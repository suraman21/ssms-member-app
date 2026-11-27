# Diagnosis — Activation queue `temporarily unavailable` (MX7CVJNR)

**URL:** `https://felegekidusan.arkeonethiopia.com/admin/acm_queue.php?status=&account_type=member&q=MX7CVJNR`
**Observed:** Red banner `⚠ The queue is temporarily unavailable.` + `0 tickets / No tickets match these filters.` (status=, account_type=member, q=MX7CVJNR, filters Open(all) + Mine/Overdue unchecked)
**Date reported:** 2026-11-27
**Repository context:** `suraman21/ssms-member-app` `arena/dfd990da-ssms-member-app` @ `768c0f5` — Members Portal (this repo) does **not** serve `admin/acm_queue.php`; that page lives in the **platform** repo `suraman21/fkss-sunday-school-platform` (see `docs/03-API-INTEGRATION.md`). This diagnosis is therefore cross-repo, built from evidence in *this* repo plus the handbook's verified ACM contract.

---

## 1. What the screenshot tells us (VERIFIED from image)

* The request reached the web server and PHP rendered the page shell (header `Access Control / Account Command Center`, left nav `MANAGE / ACCOUNT` with `Activation queue` highlighted, user badge `ACO / Accounts Office`). So routing, auth (ACO role), and layout are intact.
* The **query hit the queue controller** — filters are populated (`Open(all)`, `Member`, `MX7CVJNR` in the search box). That proves `$_GET['q']` was parsed.
* Two distinct UI signals are shown at once:
  1. **Error banner** `The queue is temporarily unavailable.` (full-width dark-red bar). — This is not the "empty state"; it's an error state rendered *above* the list.
  2. **Empty list state** `0 tickets / No tickets match these filters.` with a grey check-circle.

Pattern match: this is the classic **caught-exception-and-continue-with-empty-result** pattern seen throughout this codebase. Compare `docs/prototypes/.../backend/api.php`:

```php
set_exception_handler(function (Throwable $e) {
    // ...
    http_response_code(500);
    echo json_encode(['success'=>false,'code'=>'SERVER_ERROR',...]);
});
```

and `index.php` in *this* repo:

```php
catch (HttpException $ex) { render error page }
catch (Throwable $ex) { render 500 generic, never echo stack }
```

A real queue failure is **hidden behind a user-safe string** while the technical cause is sent only to `error_log`. The list is then rendered with zero rows because the fetch returned nothing or threw.

**Confidence:** HIGH that `acm_queue.php` wraps its data fetch in `try/catch (Throwable)` and shows exactly this banner on catch.

## 2. Where that page lives (VERIFIED)

`grep -R acm_queue` in *this* repo returns **0 hits** — expected. The handbook (`docs/01-SYSTEM-CONTEXT.md`) states the ACM (Account/Command Mediated activation) subsystem lives on the platform under `acm/` and is reachable only via `/api/v1/acm/*`. The admin page `admin/acm_queue.php` is therefore **not in `ssms-member-app`**; fixing it requires a change in `fkss-sunday-school-platform`.

In *this* repo the only ACM surface is `src/Repository/AcmRepository.php`:

* `verifyCode(selector,secret,purpose='activate')` → `POST /acm/activate/verify`
* `setCredentials(grant,password,idempotencyKey)` → `POST /acm/activate/credentials`
* `beginDeviceRegistration(...)` → `POST /acm/devices/register/begin`
* `completeDeviceRegistration(...)` → `POST /acm/devices/register/complete`

called from `index.php` activation wizard and driven by `assets/js/session.js` (IndexedDB P-256 key, `rawToDer` → DER). All four calls go through `src/Repository/ApiClient.php` which is the **only** place that contains `/api/v1` strings.

## 3. Root-cause hypotheses (ranked)

### H1 — Platform DB or cache unavailable (most likely) — HIGH CONFIDENCE

`acm_queue.php` most likely does:

```php
try {
    $tickets = $acmService->listQueue(['status'=>$_GET['status'],'account_type'=>$_GET['account_type'],'q'=>$_GET['q']]);
} catch (Throwable $e) {
    error_log('ACM queue failed: '.$e->getMessage());
    $banner = 'The queue is temporarily unavailable.';
    $tickets = [];
}
render(queue_template, ['tickets'=>$tickets,'banner'=>$banner]);
```

If the DB connection to `acm_person_accounts / acm_tickets / acm_activation_codes` fails, or a cache/RDS is down, every queue request — including `q=MX7CVJNR` — would show banner + 0. This matches "Open(all) + Member + MX7CVJNR → 0" even though the code `MX7CVJNR` *should* narrow to one ticket.

**Check:** `docs/03-API-INTEGRATION.md §2` defines platform error codes `AUTH_SERVICE_UNAVAILABLE`, `CACHE_UNAVAILABLE`, `503 retry later` for exactly this situation. `src/Repository/ApiClient.php:503` maps `curl_exec false|status 0` → `503 AUTH_SERVICE_UNAVAILABLE`. A platform `503` would surface in `acm_queue.php` logs as `SQLSTATE` or `CACHE_UNAVAILABLE`.

### H2 — Search index / query builder rejects the selector — MEDIUM

`q=MX7CVJNR` is an 8-char selector (alphanumeric). If `acm_queue.php` expects a different shape (e.g. `FK-` prefix, or full `selector:secret`) it might build a `WHERE selector = ?` that returns zero and the banner is unrelated (stale cache of a prior failure). But the banner would then be **stale** and not tied to this `q`. The screenshot shows banner + 0 together, so H1 remains more likely.

### H3 — Permissions / tenant scoping hides the ticket — LOW

Left nav shows `ACO / Accounts Office` (a scoped staff role). If `acm_queue.php` filters by office/grade via `staff_scopes` (prototype pattern `lib/security.php:fk_staff_can_manage` checks grade/class), a ticket for a member outside ACO's scope would correctly be 0 but **no banner** would be shown. Banner contradicts a clean permission filter.

### H4 — This repo broke the queue — NOT SUPPORTED

`ssms-member-app` never writes to platform tables and never exposes `/admin/*`. No commit on `768c0f5` touches ACM. The queue outage is independent of this branch.

## 4. What `MX7CVJNR` likely is

Based on `docs/03-API-INTEGRATION.md §6` and `src/Repository/AcmRepository.php:verifyCode`:

* `selector` is the public half of an activation code issued by the Accounts Office (human-readable, e.g. `MX7CVJNR`), `secret` is the second half.
* `POST /acm/activate/verify {selector: "MX7CVJNR", secret: "...", purpose: "activate"}` → `{grant}` on success.
* Searching `q=MX7CVJNR` in the queue is **looking up the ticket/code row** before or after redemption — standard for "Manual review. Verify identity, then approve or reject."

If no ticket exists for `MX7CVJNR` the correct state is `0 tickets` **without banner**. The presence of the banner means the lookup never reached the point of correctly answering empty vs found.

## 5. What to check next (owner = platform, not this repo)

Run these on the platform host where `felegekidusan.arkeonethiopia.com` is deployed:

```bash
# 1. Is the ACM endpoint itself healthy? (bypasses the admin UI)
curl -i https://felegekidusan.arkeonethiopia.com/api/v1/ping
# Expect: 200 {status:"success"}

# 2. Try the ACM verify with a dummy (should be 400/401, not 503)
curl -i -X POST https://felegekidusan.arkeonethiopia.com/api/v1/acm/activate/verify \
  -H 'Content-Type: application/json' \
  -d '{"selector":"MX7CVJNR","secret":"test","purpose":"activate"}'
# Expect: 400/403 with envelope {status:"error",message:...,code:...}
# If you get 503 {code:"AUTH_SERVICE_UNAVAILABLE"} or raw 500, H1 confirmed.

# 3. Check platform error logs (the real cause is there, not in the UI)
tail -n 200 /var/log/php/error.log | grep -i -E 'acm|queue|SQLSTATE|CACHE'
tail -n 200 {platform_root}/storage/logs/*.log 2>/dev/null
# Look for: PDOException, SQLSTATE[42S22|42S02] (missing table), Redis CACHE_UNAVAILABLE, or ApiClient curl_error.

# 4. Verify DB tables for ACM exist on the production DB
mysql -e "SHOW TABLES LIKE 'acm%';"
# Expected at least: acm_person_accounts, acm_activation_codes, acm_devices, acm_tickets (names per handbook §3)
# A missing table explains 500 → banner.

# 5. If the queue uses a cache/queue worker, check it
systemctl status redis 2>/dev/null || systemctl status cache 2>/dev/null
```

From the **member portal** side (this repo), verify the wizard still surfaces the real error:

```bash
# Point a local checkout at the live API and attempt the same selector
FKSS_API_BASE_URL=https://felegekidusan.arkeonethiopia.com/api/v1 php -S localhost:8002 -t .
# Then in browser: http://localhost:8002/activation → Step 1 MX7CVJNR + any secret
# Expect either a field error (wrong secret) or, if H1, "Platform API is currently unreachable. (503)" or "Unexpected response"
# That error is thrown in src/Repository/ApiClient.php and rendered in templates/pages/activation.php as fk-alert-error.
```

## 6. Immediate remediation (platform — not this repo)

* **Do not hide the DB/curl error behind a generic banner alone.** Keep the banner for users but add `X-Request-Id` (already present per `docs/03 §1`) plus the error code (`CACHE_UNAVAILABLE`, `DB_CONNECTION`, `SQLSTATE[42S02]`) to the banner's `data-error-code` attribute or to the server log line so an ACO can report it. Current prototype handler `set_exception_handler` already distinguishes `SETUP_INCOMPLETE` vs generic — platform should do same.
* **Health probe `GET /api/v1/ping` is liveness only, not readiness** (`docs/03 §1`). Add a readiness probe that checks DB + cache and have `acm_queue.php` call it before rendering; show a distinct "System is in maintenance" vs "No tickets match".
* **No schema change in this repo needed.** The member portal's `AcmRepository` already handles `503/502` via `HttpException`; `index.php` activation branch catches it and sets `viewData['error'] = ex.getMessage()` (VERIFIED `index.php:66,98,103`).

## 7. What this means for MX7CVJNR specifically

* With the banner present, the search result `0 tickets` **is not trustworthy**. The queue did not return "zero matching" — it returned "I couldn't query at all and fell back to empty."
* Retry after confirming platform health. If after H1 is fixed the search still returns 0, then `MX7CVJNR` either (a) was already consumed (grant issued, ticket closed), (b) belongs to a different `account_type` or `status` (try `status=all&account_type=all`), or (c) was never issued (typo). The queue's SQL is likely `WHERE selector LIKE '%MX7CVJNR%' OR member_code LIKE '%MX7CVJNR%'` filtered by `account_type=member` — try clearing `Member` filter too.
* The member portal can still redeem the code directly (if H1 is transient and `/acm/activate/verify` recovers) without visiting the queue — `MX7CVJNR` + secret in `/activation` Step 1 is the canonical path.

## 8. Evidence map for this diagnosis

| Claim | Source |
|-------|--------|
| `admin/acm_queue.php` not in this repo | `grep -R acm_queue` → 0 in ssms-member-app, `docs/01-SYSTEM-CONTEXT.md` says ACM lives in platform `acm/` |
| Queue banner is a caught Throwable | Pattern in `docs/prototypes/backend/api.php:set_exception_handler` + `index.php: catch(Throwable)→500 generic` — same team convention |
| `MX7CVJNR` is a selector | `docs/03 §6` + `AcmRepository::verifyCode(selector,secret,purpose)` |
| Platform 503/CACHE codes exist | `docs/03 §2` + `src/Repository/ApiClient.php: throw 503 AUTH_SERVICE_UNAVAILABLE` |
| Member portal activation does not write the queue | `src/Repository/AcmRepository.php` HTTP-only vs no `require` of platform files per `docs/01 §4` |

---

**Recommendation for this branch:** No code change needed here to fix the platform banner. If you want the members portal to degrade more gracefully when the platform queue/API is down, the smallest safe change (per the archaeology report §V) would be to ensure `index.php`'s activation `verify`/`credentials` catches specifically log the `code` (`AUTH_SERVICE_UNAVAILABLE`) with a user-facing "The Accounts Office system is briefly down — please try again in a few minutes and contact the office if MX7CVJNR still fails." — today it already does via `ex.getMessage()` but could be more explicit. I can add that one-line refinement on this branch if you want, or leave this repo untouched and fix `acm_queue.php` on the platform side.
