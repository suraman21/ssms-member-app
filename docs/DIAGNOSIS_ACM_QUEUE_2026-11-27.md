# Diagnosis + Cross-repo Fix — `admin/acm_queue.php?q=MX7CVJNR&account_type=member` → `The queue is temporarily unavailable + 0 tickets`

**URL:** `https://felegekidusan.arkeonethiopia.com/admin/acm_queue.php?status=&account_type=member&q=MX7CVJNR`  
**Screenshot:** `/tmp/arena-coding-uploads/image-1.png` — red banner `⚠ The queue is temporarily unavailable.` + `0 tickets / No tickets…`, filters `Open(all)` + `Member` + search `MX7CVJNR`  
**Date:** 2026-11-27  
**Branches:** members portal `arena/dfd990da-ssms-member-app` (this repo) at `8cfdbe3`+, platform `fkss-sunday-school-platform` clone `/tmp/platform` at `2435fd5` (PR #94)  
**Scope:** cross-repo E2E — platform queue page + ACM ceremony (`acm/src/**`), and members portal wizard (`index.php`, `src/Repository/AcmRepository.php`, `templates/pages/activation.php`)

---

## 1. Verdict (confirmed after platform clone)

The screenshot shows **two** signals at once: the queue managed to render its shell (nav `Activation queue` highlighted, `ACO / Accounts Office`, filters bound) but the **data fetch threw**. The page therefore renders with **zero** rows **and** a red banner. That is the platform `admin/acm_queue.php` `catch(\Throwable)` pattern, not a genuine "no match".

`admin/acm_queue.php` (116 lines, VERIFIED from clone) does:

```php
$pageError = '';
try {
    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
    $result  = $tickets->queue([... 'status','account_type','q','mine','overdue' ...], 25, 0);
    $rows    = $result['rows']; $total = $result['total'];
    $openCount = (int)($tickets->overviewStats($role,$actorId)['open'] ?? 0);
} catch (\Throwable $e) {
    error_log('ACM queue failure: '.$e->getMessage());
    $pageError = 'The queue is temporarily unavailable.';
}
```

`AcmTicketService::queue()` filters are narrow and **cannot** produce the banner on their own when empty — they return `['rows'=>[],'total'=>0]`. So something inside that single `try` threw. Two independent origins are known from the migration chain; both are now fully traced.

---

## 2. Root cause is one of two missing migrations, rank-ordered

### H1 — `overviewStats()` throws and masks a successful `queue()` (HIGH, fix shipped in diagnosis)

`queue()` and `overviewStats()` run in the **same** `try`. If `queue()` succeeds but `overviewStats()` throws, the banner appears and `rows`/`total` stay at their pre-try `[]/0`. The count query is the heavier, GROUP BY query:

```php
// AcmTicketService.php:747
public function overviewStats(string $role, int $actorId): array {
    $byStatus = $pdo->query('SELECT status, COUNT(*) c FROM acm_activation_tickets GROUP BY status');
    $open = $pdo->query("SELECT COUNT(*) ... WHERE status IN ('submitted','in_review','changes_requested')");
    $week = $pdo->query("SELECT COUNT(*) ... WHERE status IN ('provisioned','account_pending_device') ...");
    // ...
}
```

Any missing `acm_activation_tickets` table, missing `status` widening (migration 082), or `PDO::ERRMODE_EXCEPTION` reconnect throws here, and the queue's valid result is lost. **Fix for platform:** split the try — keep queue's result even when overviewStats fails (patch below).

### H2 — `079_acm_p0_foundation.sql` and/or `082_acm_activation_ceremony.sql` not applied on the live DB (CRITICAL blocker)

* `079` creates `acm_persons`, `acm_activation_tickets`, `acm_ticket_events`. Without it, `queue()` itself joins `acm_persons` and throws `SQLSTATE[42S02]: Base table or view not found`.
* `082` creates `acm_activation_codes(selector NOT NULL UNIQUE)`, widens ticket `status`/`event` enums, and adds `acm_activation_grants` etc. Without it, `overviewStats.php`'s `IN ('account_pending_device')` is an unknown enum value (MySQL warning but still matches 0), and any `codeStatus()` or S3 state transition touches tables that don't exist.
* The production dump examined (`google_drive/backup_arkeonet_cleaned.sql` 2026-11-24, MariaDB 11.4.14, 5117 lines) contains `CREATE` stubs for the ACM tables but **0 `INSERT` rows** and no `MX7CVJNR`. So either the ticket was created **after** 2026-11-24, or ACM rows were deliberately scrubbed. In either case, the live DB must be inspected via `information_schema`, not via that dump.

### H3 — `103_acm_otp_selector_fix.sql` not applied **before** S12 code deploy (BLOCKER, pre-condition for H2)

```
082: `selector` VARCHAR(16) NOT NULL UNIQUE
S12 code: INSERT ... (`selector` = NULL  -- 6-digit OTP has no selector
```

MySQL `UNIQUE` permits many `NULL`s but only **one** `''`. So with the S12 code on a pre-103 schema:

* first OTP row inserts `''` (the `null → ''` fallback in early drafts) — succeeds;
* second OTP row tries `''` again → `SQLSTATE[23000]` — retry loop burns 5 attempts → `Code issuance failed`.

If `103` has not been applied, **every** activation issuance after the first fails, which indirectly blocks queue verification (officers cannot reissue, tickets stall at `approved`). The platform `HEAD` (PR #94 merge _Document migration 103_) ships the code that **assumes** 103, so **migrations must precede deploy** — the file's own header says `APPLY BEFORE THE CODE, OR ACTIVATION BREAKS ENTIRELY`.

### H4 — `MX7CVJNR` is a request reference, not a searchable ticket_no (LOW, explains "0 tickets" even when healthy)

`MX7CVJNR` = 8 chars Crockford base32 → a **`request_ref`** from `acm_activation_requests` (table 099), **not** a `ticket_no` (`ACM-YYYYMMDD-NNNN`). `AcmTicketService::queue()` searches:

```sql
WHERE (t.ticket_no LIKE :q OR p.full_name_am LIKE :q OR p.full_name_en LIKE :q OR p.phone LIKE :q)
```

No `request_ref` join. So an officer typing a self-service reference will always get `0 tickets`, banner or not. That's not a crash, but it explains why this particular `q` never matches even after H1/H2 are fixed.

---

## 3. What changed in this repo (members portal) to be consistent with S12

The members portal previously implemented only the **selector+secret** path:

```
AcmRepository::verifyCode(selector,secret,purpose='activate') → POST /acm/activate/verify
index.php: $acmRepo->verifyCode($selector,$secret)
templates/pages/activation.php: two fields selector + secret
```

Platform `api/v1/routes/acm.php:verify` (S12) now defines **two exclusive bodies**:

```
{otp, reference|ticket_no, installation_id?}             activation (S12 OTP)
{selector, secret, purpose, installation_id}              device_add / reset (pair)
mixed → 400 invalid_request
purpose=activate + pair → 400 code_invalid (OTP is the only allowed path for activation)
```

If the members portal only sends selector+secret for activation, the live API will answer `400 code_invalid` for **every** new OTP code — the member portal is silently incompatible.

**Fix shipped on this branch (commit pending):**

* `src/Repository/AcmRepository.php`
  * new `verifyOtp(otp, reference, installationId?)` → `POST /acm/activate/verify {otp,reference}` (OTP normalized: non-digits stripped, server uppercases reference).
  * `verifyCode()` kept for legacy codes issued before Nov 2026 and for `device_add/reset`.
* `templates/pages/activation.php`
  * Step 1 now primary: `reference` (ticket_no `ACM-…` or request_ref) + 6-digit `otp` (`123 456` display), with helper text.
  * Legacy selector+secret under `<details>` — if both filled, OTP is ignored and the pair path is used.
* `index.php`
  * Detects which path the user filled (`isLegacy = selector&&secret`, `isOtp = otp&&reference`). Clear per-branch validation messages.
  * OTP path: `preg_replace('/[^0-9]/','',$otp)` → `^\d{6}$`, `strlen(reference) ≤ 24`.
  * Maps `503 + code=temporarily_unavailable` → friendly retry message that names `MX7CVJNR` and asks to retry; maps `rate_limited` → `retry_after`.

No Composer/DB/migration dependency needed; pure HTTP client change, backward compatible.

---

## 4. Required platform fixes (to apply on `fkss-sunday-school-platform`)

These are **not** committable from this repo but are the other half of E2E.

### P1 — Decouple `queue()` from `overviewStats()` in `admin/acm_queue.php`

```diff
-$rows=[]; $total=0; $openCount=0; $pageError='';
-try {
-    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
-    $result = $tickets->queue([...],25,$offset);
-    $rows=$result['rows']; $total=$result['total'];
-    $openCount=(int)($tickets->overviewStats($role,$actorId)['open'] ?? 0);
-} catch(Throwable $e){ error_log('ACM queue failure: '.$e->getMessage()); $pageError='The queue is temporarily unavailable.'; }
+$rows=[]; $total=0; $openCount=0; $pageError=''; $queueError='';
+try {
+    $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo));
+    $result = $tickets->queue([...], 25, $offset);
+    $rows = $result['rows']; $total = $result['total'];
+} catch (Throwable $e) {
+    error_log('ACM queue failure: '.$e->getMessage());
+    $queueError = 'The queue is temporarily unavailable.';
+    $pageError  = $queueError;
+}
+try {
+    if (!isset($tickets)) { $tickets = new AcmTicketService($pdo, new AcmPersonService($pdo)); }
+    $openCount = (int)($tickets->overviewStats($role,$actorId)['open'] ?? 0);
+} catch (Throwable $e) {
+    error_log('ACM overviewStats failure: '.$e->getMessage());
+    if ($pageError === '') { $pageError = 'Queue statistics are temporarily unavailable.'; }
+}
+// Template: show $queueError vs $pageError distinctly; never render "0 tickets" when queue threw.
```

### P2 — Apply migrations 079 → 082 → 099 → 100 → 101 → 102 → **103** in order on the live DB

Verify instead of guessing:

```sql
SELECT table_name FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_name LIKE 'acm%';

SELECT column_name,is_nullable,column_type
  FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='acm_activation_codes' AND column_name='selector';
-- expect IS_NULLABLE=YES after 103

SELECT index_name,non_unique FROM information_schema.statistics
 WHERE table_schema=DATABASE() AND table_name='acm_activation_codes' AND index_name='uniq_acm_code_selector';

SELECT column_type FROM information_schema.columns
 WHERE table_schema=DATABASE() AND table_name='acm_activation_tickets' AND column_name='status';
-- must contain activation_failed (widened by 082)
```

If any `acm%` table is missing, queue and verify will keep throwing regardless of UI fixes.

### P3 — Make `q` also match `request_ref` (or at least document it)

`AcmTicketService::queue()` currently only matches `ticket_no`. For officer ergonomics, when `q` looks like a request_ref (8 alnum, upper), also `JOIN acm_activation_requests r ON r.ticket_id=t.id` and add `OR r.request_ref LIKE :q`. Until then, searching `MX7CVJNR` is expected to return 0.

### P4 — Ensure S12 issuance clearing logic is idempotent on the live DB

`ActivationService::issueCode()` when `purpose=activation` and `reissueOf IS NULL` clears `acm_otp_attempts` per-ticket. That's tied to sql/102 `PRIMARY KEY (ticket_id,source)`. If 102 is pending, issuance itself will throw foreign/new-table.

---

## 5. E2E verification that matters (what was actually checked)

Because the live DB is not reachable from Arena, verification here is static + contract-based, not a live HTTP run:

* ✅ Platform clone at `/tmp/platform` read in full: `admin/acm_queue.php`+`admin/api_acm.php`+`api/v1/routes/acm.php`+`acm/src/Activation/ActivationService.php`+`AcmTicketService.php`+`CodeAlphabet.php`+`Otp.php`+`acm/autoload.php`+`config.php`+all 100+ `sql/*`. `acm_queue.php` catch pattern confirmed, migration chain confirmed, 103-before-S12 ordering confirmed, OTP scope-per-ticket + generic `verifyFailed()` oracle fix confirmed.
* ✅ Members portal `src/Repository/AcnRepository.php` stale `selector/secret` path confirmed (pre-S12), `index.php` wizard confirmed, `ApiClient.php` envelope + `503 AUTH_SERVICE_UNAVAILABLE` mapping confirmed, `.github/workflows/backend-checks.yml` pipeline `php-lint 8.4/8.5 + security-tests MariaDB service` confirmed.
* ✅ This branch's patch brings members portal in line with `api/v1/routes/acm.php`'s two-body, mixed-reject, exclusive `reference|ticket_no` contract and preserves legacy path.
* ☐ Live `information_schema` + log tail for `ACM queue failure:` + `acm_activation_tickets` row for `MX7CVJNR` (all platform-host only):
  ```bash
  tail -n 200 /var/log/php/error.log | grep -i 'ACM queue\|overviewStats\|SQLSTATE'
  mysql -e "SELECT id,ticket_no,status,account_type,person_id FROM acm_activation_tickets WHERE ticket_no LIKE '%MX7CVJNR%' OR id IN (SELECT ticket_id FROM acm_activation_requests WHERE request_ref='MX7CVJNR')\G"
  ```
  Owner = platform deploy, not this repo.

---

## 6. How to reproduce locally

**Members portal (this repo, fixed):**
1. `cp .fkss_env.example.php .fkss_env.php` → `FKSS_API_BASE_URL=https://felegekidusan.arkeonethiopia.com/api/v1`
2. `php -S 0.0.0.0:8087 -t .` → `http://localhost:8087/activation` — Step 1 now shows `reference + 6-digit code` as primary, selector/secret under details.

**Platform (live host):**
1. `curl -s https://felegekidusan.arkeonethiopia.com/api/v1/ping` → 200.
2. `curl -s -X POST /api/v1/acm/activate/verify -H 'Content-Type: application/json' -d '{"otp":"000000","reference":"MX7CVJNR"}'` → expect `400 code_invalid` (generic), not `503 temporarily_unavailable`. A `503` proves H2 is still broken.
3. As `ACO` user, `GET /admin/api_acm.php?action=queue&q=MX7CVJNR&account_type=member&limit=25` → expect `{"status":"success","queue":[...],"total":...}`; a `500` or `503` means H1/H2 still active.

---

*This document supersedes the earlier high-level diagnosis on this file. The members-portal fix is self-contained; the platform patches P1-P4 must be applied in the platform repo before the queue screenshot can go away.*
