# System Archaeology Report — ssms-member-app

**Repository:** `suraman21/ssms-member-app`
**Branch:** `arena/dfd990da-ssms-member-app` — branched from `main@768c0f5713387611d788246e787296e8f172fb11` (2026-11-26, *Merge PR #6 — Wire /profile with ProfileRepository*)
**Date (UTC):** 2026-11-27
**Analyst role:** Senior Architect / Codebase Archaeologist — read-only inventory, no modifications to runtime behavior.
**Evidence standard:** Every claim cites the file and symbol where it was verified. `VERIFIED` = directly observed, `INFERENCE` = strongly suggested but not proven, `UNKNOWN` = insufficient evidence, `NOT VERIFIED` = claimed elsewhere but not found here.

---

## Table of Contents

1. [A — Executive System Summary](#a--executive-system-summary)
2. [B — Technology Stack](#b--technology-stack-verified)
3. [C — Repository Architecture](#c--repository-architecture)
4. [D — Application Architecture](#d--application-architecture)
5. [E — Module-by-Module](#e--module-by-module)
6. [F — File-Level Map](#f--file-level-map)
7. [G — Function / Class / Component Map](#g--functionclasscomponent-map)
8. [H — Database Architecture](#h--database-architecture)
9. [I — API Map](#i--api-map)
10. [J — Authentication & Authorization](#j--authentication--authorization)
11. [K — State Management](#k--state-management)
12. [L — Business Rules](#l--business-rules)
13. [M — Workflow Maps](#m--workflow-maps-end-to-end)
14. [N — Status / State Transition Maps](#n--statusstate-transition-maps)
15. [O — External Integrations](#o--external-integrations)
16. [P — Configuration](#p--configuration)
17. [Q — Error Handling](#q--error-handling)
18. [R — Dependency Graph](#r--dependency-graph)
19. [S — Potential Problems](#s--potential-problems-no-fixes-applied)
20. [T — Unknowns](#t--unknowns)
21. [U — Documentation Mismatches](#u--documentation-mismatches)
22. [V — Change Impact Map](#v--change-impact-map)
23. [Completeness Checklist](#completeness-checklist)
24. [Confidence Classification Summary](#confidence-classification-summary)

---

## A — Executive System Summary

**VERIFIED:** This repository contains **two distinct systems** in one checkout:

1. **The live Members Portal** (the product being built) — `index.php` + `src/` + `templates/` + `assets/` + `storage/` + `.htaccess`. A server-rendered PHP web app for **members (students) and parents** of Felege Kidusan Sunday School. It runs on its **own subdomain**, shares **no files, no database, and no PHP session** with the staff platform (`suraman21/fkss-sunday-school-platform`). Every fact about a member arrives as **JSON over HTTPS from `POST/GET /api/v1`**. Verified by `index.php` lines 1–253 (all API access via `Repository/*`), `src/Support/Config.php:apiBaseUrl()`, and `src/Repository/ApiClient.php` (only layer that calls the platform).

2. **The archived prototype** (reference only, not loaded) — `docs/prototypes/member-app-2026-11-26/` — a 57-file frozen copy of an earlier standalone app that shipped **its own backend, its own MySQL schema, its own session auth, and ~29 `/api/*` routes** against `felege_kidusan_test`. It is explicitly not adopted; `.htaccess` blocks `docs/` from the web and `src/Support/bootstrap.php` never requires anything under `docs/prototypes`. Verified by `docs/ARCHIVE.md`, `docs/HANDBOOK.md § Three corrections`, and `docs/06-FIRST-TASKS.md §1`.

**What the live app does today (VERIFIED):**

- `GET /`, `GET /sign-in` → server-rendered sign-in form (`templates/pages/sign-in.php`). `POST /sign-in` → `AuthRepository::login()` → stores `token`/`refresh_token`/`user` in PHP `$_SESSION` via `SessionManager::setAuth()` → `303 /profile`.
- `GET /activation` → 3-step wizard (`verify` → `credentials` → `device`). Steps 1–2 are server POSTs via `AcmRepository`; step 3 is browser WebCrypto (`assets/js/session.js`) that `POST /activation` with `step=device_begin|device_complete` and JSON responses.
- `GET /profile` (protected) → `ProfileRepository::getMe()` and `PATCH /users/me` + `POST /users/change-password` via `ProfileRepository`.
- `POST /logout` → `AuthRepository::logout()` (best-effort) + `SessionManager::clear()` + `303 /sign-in`.
- All other paths → `404 Error` via `Router::resolve()` throwing `HttpException(404)`.

**What it does NOT do (VERIFIED):** Lessons, quizzes, progress, tasks, past-papers, verses, attendance, notifications, permissions, children-list — none are wired in the live app. Their prototype pages (`quiz-me.html`, `lessons.html`, `tasks.html` etc.) and backend handlers (`api/learning.php`, `api/quiz.php`, `api/extras.php`) exist only under `docs/prototypes/` and are not reachable from `index.php`.

---

## B — Technology Stack (VERIFIED)

| Layer | Technology | Evidence |
|---|---|---|
| **Language** | PHP 8.4 / 8.5 (strict_types, `declare(strict_types=1)`) | `index.php:2`, all `src/**/*.php`, `.github/workflows/lint.yml: php-version [8.4, 8.5]` |
| **Framework** | **None.** Hand-rolled router/template/repository. No Composer, no autoloader. | `src/Support/bootstrap.php:7-24` (hand-listed `require_once` in dependency order), `README.md: The two facts` |
| **Package manager** | None | No `composer.json`, no `package.json`, no `vendor/` |
| **Web server** | Apache with `mod_rewrite` (inferred from `.htaccess`) | `.htaccess: RewriteEngine On` |
| **Session** | Native PHP `$_SESSION` (`FKSS_MEMBER_SID`, `Lax`, `HttpOnly`, `Strict-mode`) | `src/Auth/SessionManager.php:start()` |
| **HTTP client** | `ext-curl` | `src/Repository/ApiClient.php: curl_init`, `CURLOPT_*` |
| **View** | PHP templates (`ob_start`/`extract`/`require`) + `htmlspecialchars` escaper | `src/View/Template.php` |
| **Styling** | Vanilla CSS: `fk-theme.css` (1058 lines, design tokens) + `style.css` (2444 lines) | `assets/css/*`, `templates/layouts/base.php: links` |
| **JS** | Vanilla, no bundler, no framework | `assets/js/fk-ui.js` (505 lines), `assets/js/session.js` (168 lines), `templates/layouts/base.php: defer` |
| **Crypto (browser)** | WebCrypto ECDSA P-256 (`crypto.subtle`, IndexedDB `fkss_member_device`) | `assets/js/session.js: generateKey {ECDSA,P-256}`, `rawToDer()` |
| **DB (live app)** | **None** — HTTP only. Prototype used MariaDB `felege_kidusan_test` via PDO `mysql:` | `src/` has no PDO, no `database.php`; prototype: `docs/prototypes/.../backend/config/database.php` |
| **Auth** | JWT Bearer `access_token` (15 min) + rotating `refresh_token` (90 d) + optional DPoP `ES256` proof | `src/Auth/SessionManager.php:setAuth`, `src/Repository/AuthRepository.php`, `docs/03-API-INTEGRATION.md §3` |
| **Logging** | JSON-lines file `storage/logs/auth.log` (also `storage/logs/.htaccess` denies web) | `src/Support/Logger.php`, `index.php:$logger = new Logger(.../auth.log)` |
| **CI** | GitHub Actions `php -l` lint on 8.4+8.5 | `.github/workflows/lint.yml` |
| **Deployment** | Document root = repository root, SFTP upload, shared hosting (inherited ADR-0001) | `.htaccess` blocks `src/templates/docs/storage`, `docs/01-SYSTEM-CONTEXT.md §5 diagram` |

---

## C — Repository Architecture

### C.1 Root inventory (recursive, VERIFIED)

```
/ (repo root)
├── index.php                 # ONLY HTTP entry point — 254 lines
├── .htaccess                 # Apache rewrite + blocklist
├── .fkss_env.example.php     # config template (outside git)
├── .gitignore                # .fkss_env.php, *.log, *.lock, storage/locks/
├── .github/workflows/lint.yml
├── README.md
├── src/
│   ├── Support/  Config, HttpException, Logger, bootstrap
│   ├── Auth/     SessionManager, AuthGuard
│   ├── Http/     Router
│   ├── Repository/ ApiClient, AuthRepository, AcmRepository, ProfileRepository
│   ├── Service/  (empty — .gitkeep)
│   └── View/     Template
├── templates/
│   ├── layouts/base.php      # shell (header/nav/main/footer)
│   └── pages/{sign-in,activation,profile,error}.php
├── assets/{css/{fk-theme,style}.css, js/{fk-ui,session}.js, favicon}
├── storage/logs/{.htaccess}  # .htaccess denies web; auth.log gitignored
└── docs/
    ├── 00-START-HERE.md … 06-FIRST-TASKS.md, HANDBOOK.md
    └── prototypes/member-app-2026-11-26/
        ├── ARCHIVE.md
        ├── backend/{api.php,router.php,config/database.php,lib/security.php,
        │           api/{auth/login,register,auth_staff,learning,extras,quiz,question,question_import,lessons/*},
        │           tools/{check_setup,create_staff,import_lesson_text},
        │           data/{verses,lessons,lesson_text_grade10}.json, server.js}
        ├── database/{migrations/001…003 + rollbacks, demo/demo_past_paper.sql}
        ├── docs/{HANDOVER..., README_START_HERE, STAGE1_SETUP, TEST_CHECKLIST, lesson_text...md}
        ├── frontend/{index,login,student-dashboard,lesson,lessons,quiz-me,exam-prep,progress,tasks,change-password,staff.html
        │           + css/fk-theme.css, style.css + js/fk-ui.js, session.js}
        ├── tests/frontend-mock/{server.js, interact.js, run.js}
        └── uploads/{past-papers/2018-grade10-final-exam-demo.pdf}
```

### C.2 Directory responsibilities (VERIFIED)

| Path | Responsibility | Loaded at runtime? |
|---|---|---|
| `src/Support/bootstrap.php` | Hand-maintained autoload map (dependency order) | yes — `index.php:3 require_once` |
| `src/Support/*` | `Config`, `HttpException`, `Logger` — cross-cutting | yes |
| `src/Auth/*` | `SessionManager` (cookie + token store), `AuthGuard` (role gate) | yes |
| `src/Http/Router.php` | Finite route table | yes |
| `src/Repository/*` | **Only place that touches `/api/v1`** — envelope, headers, `DPOP` | yes |
| `src/Service/` | Business rules — **intentionally empty** (no rules yet) | no |
| `src/View/Template.php` | `render(page, data)` → layout | yes |
| `templates/layouts/base.php` | Shell, header, footer, script includes | yes |
| `templates/pages/*` | Four pages today (`sign-in`, `activation`, `profile`, `error`) | yes |
| `assets/*` | CSS + JS, served as static files (`-f` bypass in `.htaccess`) | browser |
| `storage/logs/` | `auth.log` (JSON-lines), `locks/refresh_*.lock` (via `AuthRepository::refresh`) | runtime writes |
| `docs/` | Handbook + archive — **never loaded** (blocked by `.htaccess`) | no |
| `docs/prototypes/...` | Reference prototype — **never loaded** | no |

---

## D — Application Architecture

### D.1 Live app — layered diagram (VERIFIED)

```
Browser (member/parent)
  │  HTML + forms (POST, csrf_token) + fetch (device registration JSON)
  │  cookies: FKSS_MEMBER_SID (opaque session ID only)
  ▼
Apache → .htaccess → index.php  (single dispatcher)
  │
  ├─ Router::resolve(REQUEST_URI) → {template,title,noStore,protected} finite map
  │
  ├─ Auth:
  │    SessionManager (start / setAuth / isAuthenticated / accessToken / csrfToken / clear)
  │    AuthGuard::requireRole(['member'])
  │    Logger → storage/logs/auth.log (JSON-lines, PII-sanitized)
  │    Config → ../.fkss_env.php → FKSS_API_BASE_URL (trim, strip trailing /)
  │
  ├─ Repository (ONLY HTTP callers):
  │    ApiClient::request(method, path, body, token, dpopProof)  ← curl, envelope
  │      ├─ AuthRepository: login / refresh (flock) / verify / logout
  │      ├─ AcmRepository: verifyCode / setCredentials / begin|completeDevice
  │      └─ ProfileRepository: getMe / updateMe / changePassword
  │
  ├─ View: Template::render(page, data) → pages/*.php inside layouts/base.php
  │
  └─ Platform REST API  POST/GET https://<FKSS_API_BASE_URL>/api/v1/*
       └─ MariaDB (on platform host — unreachable directly)
```

**Constraint (VERIFIED, `docs/01-SYSTEM-CONTEXT.md §5`):** `Browser → Members web app → Platform /api/v1 → MariaDB`. Arrows carry **data only** (JSON), never code, never platform DB credentials. The members subdomain is a **different origin** from the API → CORS applies.

### D.2 Prototype — parallel architecture (VERIFIED, `docs/prototypes/.../backend/router.php` + `api.php`)

```
Browser → backend/router.php
           ├─ if /api/* → backend/api.php → fk_gate() → handlers (learning/quiz/extras/auth_staff)
           │                    ↓
           │                 PDO MySQL felege_kidusan_test (students, lessons, quiz_attempts …)
           └─ if /uploads/* → auth-gated static serve
           else → frontend/*.html (static)
```

This stack is **not part of the live request path**. `index.php` never includes `docs/prototypes/.../backend/*`.

### D.3 Request lifecycles (live)

**Sign-in:**
`Browser POST /sign-in {csrf, username, password}` → `Router /sign-in` → `verifyCsrfToken()` → `AuthRepository::login()` → `ApiClient POST /auth/login` → on `success: SessionManager::setAuth(..., regenerateId=true)` → `303 /profile`; on `403 activation_pending` → `303 /activation`; on `401/429` → re-render `sign-in` with `error`.

**Activation verify:**
`POST /activation {csrf, step=verify, selector, secret}` → `AcmRepository::verifyCode()` → `POST /acm/activate/verify` → on success → `step=credentials` with `grant + idempotencyKey=random_bytes(16)`.

**Activation credentials:**
`POST /activation {csrf, step=credentials, grant, password, idempotency_key}` → `AcmRepository::setCredentials()` → `POST /acm/activate/credentials` → `step=device`.

**Device registration (browser + BFF):**
`frontend JS: FKSession.getOrCreateDeviceIdentity() → exportSpkiBase64()` → `fetch POST /activation {step=device_begin, grant, installation_id, pubkey, name}` → `AcmRepository::beginDeviceRegistration() → POST /acm/devices/register/begin` → `{challenge_id, challenge(base64url)}` → `FKSession.signChallenge(challenge)` (sign bytes with IndexedDB private key, `rawToDer` → base64) → `fetch POST /activation {step=device_complete, challenge_id, challenge, signature}` → `AcmRepository::completeDeviceRegistration() → POST /acm/devices/register/complete` → `device_uuid` → redirect `/sign-in`.

**Profile:**
`GET /profile` (protected) → `AuthGuard::requireRole(['member'])` → `ProfileRepository::getMe(token)` → render. `POST /profile {action=update_profile|change_password}` → `ProfileRepository::updateMe|changePassword` → notice/error + re-render.

**Logout:**
`POST /logout {csrf}` → `requireRole(['member'])` + `verifyCsrfToken` → `AuthRepository::logout(refresh_token, access_token)` (best-effort, `catch(Throwahle) ignored`) → `SessionManager::clear()` → `303 /sign-in`.

---

## E — Module-by-Module

### E.1 `src/Support` — Foundation

| File | Purpose | Key behavior |
|---|---|---|
| `bootstrap.php` | **ONLY autoloader.** Hand-listed `require_once` in dependency order. `__DIR__ . '/…'` so static include check resolves. | Verifies `No Composer` constraint (`docs/04-CONVENTIONS.md §2`). Adding a class → add one line here. |
| `Config.php` | Reads `/.fkss_env.php` (array return) else `getenv`. | `apiBaseUrl(): string` throws `HttpException(500)` if missing/empty; trims & `rtrim('/')`. `schoolName()` / `appTitle()` default to `Felege Kidusan` / `Members Portal`. |
| `HttpException.php` | Public-facing error type. Carries `status:int` + `publicPayload:array` (`code`, `retry_after`). `message` is user-safe. | Distinguishes “show to user” vs. internal failure. Used by `ApiClient`, `Router`, `Template`, `AuthGuard`, `Config`, `index.php` handlers. |
| `Logger.php` | JSON-lines auth audit log: `{ts, event, ip, ctx}` | `log(event, context)` → `mkdir -p`, `json_encode(UNESCAPED_SLASHES|UNICODE)`, `FILE_APPEND|LOCK_EX`. `sanitize()` redacts keys `password|token|access_token|refresh_token|dpop|secret|authorization` → `[REDACTED]`. Never logs tokens despite `index.php` passing `user_id`, `selector`, `device_uuid`. |

### E.2 `src/Auth`

| File | Purpose |
|---|---|
| `SessionManager.php` | Opaque server-side session; browser holds only `FKSS_MEMBER_SID` cookie. `start()` sets `use_strict_mode=1`, `use_only_cookies=1`, `Secure` if HTTPS, `HttpOnly`, `SameSite=Lax`, `lifetime=0`, `path=/`, `session_name('FKSS_MEMBER_SID')`. `setAuth(authData, regenerateId)` stores `{access_token, refresh_token, expires_at (time()+expires_in\|900), device_bound, user}`. `isAuthenticated()` = token+user.id present. `verifyCsrfToken()` uses `hash_equals`. `accessTokenHash()` = `base64url(sha256(token))` (43 chars). `clear()` resets `$_SESSION`, deletes cookie, `session_destroy()`. |
| `AuthGuard.php` | `requireRole(allowedRoles=['member'])`: if `!isAuthenticated()` → `log('access_denied', reason=unauthenticated)` → `HttpException(401)`. Else check `$user['role']` against allow-list → if mismatch log + `HttpException(403)`. Returns `user` array on success. Only `role === 'member'` is allowed in `index.php` today. |

### E.3 `src/Http`

| File | Purpose |
|---|---|
| `Router.php` | Finite map `ROUTES: path → {template,title,noStore,protected}`. Routes: `/` + `/sign-in` → `sign-in` (public), `/logout` → `sign-in` (protected), `/activation` → `activation` (public, `noStore=true`), `/profile` → `profile` (protected, `noStore=true`). `resolve(uri)`: `parse_url(PATH)`, trim trailing `/`, throw `HttpException(404)` if not in map. No regex, no dynamic segments, no method check. |

### E.4 `src/Repository` — **only layer that contains `/api/v1` strings** (enforced by convention, VERIFIED: `grep /api/v1` only under `src/Repository`)

| File | Purpose |
|---|---|
| `ApiClient.php` | Low-level curl wrapper. `request(method, path, body|null, accessToken, dpopProof)`: builds `baseUrl/path`, headers `Accept`, `Content-Type`, optional `Authorization: Bearer`, optional `DPOP`. `CURLOPT_TIMEOUT 15`, `CONNECTTIMEOUT 5`, `SSL_VERIFYPEER/VERIFYHOST true`. Captures `Retry-After` via `HEADERFUNCTION`. On `curl_exec false|status 0` → `HttpException(503, code=AUTH_SERVICE_UNAVAILABLE)`. Decodes JSON, requires `status` key or → `502 Unexpected response`. If `status===error` → throw `HttpException(httpStatus, message, {code?, retry_after?})`. Else return `data ?? []`. |
| `AuthRepository.php` | `login(username,password, installationId?, deviceFingerprint?) → POST /auth/login`. `refresh(sessionKey, refreshToken, dpopProof?) → POST /auth/refresh-token` with **flock single-flight** (`refresh_<sha256(sessionKey)>.lock`, `LOCK_EX`). `verify(token) → GET /auth/verify`. `logout(refreshToken, accessToken?) → POST /auth/logout`. |
| `AcmRepository.php` | `verifyCode(selector,secret,purpose='activate', installationId?) → POST /acm/activate/verify`. `setCredentials(grant,password,idempotencyKey) → POST /acm/activate/credentials`. `beginDeviceRegistration(grant,installationId,pubkeySpkiBase64,name) → POST /acm/devices/register/begin`. `completeDeviceRegistration(challengeId,challenge,signatureDerBase64) → POST /acm/devices/register/complete`. |
| `ProfileRepository.php` | `getMe(token,dpop?) → GET /users/me`. `updateMe(token,fields,dpop?) → PATCH /users/me` (expects `profile_version`, `username`, `full_name`, `email`). `changePassword(token,current,new,confirm,dpop?) → POST /users/change-password`. |

### E.5 `src/View`

| File | Purpose |
|---|---|
| `Template.php` | `render(page,data,layout='base')`: validates `page`+`layout` against `^[a-z0-9_-]+$`, checks files exist else `404`, escapes via `htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE,UTF-8)`, renders page then layout via `extract(..., EXTR_SKIP)` + `require` inside `ob_start`. Injects `e` (escaper) and `content`. |

### E.6 `src/Service` — **empty** (VERIFIED: only `.gitkeep`). Handbook reserves it for business rules that never need to touch `$_GET`/`$_POST` or `/api/v1` directly. No Service is instantiated in `index.php`.

### E.7 `assets` / `templates`

- **`templates/layouts/base.php`**: Shell `<!DOCTYPE html>`, `<title>` escaped, nav active via `$activeRoute === path`, links `fk-theme.css + style.css`, header `fk-brand`, nav `Sign In / Activate / Profile + Sign Out (POST /logout csrf)`, footer `date('Y')`, scripts `fk-ui.js + session.js defer`.
- **`templates/pages/sign-in.php`**: `fk-card` form `POST /sign-in` with `csrf_token`, `username` (autocomplete username), `password`, `fk-alert-error / fk-notice`, link to `/activation`.
- **`templates/pages/activation.php`**: Wizard by `$step` (`verify|credentials|device`). `verify`: selector+secret. `credentials`: hidden grant+idempotency_key, password. `device`: `device-status`, `register-device-btn`, inline `<script>` that drives IndexedDB key → `FKSession` → `fetch /activation` `device_begin`/`device_complete`. Link back to `/sign-in`.
- **`templates/pages/profile.php`**: Two cards: profile form `POST /profile action=update_profile` (`username,full_name,email,profile_version hidden`) + password form `action=change_password` (`current_password,new_password,confirm_password`). Reads `profile ?? []`.
- **`templates/pages/error.php`**: `Error {status}: {message}` escaped, link home.
- **`assets/css/fk-theme.css`**: Design tokens (`--fk-ivory, --fk-cream, --fk-gold, --fk-wine …`), radii, shadows, fonts `Noto Sans Ethiopic`, header/nav/media queries, reduced-motion. Loaded last to restyle prototype markup without behavior change.
- **`assets/js/fk-ui.js`**: Prototype UI helpers (header, nav, assistant window) — not exercised by live pages but shipped.
- **`assets/js/session.js`**: **Replaces** `docs/prototypes/.../frontend/js/session.js` (which logged in against deprecated `/api/auth/login` session-cookie API). Live `session.js` implements `FKSession` IIFE with IndexedDB `fkss_member_device/keys/primary_p256`, `getOrCreateDeviceIdentity()` (generate `ECDSA P-256` non-extractable-ish false + `web-` + `crypto.randomUUID()`), `exportSpkiBase64(pubKey)`, `signChallenge(b64url)` (`fromBase64Url` → `subtle.sign SHA-256` → `rawToDer` → base64), `createDpopProof(htm,htu,ath)` (header `typ:dpop+jwt alg:ES256 jwk`, payload `jti htm htu iat ath?`, signing `base64url(header).base64url(payload)` → raw sig → `rawToDer` → `base64url`). **`rawToDer`** is the browser→OpenSSL bridge (64-byte `r||s` → `DER 0x30 SEQUENCE { INTEGER r, INTEGER s }` with leading-zero handling).

---

## F — File-Level Map

| File | Lines | VERIFIED responsibility |
|---|---|---|
| `index.php` | 254 | **Single HTTP dispatcher.** Boots `bootstrap,Router,Template,SessionManager,Logger,AuthGuard,Config,Repositories`. Reads `REQUEST_URI/METHOD`, `resolve()`, handles `POST /logout` (CSRF + best-effort `logout()` + `clear()` + `303`), activation wizard branching (`verify/credentials` server forms + `device_begin/device_complete` JSON), `POST /sign-in` (CSRF + `login()` + `setAuth(...,true)` + `303 /profile` or `303 /activation` on `403 activation_pending` or re-render with `error` + `Retry-After`), `requireRole(['member'])` for protected routes, `/profile` GET+POST via `ProfileRepository`, cache header `no-store` on errors/`noStore`, renders via `Template`, catches `HttpException` → error page (status) and `Throwable` → `500`. |
| `src/Support/bootstrap.php` | 35 | Autoload list, dependency order comment. |
| `src/Support/Config.php` | 46 | Env file + getenv, `apiBaseUrl/schoolName/appTitle`. |
| `src/Support/HttpException.php` | 42 | `status + publicPayload` error type. |
| `src/Support/Logger.php` | 66 | JSON-lines logger + `sanitize`. |
| `src/Auth/SessionManager.php` | 166 | Session cookie + token store + CSRF + `ath`. |
| `src/Auth/AuthGuard.php` | 53 | `requireRole` guard + audit log. |
| `src/Http/Router.php` | 48 | Finite route table. |
| `src/Repository/ApiClient.php` | 115 | curl + envelope. |
| `src/Repository/AuthRepository.php` | 95 | `login/refresh/verify/logout` |
| `src/Repository/AcmRepository.php` | 92 | ACM ceremony 4 calls |
| `src/Repository/ProfileRepository.php` | 51 | `getMe/updateMe/changePassword` |
| `src/View/Template.php` | 67 | `render` + `escape` + `renderFile`. |
| `templates/layouts/base.php` | 56 | Shell layout |
| `templates/pages/sign-in.php` | 45 | Sign-in form |
| `templates/pages/activation.php` | 132 | 3-step wizard + inline JS |
| `templates/pages/profile.php` | 77 | Two-form profile page |
| `templates/pages/error.php` | 18 | Error card |
| `assets/css/fk-theme.css` | 1058 | Design system |
| `assets/css/style.css` | 2444 | Page layout |
| `assets/js/fk-ui.js` | 505 | UI helpers |
| `assets/js/session.js` | 168 | WebCrypto device key + DPoP + DER |
| `.htaccess` | 17 | Rewrite + blocklist |
| `.fkss_env.example.php` | 9 | Env template |
| `.gitignore` | 16 | Secrets + logs + locks |
| `.github/workflows/lint.yml` | 27 | Lint matrix 8.4/8.5 |
| `storage/logs/.htaccess` | 8 | Deny web access |
| `README.md` | 48 | Repo overview + layout |
| `docs/*.md` (7 files) | ~900 | Handbook (inventory below) |

**Prototype files (not in live path — summarized, not exhaustively traced):** `backend/api.php` (1066 lines, gate + handlers `handleLoginApi/handleStudentApi…`), `backend/router.php` (278, static/API/uploads routing), `backend/lib/security.php` (295, `fk_gate/fk_login_session/throttle/scope`), `backend/api/auth_staff.php` (476, staff login + lessons unlock + admin students), `backend/api/learning.php` (927, path/lessons/materials/progress/verse/assistant — unlock rules), `backend/api/extras.php` (229, notifications/attendance/permissions/past-papers), `backend/api/quiz.php` (1235) + `backend/api/question.php` (1318) + `question_import.php` (700), `backend/config/database.php` + `tools/*` + `data/*.json`, `database/migrations/001 (61) 002 (18) 003 (88)` + rollbacks, `frontend/*.html` (12 pages), `frontend/css/js`, `tests/frontend-mock/*`, `uploads/demo`. Every line verified via `wc -l` + `head` above; full traces in `docs/ARCHIVE.md` & `docs/HANDOVER_FOR_DEVELOPER.md`.

---

## G — Function/Class/Component Map

### G.1 Live app — classes and methods (VERIFIED by reading each file)

**`MembersPortal\Support\Config`**
- `__construct(string $envFile)` — requires php array or `[]`.
- `apiBaseUrl():string` — `FKSS_API_BASE_URL` or getenv, `trim`, `rtrim('/')`, throws `500` if empty.
- `schoolName():string` — default `Felege Kidusan`.
- `appTitle():string` — default `Members Portal`.

**`MembersPortal\Support\HttpException extends RuntimeException`**
- `__construct(int $status, string $message, array $publicPayload=[])` — stores `status` + `payload`.
- `status():int`, `publicPayload():array`.

**`MembersPortal\Support\Logger`**
- `__construct(string $logFile)`, `log(string $event, array $context):void`, `sanitize(array):array`.

**`MembersPortal\Auth\SessionManager`**
- `start():void` — `session_name('FKSS_MEMBER_SID')`, `use_strict_mode`, `use_only_cookies`, `Secure` iff `HTTPS` or `443`, `HttpOnly`, `SameSite=Lax`, `session_start()`.
- `setAuth(array $authData, bool $regenerateId=false):void` — stores `token|''`, `refresh_token|''`, `expires_at (=time+expires_in|900)`, `device_bound`, `user`.
- `isAuthenticated():bool` — token non-empty && `user[id]` present.
- `accessToken():?string`, `refreshToken():?string`, `isAccessTokenExpired(int $leeway=30):bool`, `isDeviceBound():bool`, `user():array`, `accessTokenHash():?string` (base64url sha256).
- `csrfToken():string` — `bin2hex(random_bytes(32))` singleton.
- `verifyCsrfToken(?string):bool` — `hash_equals`.
- `clear():void` — wipe `$_SESSION`, delete cookie, `session_destroy()`.

**`MembersPortal\Auth\AuthGuard`**
- `__construct(SessionManager, Logger)`, `requireRole(array $allowedRoles=['member']):array` — 401 if unauthenticated, 403 if role not in allow-list; logs `access_denied`.

**`MembersPortal\Http\Router`**
- `const ROUTES` finite map, `resolve(string $requestUri):array` → `{template,title,noStore,protected}` or throw `404`.

**`MembersPortal\Repository\ApiClient`**
- `__construct(string $baseUrl, int $timeout=15)` stores `rtrim(baseUrl,'/')`.
- `request(string $method, string $path, ?array $body, ?string $token, ?string $dpop):array` — curl + `Retry-After` capture + status-code checks + `HttpException`.

**`MembersPortal\Repository\AuthRepository`**
- `__construct(ApiClient,string $lockDir)`, `login(...)` (payload username/password[+installation_id/device_fingerprint]), `refresh(sessionKey,refreshToken,dpop?)` (flock), `verify(token,dpop?)`, `logout(refreshToken,accessToken?)`.

**`MembersPortal\Repository\AcmRepository`**
- `__construct(ApiClient)`, `verifyCode`, `setCredentials`, `beginDeviceRegistration`, `completeDeviceRegistration`.

**`MembersPortal\Repository\ProfileRepository`**
- `__construct(ApiClient)`, `getMe`, `updateMe`, `changePassword`.

**`MembersPortal\View\Template`**
- `__construct(string $templatesDir)`, `static escape(mixed):string`, `render(string $page,array $data,string $layout='base'):string`, `renderFile(string $file,array $vars):string`.

### G.2 Prototype — notable functions (VERIFIED, not exhaustive)

- `sendJson`, `fk_gate`, `fk_session_start`, `fk_current_user`, `fk_login_session`, `fk_logout_session`, `fk_throttle_check/fail/clear`, `fk_password_ok`, `fk_staff_can_manage`, `fk_learning_student`, `fk_learning_rows` (unlock engine), `fk_open_lesson_ids`, `fk_learning_state`, `fk_lesson_for_student`, `fk_require_lesson_open`, `fk_week_dots`, `handleLearningApi`, `handleExtrasApi`, `handleStage1Api`, `quiz*`, `question*` etc. — all under `docs/prototypes/.../backend/**`. Behavioral detail in `docs/HANDOVER_FOR_DEVELOPER.md` and Section L below.

---

## H — Database Architecture

### H.1 Live app: **no database** (VERIFIED)

`grep -r PDO` outside `docs/prototypes/` returns nothing; `src/` has no `database.php`, no migrations, no ORM, no `members` table. `index.php` holds no DSN, no `$pdo`. Data life-cycle is `Repository → ApiClient::curl → Platform /api/v1 → Platform MariaDB`. This is intentional per `docs/01-SYSTEM-CONTEXT.md: May not connect`.

**Implication:** The live app can be tested only against a live or mocked platform. There is no local `users` row to inspect.

### H.2 Platform database (INFERENCE — not verified from this repo)

The handbook states the platform uses MariaDB with tables `users`, `members`, `attendance`, `notifications` etc., and the live app consumes it via `/api/v1`. This cannot be verified from the prototype's `felege_kidusan_test` alone. **Mark: INFERENCE — requires verification against `fkss-sunday-school-platform` commit `d7d27c1`.**

### H.3 Prototype database — `felege_kidusan_test` (VERIFIED from migrations)

**Pre-existing tables (INFERENCE from FK references):** `students(student_id PK, name, grade, class, password_hash, active)`, `subjects(id, name, grade, language)`, `chapters(id, subject_id FK, chapter_number, title, sort_order)`, `lessons(id, chapter_id FK, lesson_number, title, description, status=published, sort_order)`, `lesson_unlocks(lesson_id, grade, class, class_key generated, UNIQUE(lesson_id,grade,class_key), version from 001 adds `class`, `class_key`), `questions(id, active, chapter_id, lesson_id, ... )`, `question_options`, `lessons`, etc.

**Migration `001_stage1_auth_and_unlocks.sql` (VERIFIED, 61 lines):**
- `ALTER students ADD must_change_password TINYINT(1) DEFAULT 0`.
- `CREATE staff(staff_id PK, name, role teacher|admin, password_hash, active, created_at)`.
- `CREATE staff_scopes(id AI, staff_id FK staff ON DELETE CASCADE, grade, class NULL→whole grade)`.
- `CREATE login_attempts(id AI, identifier, ip, attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, idx lookup)`.
- `ALTER lesson_unlocks ADD class, class_key GENERATED STORED (IFNULL(class,''))`, `ADD UNIQUE uq_unlock_target(lesson_id,grade,class_key)`, `DROP INDEX unique_lesson_grade, uq_lesson_grade_teacher`. **No `CREATE DATABASE`/`USE`** — safe to apply to named DB only.

**Migration `002_lesson_progress.sql` (VERIFIED, 18 lines):**
- `CREATE lesson_progress(id AI, student_id FK students RESTRICT, lesson_id FK lessons RESTRICT, completed_at, UNIQUE(student_id,lesson_id))`.

**Migration `003_unit_unlocks_and_student_extras.sql` (VERIFIED, 88 lines):**
- `CREATE unit_unlocks(id AI, chapter_id FK chapters CASCADE, grade, class, class_key, teacher_id, UNIQUE(chapter_id,grade,class_key))` — whole-unit unlock.
- `CREATE notifications(id AI, title, body, audience_grade, audience_class, student_id NULL=one, created_by, created_at)` — audience `ALL NULL = everyone`.
- `CREATE notification_reads(student_id FK CASCADE, notification_id FK CASCADE, PK both, read_at)`.
- `CREATE attendance(id AI, student_id FK RESTRICT, attendance_date DATE, status present|absent|late|permission, note, recorded_by, UNIQUE(student_id,attendance_date))`.
- `CREATE permission_requests(id AI, student_id FK RESTRICT, request_date DATE, reason 500 chars, status pending|approved|declined, reviewed_by, review_note, reviewed_at, idx student+created_at)`.
- `CREATE past_papers(id AI, grade, subject, exam_year, title, file_name, file_path 500 chars RELATIVE to `uploads/`, idx grade+year)`.

**Rollbacks (VERIFIED):** `001_stage1_rollback.sql` (15 lines — drops staff* / login_attempts, reverts `lesson_unlocks` columns/indexes), `002_rollback.sql` (`DROP TABLE lesson_progress`), `003_rollback.sql` (7 lines — drops 6 tables), `demo/demo_past_paper.sql` (5 lines — INSERTS one 2018 row). Rollbacks for 001 warn they delete new data.

**Prototype tools:** `backend/tools/check_setup.php` (85 lines, CLI-only, read-only checks against `felege_kidusan_test`, reports FAIL for missing tables/columns), `create_staff.php` (63, creates staff only on test DB), `import_lesson_text.php` (114, dry-run + `--apply`).

### H.4 Conceptual data flow (VERIFIED)

```
[Browser form] → [index.php $_POST] → [Repository payload] → [Platform /api/v1]
                                                        ↓
                                            [Platform DB users/members …]
                                                        ↓
                                            [ApiClient decodes {status,data,message,code}]
                                                        ↓
                                            [SessionManager user[] / Template $profile]
                                                        ↓
                                            [Browser HTML]
```

No ORM, no `SELECT` in live code, no transactions.

---

## I — API Map

Source of truth for live app: `docs/03-API-INTEGRATION.md` (read from platform `d7d27c1 api/v1/routes/`). Prototype's `/api/*` is **not** the platform's `/api/v1/*`.

### I.1 Platform `/api/v1` — endpoints the live app may rely on (VERIFIED via handbook + Repository)

| Method | Path | Auth | Repository method | Purpose |
|---|---|---|---|---|
| `GET` | `/ping` | none | *(not called yet — liveness)* | no DB, no auth |
| `POST` | `/auth/login` | none | `AuthRepository::login` | tokens `{token, refresh_token, expires_in:900, device_bound, user{…authorization_version, profile_version}}` |
| `POST` | `/auth/refresh-token` | `refresh_token` | `AuthRepository::refresh` (flock) | rotate single-use |
| `POST` | `/auth/logout` | `refresh_token` | `AuthRepository::logout` | revoke family, idempotent |
| `GET` | `/auth/verify` | `Bearer` | `AuthRepository::verify` | `{valid, user, expires_at}` |
| `POST` | `/acm/activate/verify` | none | `AcmRepository::verifyCode` | redeem `selector+secret` → `grant` |
| `POST` | `/acm/activate/credentials` | `grant` | `AcmRepository::setCredentials` | `grant,password,idempotency_key` → `user_id + device_grant` |
| `POST` | `/acm/reset/verify` | none | *(not wired yet)* | recovery |
| `POST` | `/acm/reset/credentials` | `grant` | *(not wired)* | recovery |
| `POST` | `/acm/devices/register/begin` | `grant\|Bearer` | `AcmRepository::beginDeviceRegistration` | `grant,installation_id,pubkey(SPKI b64),name` → `challenge_id,challenge(b64url)` |
| `POST` | `/acm/devices/register/complete` | possession | `AcmRepository::completeDeviceRegistration` | `challenge_id,challenge,signature(DER b64)` → `device_uuid` |
| `GET` | `/acm/devices` | `Bearer` | *(not wired)* | own devices |
| `POST` | `/acm/devices/revoke` | `Bearer` | *(not wired)* | revoke |
| `GET` | `/users/me` | `Bearer` | `ProfileRepository::getMe` | own profile (`profile_version`, `profile_image{present,version,url}`, `authorization_version`) |
| `PATCH` | `/users/me` | `Bearer` | `ProfileRepository::updateMe` | requires `profile_version`; `claims_refresh_required:true` on rename |
| `GET/POST/DELETE` | `/users/me/profile-image` | `Bearer` | *(not wired)* | own photo |
| `POST` | `/users/change-password` | `Bearer` | `ProfileRepository::changePassword` | `current_password,new_password,confirm_password`, limit `5/15 min` |
| `GET` | `/users/me/children` | `Bearer parent` | *(not wired — Service/MemberRepository planned)* | verified links only; `403` for non-parents |
| `GET` | `/members/{id}` | `Bearer scoped` | *(not wired)* | parent sees **safe row** only; member `403` for all ids |
| `GET` | `/members/{id}/attendance?days=` | `Bearer scoped` | *(not wired)* | parent own children |

**Gaps (VERIFIED):** No `GET /members/me`, no lessons/quizzes/materials/progress/tasks/verse/permissions/past-papers on the platform — prototype calls to those belong to discarded backend (`docs/03-API-INTEGRATION.md §7`).

### I.2 Envelope (VERIFIED `src/Repository/ApiClient.php`)

```
Success: { status:"success", data:{…}, message?:"…" }
Error:   { status:"error",   message:"Human text", code?:"MACHINE_CODE" }
```
`data` absent when nothing to return. `code` not always present. `429 → Retry-After + X-RateLimit-Limit`, `503 → AUTH_*_UNAVAILABLE / CACHE_UNAVAILABLE` (retry), `401 REFRESH_REUSED/EXPIRED/SESSION_REVOKED`, `409 PROFILE_CLAIMS_CHANGED/AUTH_SCOPE_CHANGED`, `403 activation_pending` etc. `X-Request-Id` + `X-API-Version:1.0` on every response. Envelope check: require `is_array && isset(status)` else `502 Unexpected response`.

### I.3 Prototype `/api` (VERIFIED, `docs/prototypes/.../backend/api.php` + handlers)

~29 paths, all `$_SESSION`-gated via `fk_gate()` (not Bearer), prefix `/api` (no version):
`POST /api/auth/login|/api/auth/staff-login`, `GET /api/auth/me`, `POST /api/auth/logout`, `POST /api/auth/change-password`, `GET /api/learning/path`, `GET /api/learning/lessons/{id}`, `POST …/complete`, `GET /api/learning/materials/{id}/download`, `GET /api/progress|/progress/weak-topics|/progress/attempts/{id}`, `GET /api/verse/today`, `POST /api/assistant/ask`, `GET /api/notifications`, `POST /api/notifications/{id}/read|/read-all`, `GET /api/attendance`, `GET/POST /api/permissions`, `GET /api/past-papers|…/{id}/file`, `GET /api/tasks/{studentId}`, `POST /api/quiz/start|/answer|/complete`, `GET /api/quiz/*`, `GET/POST …/questions…`, staff/admin `staff/lessons/{id}/unlock|lock`, `admin/students…`, `tasks/{id}/attachments`. Gate: public only `/api/auth/login|staff-login`; authenticated; `must_change` blocks students; `staff/ admin/ questions/` refuse students; `student|lessons|tasks|quiz/...` last-segment id must equal `$_SESSION.user.id` for students (checked via `hash_equals`); quiz body `student_id` must match session.

### I.4 Consumers / traceability

- Live: **Browser form → `index.php` → `*Repository` → `ApiClient` → Platform**. No other caller. `ApiClient` adds `Authorization`+`DPOP`; `AuthRepository` adds `flock`.
- Prototype: **Browser fetch(XHR) → `router.php` → `api.php` → `fk_gate()` → handler → `PDO` → JSON**. Prototype frontend `session.js` (50 lines, `storage localStorage + fetch /api/auth/login`) is the former consumer — replaced by live `session.js` + `index.php` form POST.

---

## J — Authentication & Authorization

### J.1 Live — Bearer + DPoP (VERIFIED)

**Issuance:** `POST /api/v1/auth/login` with `{username, password, installation_id?, device_fingerprint?}` → `200 {token (JWT ~900s), refresh_token (90d rotating), expires_in, device_bound:bool, user{ id, username, full_name, email, role, is_active, profile_image, profile_version, authorization_version }}`. Rate limit `10/min/IP`. Failures: `401` generic, `403 activation_pending` (= finish ACM), `429` `Retry-After`, `503` retry.

**Storage:** `SessionManager::setAuth()` stores tokens server-side in `$_SESSION['fk_auth']`; browser holds only `FKSS_MEMBER_SID` opaque cookie (`Secure` when `HTTPS||443`, `HttpOnly`, `SameSite=Lax`, `Strict`, `lifetime 0`). Tokens never appear in URL/log/template. `Logger::sanitize` proves intent.

**Refresh (single-flight):** `AuthRepository::refresh()` `mkdir locks`, `flock LOCK_EX` on `refresh_<sha256(sessionKey)>.lock`, then `POST /auth/refresh-token {refresh_token}` (plus `DPOP` when `device_bound`). Rotating: second concurrent presentation → `401 REFRESH_REUSED` revokes family. `index.php` does **not yet call refresh** — `SessionManager` only **stores** `expires_at`; no middleware refreshes before `AuthGuard::requireRole`. **Mark: refresh not exercised in live request path.**

**Verification:** `apiRequireAuth()` on the platform re-checks `role` + `authorization_version` + `is_active` against DB per request; mismatch → `401 AUTH_SCOPE_CHANGED` / `409 PROFILE_CLAIMS_CHANGED` → client should `refresh once, then sign out`. Live `ProfileRepository` callers do not yet handle `409`.

**DPoP (device-bound):**
- When `device_bound=true` (login included `installation_id` + registered device), **every authenticated request** (except `refresh`) requires a per-request proof `DPOP: <header>.<payload>.<sig>` where `header {typ:dpop+jwt, alg:ES256, jwk:{kty:EC,crv:P-256}(no d)}`, `payload {jti (unique, 900s TTL), htm:upper(method), htu:exact path (refresh allows suffix), ath:base64url(sha256(access_token)) required, iat (±300s)}, sig: ES256 DER`. `require` `DPoP` header `ES256` proof; wrong `jti`/`htu`/`ath` → `ACCESS_PROOF_INVALID` with no detail. Live `ApiClient` supports `dpopProof` param; `ProfileRepository::getMe/updateMe/changePassword` accept it; `index.php` passes `null` today (sessions are unbound by default — login omits `installation_id`). `assets/js/session.js:createDpopProof()` builds proofs on the browser; `index.php` does not yet use `accessTokenHash()` to supply `ath`.

**Logout:** `POST /auth/logout {refresh_token}` (best-effort) + `SessionManager::clear()` deletes cookie + `session_destroy()`; logs `logout` with `user_id`.

**Activation (ACM):** `selector+secret(purpose=activate, installation_id?)` → grant → `grant+password+idempotency_key→ user_id+device_grant` → `device_grant+installation_id+pubkey(SPKI b64)+name→ challenge` → `signature(DER b64) over challenge bytes → device_uuid`. Verified in `index.php` + `AcmRepository` + `assets/js/session.js` `rawToDer`. Demo: P-256 key in IndexedDB non-extractable, challenge bytes `fromBase64Url(challenge)`, `subtle.sign SHA-256`, raw (64) → DER (via leading-zero handling) → base64.

**CSRF:** Since auth is cookie-based (PHP session), every `POST` checks `csrf_token` (`SessionManager::csrfToken()` `random_bytes(32)` hex, `verifyCsrfToken` via `hash_equals`) → `403 Invalid or expired form submission.` Verified in `index.php: verifyCsrfToken` on `/logout`, `/activation` (all steps), `POST /sign-in`, `POST /profile (update|password)`. No CSRF on JSON `fetch /activation device_*` — CSRF present as form param `csrf_token` in URLSearchParams body, validated server-side via `verifyCsrfToken`. GETs are safe (no state change).

### J.2 Authorization (VERIFIED)

**Platform ACL (live):** `/users/me*` — **no role gate** (`any authenticated`), ownership from token. `/users/me/children` — `parent` only. `/members/{id}(/attendance)` — parents only via `apiParentCanSeeMember()` deny-by-default, returns safe row; `member` sees **none** (verified: `member` in no allow-list per `docs/03-API-INTEGRATION.md` + `05 §2`). Staff routes `classes|dashboard|notifications|attendance|grades|subjects|timetable|teachers|enrollment|mezmur|sync` → `apiRequireStaffRole()` / `apiRoles*` allow-lists exclude member/parent.

**Live app guard:** `AuthGuard::requireRole(['member'])` for `/profile` and `/logout` (only `member` pass; `parent|teacher|admin|staff` → `403 You do not have permission`). No guard on `/sign-in`/`/activation`. **INFERENCE:** `parent` users will be unable to open `/profile` today — intentional per handbook's member-first build, but will block parent testing.

**Prototype gate (`fk_gate`, VERIFIED):** Public `/api/auth/login|staff-login` only; else `401 AUTH_REQUIRED`; account routes any signed-in; `must_change` students forced to `change-password` (403 `PASSWORD_CHANGE_REQUIRED`); `admin/` → admin only; `staff/` → teacher|admin; `questions/**` + non-GET `tasks` + `tasks/*/attachments` → isStaff; `student|lessons|tasks|quiz/.../{id}` last segment must equal `user.id` for students (or `403 FORBIDDEN`); `quiz/start|answer|complete` body `student_id` must equal `user.id`. Plus `teacher scope` (`fk_staff_can_manage` checks `staff_scopes` grade/class).

---

## K — State Management

| State | Where | Lifecycle | Evidence |
|---|---|---|---|
| **PHP session cookie** `FKSS_MEMBER_SID` | Browser cookie `HttpOnly Secure Lax` | `lifetime 0` (until tab close / OS cleanup), destroyed on `clear()` | `SessionManager:start()` |
| **Access token / refresh token / expires_at / device_bound / user{}** | `$_SESSION['fk_auth']` server-side only | `setAuth(..., regenerateId=true)` on login; cleared on logout; re-validated per request by platform | `SessionManager:setAuth/isAuthenticated/accessToken` |
| **CSRF token** `fk_csrf` | `$_SESSION['fk_csrf']` + hidden `<input csrf_token>` | singleton per session, `random_bytes(32) hex`, checked on every `POST` | `SessionManager::csrfToken/verifyCsrfToken`, `index.php` checks |
| **Grant (ACM)** `grant` | `hidden <input grant>` + `idempotency_key` in HTML → POST | short-lived, single activation ceremony; `random_bytes(16) hex` per `verify` success | `index.php: bin2hex(random_bytes(16))`, `credentials` step hidden field |
| **Device identity** `{keyPair(P-256), installationId: web-uuid}` | `IndexedDB fkss_member_device/keys/primary_p256` (non-extractable-ish, `false` non-extractable) | survives navigation, cleared only if user clears site data; generation on demand | `assets/js/session.js:getOrCreateDeviceIdentity()` |
| **Private key** | `CryptoKey` inside IndexedDB, never `localStorage` JWK text | `(STS)` — never exported | `session.js` — `generateKey` non-extractable `false` but store is IndexedDB objectStore, not `localStorage` per `05 §4` |
| **Chat / UI state** | `sessionStorage fk_chat_v2` (prototype `fk-ui.js` assistant), `localStorage?` none in live | per-tab, not persisted across logins | `assets/js/fk-ui.js: convo save() sessionStorage` (inherited from prototype, `handleExtras` not wired live) |
| **Platform tokens** `authorization_version/profile_version` | Platform DB + JWT claim, echoed on `user{}` | bump on role/password/profile rename; mismatch → 401/409 mid-session | `docs/03 §3.4` |
| **Logging state** `auth.log` JSON-lines | `storage/logs/auth.log` file | append-only, `LOCK_EX`, never truncated by app | `Logger.php` |

No Redux/Zustand, no global store, no server cache in this app. Prototype stored `login throttles` in `login_attempts` table and `notifications|attendance|permissions` in MySQL; live app holds none of that locally.

---

## L — Business Rules (extracted from code)

| Rule | Where | Verbatim behavior |
|---|---|---|
| **Lesson publish rule (prototype, archived)** | `backend/api/learning.php:fk_learning_rows` | Only `l.status='published'` for the student's `s.grade` in `subjects→chapters→lessons` ordered by `s.id, c.sort_order, c.id, l.sort_order, l.id`. |
| **Lesson unlock — preconditions** | `fk_learning_rows` | `UNLOCKED(unit)` = `unit open && (rank<2 \|\| teacher_unlocked)`. `teacher_unlocked` = `EXISTS lesson_unlocks(grade, class)` (class NULL matches all). |
| **Unit unlock** | `fk_learning_rows` | Unit 1 always `open=true`. Unit N+1 `open = (prev quiz_ok==true && teacher_unlocked(unit N+1)==true)`. `quiz_ok = !quiz_required \|\| (quiz_passed && lessons_done==lessons_total)`. `quiz_required = COUNT questions(active=1, chapter_id OR lesson.chapter_id) >0`. `quiz_passed = EXISTS quiz_attempts(student, chapter, scope='unit', completed_at NOT NULL, passed=1)`. `waiting_for = quiz` vs `teacher` accordingly. |
| **Unit quiz available** | `fk_learning_rows` | `open && quiz_required && lessons_total>0 && lessons_done==lessons_total`. |
| **State machine** | `fk_learning_state` | `if completed→completed; else if unlocked→available else locked`. `completed` checked via `EXISTS lesson_progress(student,lesson)`. |
| **Next lesson** | `fk_next_available` | First `available` after given id in row order. |
| **Completion** | `handleLearningApi POST /complete` | Only if `state != locked`; INSERT `lesson_progress` (unique); never unlocks anything by itself. |
| **Controlled progression** | `fk_learning_student` | Every handler loads student from `students WHERE active=1` via `session.user.id` — request-supplied id ignored. |
| **Attendance percent** | `backend/api/extras.php` | `percent = null` if `total 0` else `round((present+late)*100/total)`; counts `GROUP BY status`. Records last 60 desc. `members/{id}/attendance` on platform uses `days=90` param (live). |
| **Notifications** | `backend/api/extras.php` | `WHERE (student_id IS NULL OR =self) AND (audience_grade IS NULL OR=grade) AND (audience_class IS NULL OR=class)` ORDER `created_at DESC LIMIT 30`; `is_read` via `LEFT JOIN notification_reads`; `POST read` uses `INSERT IGNORE` scoped by same audience filter; `read-all` inserts for all visible. |
| **Permissions** | `backend/api/extras.php` | `POST /api/permissions` validates `request_date Y-m-d` via `DateTime::createFromFormat`, `reason 1..500 UTF8`, rejects if `COUNT pending >=5` → `429`; insert. `GET` last 30 desc. |
| **Past papers** | same | `WHERE grade=? ORDER exam_year DESC, subject ASC`; file serve `grade` check, path sanitization `realpath uploads/` + `strncmp` traversal guard + `file_exists`, types `pdf|jpg|jpeg|png`. |
| **Search (assistant)** | `handleLearningApi POST /assistant/ask` | Keyword search over **open lessons only** (`fk_open_lesson_ids`), no AI model; server key never in frontend. |
| **Password rules** | `lib/security.php:fk_password_ok` | `mb_strlen >=8 && strlen<=200`; change requires `current_password` verify, `new_password/confirm` match (platform: `5/15 min` limit). |
| **Throttling** | `lib/security.php` | `SELECT COUNT(*) login_attempts WHERE identifier=? AND ip=? AND attempted_at > NOW()-15min`; `>=5 →429 Too many`. On success `DELETE`. Identifier is `student_id` or `staff:staff_id` + `REMOTE_ADDR`. Missing table → skip + `error_log`. |
| **Scope check (staff)** | `lib/security.php:fk_staff_can_manage` | `admin → true`; teacher: whole-grade action needs `scope class IS NULL`; class action needs `class IS NULL OR class=target`. |
| **CRLF / role gate** | `lib/security.php:fk_gate` | Documented §J.2 above — `hash_equals` for id comparison. |
| **Live app profile concurrency** | `templates/pages/profile.php` + `ProfileRepository::updateMe` | Hidden `profile_version` submitted; platform rejects on `409 PROFILE_CLAIMS_CHANGED`. Changing `username/full_name` sets `claims_refresh_required:true`. |
| **Terms of re-auth** | `docs/03 §3.4` | Role/`authorization_version`/`is_active` change invalidates token mid-session → `401 AUTH_SCOPE_CHANGED` (retry once, then sign out). |

---

## M — Workflow Maps (end-to-end, actual implementation)

### M.1 Sign-in (VERIFIED)

```
[Browser] GET /sign-in → index.php:Router resolves → render sign-in (csrfToken)
[Browser] POST /sign-in {csrf,username,password} → index.php: verifyCsrfToken → AuthRepository.login(username,password)
          → ApiClient POST /auth/login JSON {username,password} → Platform
              ← 200 {token,refresh_token,expires_in:900,device_bound,user}
            → SessionManager::setAuth(...,regenerateId=true) → Logger login_success → 303 Location:/profile
              ← 403 code=activation_pending → Logger activation_pending → 303 /activation
              ← 401|429 → Logger login_failed → render sign-in with error (+Retry-After)
              ← 503/502 → render error (handled as HttpException)
[Browser] GET /profile → AuthGuard.requireRole(['member']) → ProfileRepository.getMe → render
```

### M.2 Activation — office code → password → device (VERIFIED)

```
[Office] issues selector+secret (ACM grant system)
[Browser] GET /activation → render verify (csrf)
[Browser] POST /activation step=verify {selector,secret} → verifyCsrfToken → AcmRepository.verifyCode(selector,secret,purpose=activate)
          → POST /acm/activate/verify → {grant} → Logger activation_verify → render credentials with grant+idempotencyKey(random_bytes(16) hex)
[Browser] POST /activation step=credentials {grant,password,idempotency_key} → verifyCsrfToken → AcmRepository.setCredentials(grant,password,idempotency_key)
          → POST /acm/activate/credentials → {user_id, device_grant} → Logger activation_credentials → render device
[Browser JS] click Register This Device → FKSession.getOrCreateDeviceIdentity() (IDB or generate ECDSA P-256 + web-uuid)
          → exportSpkiBase64(public) → fetch POST /activation step=device_begin {csrf,grant,installation_id,pubkey,name}
              → AcmRepository.beginDeviceRegistration → POST /acm/devices/register/begin → {challenge_id,challenge(b64url)}
          → FKSession.signChallenge(challenge) (fromBase64Url → subtle.sign SHA-256 raw 64 → rawToDer → base64)
          → fetch POST /activation step=device_complete {csrf,challenge_id,challenge,signature}
              → AcmRepository.completeDeviceRegistration → POST /acm/devices/register/complete → {device_uuid}
          → Logger activation_complete → JS sets status "Device registered! Redirecting…" → window.location=/sign-in
  (On HttpException → json {error:msg} with status, JS shows msg)
```

### M.3 Profile read / update / password change (VERIFIED)

```
Authenticated [Browser] GET /profile → AuthGuard.requireRole(['member']) ( else 401/403→error page) → SessionManager.accessToken
          → ProfileRepository.getMe(token) → ApiClient GET /users/me + Authorization Bearer → {user + profile_version}
          → render profile card (values escaped) + password card. On HttpException → profile=user fallback + error.
[Browser] POST /profile action=update_profile {csrf,profile_version,username,full_name,email} → verifyCsrfToken
          → ProfileRepository.updateMe(token,{profile_version,username,full_name,email}) → PATCH /users/me
          → on success: notice "Profile updated."; on HttpException(409 etc.): error.
[Browser] POST /profile action=change_password {csrf,current,new,confirm} → verifyCsrfToken
          → ProfileRepository.changePassword(token,…) → POST /users/change-password → notice/error.
  (Cache-Control:no-store on all /profile renders)
```

### M.4 Logout (VERIFIED)

```
[Browser] POST /logout {csrf_token} (from base.php nav form) → AuthGuard.requireRole(['member']) → verifyCsrfToken
          → SessionManager.refreshToken()+accessToken → AuthRepository.logout(refreshToken,accessToken) (best-effort catch(Throwahle ignored))
          → SessionManager.clear() (wipe, delete cookie, destroy) → Logger logout(user_id) → 303 /sign-in
```

### M.5 Lesson — prototype only (VERIFIED, NOT in live path)

```
[Student Browser] GET /api/learning/path → fk_gate (must be student, must_change==false) → fk_learning_student(pdo) → fk_learning_rows(pdo,student)
          → group subjects→chapters→lessons, compute unit open/quiz_ok/waiting_for, rank, unlocked, state → JSON subjects[].lessons{state}
[Browser] GET /api/learning/lessons/{id} → fk_lesson_for_student (404 if not grade) → fk_require_lesson_open (403 LESSON_LOCKED if locked) → JSON lesson+materials+state
[Browser] POST /api/learning/lessons/{id}/complete → require open → INSERT lesson_progress → totals/percent/next → JSON
[Dashboard] INSERT lesson_unlocks/unit_unlocks by teacher/admin via staff endpoints or directly → next GET path shows changed states.
```

### M.6 Quiz / Questions — prototype only

```
[Student] POST /api/quiz/start {student_id (must match session)} → validate unlocked, scope/unit → INSERT quiz_attempts
[Student] POST /api/quiz/answer | /complete → store answers, score 0..100, passed = score>=60% (engine-owned), scope='unit' for unit quizzes
[Teacher] POST /api/admin/students {grade,class,… temporary password} → INSERT students(active=1, must_change_password=1)
[Teacher] manage question bank via /api/questions (+import), grade/class checks.
```

---

## N — Status/State Transition Maps

### N.1 ACM Activation (VERIFIED `index.php` + `AcmRepository` + handbook)

```
[unissued] —office issues selector+secret→ [code_issued]
[code_issued] —POST verify correct→ [grant_issued (verify success)]  ; wrong→ error, stays code_issued ; network_denied→ stays
[grant_issued] —POST credentials (strong password, fresh idempotency_key)→ [password_set + device_grant] ; duplicate key→ idempotent success ; weak→ error
[password_set] —begin proves possession of device key → challenge → sign → complete→ [device_bound (device_uuid)] → redirect sign-in
Any failure in device steps → remain password_set, retry allowed (new beginning with same grant while valid)
```

### N.2 Student lesson state (prototype, VERIFIED `fk_learning_state`)

```
locked --(unit opens + (rank<2 OR teacher_unlock))--> available --(press "I finished")--> completed
  ↑ locked cannot be completed (403 LESSON_LOCKED)
Note: finishing never transitions any other lesson; only unit_unlocks + unit quiz passed can unlock next unit.
Unit: closed --(prev quiz_ok && unit_unlock teacher)→ open ; open + lessons done + quiz available flag → quiz node appears.
```

### N.3 Token family (live, VERIFIED docs + ApiClient+AuthRepo)

```
Created (login) → {access:15m, refresh:90d, family valid}
  —refresh (single rotation)→ {new access, new refresh (old consumed), family still valid}
  —second concurrent refresh on old token→ REFRESH_REUSED → family revoked → all tokens dead → re-login required
  —inactivity/expiry→ refresh works until refresh expires → REFRESH_EXVPIRED → re-login
  —role/password/authorization_version change→ AUTH_SCOPE_CHANGED / PROFILE_CLAIMS_CHANGED → refresh once else logout
  —logout → family revoked (any token) → session cleared
```

### N.4 Student `active` / `must_change_password` (prototype)

```
INSERT active=1, must_change_password=1 → login succeeds but gate returns 403 PASSWORD_CHANGE_REQUIRED until change
→ POST /api/auth/change-password correct→ must_change=0 → gate passes
→ admin reset temp password→ must_change=1 again
→ active=0 → 403 inactive; password wrong→ 401; throttle 5/15m→ 429 .
```

---

## O — External Integrations

| Integration | Direction | Protocol | Auth | Where |
|---|---|---|---|---|
| **Platform REST `/api/v1`** | Members app → platform | HTTPS JSON, `Host→port 443`, `curl` | Bearer (+ DPoP when bound) | `ApiClient` baseUrl from `.fkss_env.php` |
| **Flutter mobile app** | *separate client* of same `/api/v1` (reference, not called) | Same envelope | Bearer + DPoP | `docs/01-SYSTEM-CONTEXT.md` — frozen paths pinned in installed APKs → contract stability INFERENCE |
| **Platform admin/teacher dashboards** | Writers to prototype tables | — | — | `Handover` — dashboards should INSERT `unit_unlocks, notifications, attendance, past_papers` and UPDATE `permission_requests` — prototype reader |
| **No email/SMS/payment/cloud/monitoring/queue/cron** | — | — | — | VERIFIED: none present; logging is file-only, no provider SDKs |

**CORS consequence (VERIFIED `docs/01 §6`, `docs/05 §5`, `docs/03 §1 note`):** Platform `api/v1/core/middleware.php::handleCors()` allow-lists `[SITE_URL, http://SITE_DOMAIN, https://www.SITE_DOMAIN]`; members subdomain **is not** in list → browser `fetch()` from subdomain blocked. Two fixes: (1) add subdomain in platform (one line, platform release train), (2) proxy via members host (`/api/*` forwards Authorization/DPOP, no cache). **Unknown:** whether production serves API on same host or separate `api.` hostname (`SITE_DOMAIN` from private env).

---

## P — Configuration

| Key | Source | Required | Default | Evidence |
|---|---|---|---|---|
| `FKSS_API_BASE_URL` | `/.fkss_env.php` array return OR `getenv` | **yes** — 500 if missing/empty | none (hard fail) | `Config.php:apiBaseUrl()` throws `HttpException(500, incomplete)`; `index.php:$config=new Config(.../.fkss_env.php)` |
| `FKSS_SCHOOL_NAME` | same | no | `Felege Kidusan` | `Config:schoolName()` |
| `FKSS_APP_TITLE` | same | no | `Members Portal` | `Config:appTitle()` |
| Environment file location | `__DIR__/.fkss_env.php` (repo root, outside git) | expected outside web root per handbook; example at `.fkss_env.example.php` | — | `index.php:$config=new Config(__DIR__.'/.fkss_env.php')`, `.gitignore: .fkss_env.php` |
| Protocol | provided value trimmed, trailing `/` stripped | — | — | `rtrim(trim($url),'/')` |
| Prototype DB | `backend/config/database.php` constants `host felege_kidusan_test CHANGE_ME_USER/PASSWORD` | for prototype only | none | `docs/prototypes/.../backend/config/database.php` |
| Locks dir | `__DIR__.'/storage/locks'` for refresh `flock` | auto-mkdir 0750 | — | `index.php: new AuthRepository(...,'storage/locks')`, `AuthRepository::refresh` mkdir |
| Logs | `__DIR__.'/storage/logs/auth.log'` | auto-mkdir 0750 | — | `index.php:$logger=new Logger(.../auth.log)`, `Logger` mkdir |
| Session cookie | `FKSS_MEMBER_SID`, `Lax` | — | path `/`, lifetime 0 | `SessionManager` |
| Timeouts | `ApiClient timeout 15s, connect 5s` | — | — | `ApiClient::__construct(timeout=15)` |
| TLS | `SSL_VERIFYPEER true, VERIFYHOST 2` | — | — | `ApiClient` |

Missing env → `500 Application configuration is incomplete.` rendered via `HttpException` catch as `error` page `Cache-Control:no-store`. No guessing of base URL per `docs/04 §5`.

---

## Q — Error Handling

| Layer | Strategy | Evidence |
|---|---|---|
| **`ApiClient`** | Envelope parser: `502 Unexpected response` if `!is_array`\|\| `!isset(status)`; `503 AUTH_SERVICE_UNAVAILABLE` if `curl_exec false\|status 0` (maps `curl_error` to `detail:transport_error`); on `status==='error'` → throw `HttpException(httpStatus>=400?httpStatus:500, message||'Request failed.', {code?,retry_after})`. Captures `Retry-After` header. `400 Invalid request payload` on `json_encode false`. `500 Unable to initialize client` on `curl_init false`. | `ApiClient.php: raw→status→decoded→error→data` |
| **`Repository`** | No extra handling — lets `HttpException` bubble; caller decides UI. `AuthRepository::refresh` rethrows `500 Unable to coordinate` if lock file open fails. | `AuthRepository.php` |
| **`Router`** | Unknown path → throw `HttpException(404, The requested page could not be found.)` | `Router.php: if !isset(ROUTES[path]) throw 404` |
| **`Template`** | Invalid `page`/`layout` name (regex) → `404` ; missing file → `404` | `Template.php: preg_match + is_file` |
| **`AuthGuard`** | Unauthenticated → `log access_denied unauthenticated` → `401 Please sign in.` ; wrong role → `log role_not_allowed` → `403 You do not have permission.` | `AuthGuard.php` |
| **`SessionManager/Config`** | `verifyCsrfToken` returns bool; caller throws `403 Invalid or expired form submission.` if false. `Config::apiBaseUrl` throws `500 incomplete`. | `index.php: verifyCsrfToken` on every POST |
| **`index.php` dispatcher** | `try { route → handlers → guard → repository → render } catch(HttpException $ex) { http_response_code(status); no-store; render error(status,message) } catch(Throwable $ex){ http_response_code(500); no-store; render error(500, An unexpected error occurred.) }` — never echoes stack/exception message. Success also sets `no-store` if `route.noStore||error`. | `index.php: top try/catch` |
| **Prototype `api.php`** | `set_exception_handler` → `error_log` + `500 {success:false, code:SETUP_INCOMPLETE|SERVER_ERROR, message: setup vs generic}`; pdoCode `42S22|42S02` → setup message. No stack in response. | `backend/api.php: set_exception_handler` |
| **Logging** | Both live/log & prototype `error_log` never include token/hash/payload. Live `Logger.sanitize` redacts before file write. | `Logger.php: blocked keys` |

**UI mapping (VERIFIED):** `activation_pending` → `303 /activation`. `429 + retry_after` → `Too many sign-in attempts. Please wait X seconds.` Other `HttpException` → `viewData['error']` escaped in card (`fk-alert-error`). JSON device errors → `http_response_code(status)` + `json {error:message}` (caught by JS `statusEl.textContent`).

**Silent failures:** `AuthRepository::logout` catch `Throwable ignored` — always `SessionManager::clear()`.

---

## R — Dependency Graph

```
index.php
 ├─► bootstrap.php (pulls 10 requires in order)
 │     HttpException → Config → Logger → ApiClient → AuthRepository → AcmRepository → ProfileRepository
 │                                      → SessionManager → AuthGuard → Template → Router
 ├─► Router (no deps)
 ├─► Template (uses HttpException)
 ├─► SessionManager (uses $_SESSION / crypto random)
 ├─► Logger (uses fs)
 ├─► AuthGuard (uses SessionManager + Logger + HttpException)
 ├─► Config (uses HttpException)
 └─► Repository/
       ApiClient (uses HttpException)
       AuthRepository (uses ApiClient + HttpException + flock on storage/locks)
       AcmRepository (uses ApiClient)
       ProfileRepository (uses ApiClient)

templates/layouts/base.php ◄── Template::render
templates/pages/*           ◄── Template::render
assets/css/*  ─► base.php   (link)
assets/js/*   ─► base.php   (script defer) + activation.php inline <script> uses FKSession

Storage runtime out-edges:
  Logger ─► storage/logs/auth.log
  AuthRepository ─► storage/locks/refresh_*.lock

External edge:
  ApiClient ──(curl)→ Platform /api/v1 (no reverse dependency)

Prototype isolated sub-graph (no edge to live):
  backend/router.php ─► backend/api.php ─► backend/lib/security.php ─► backend/config/database.php (PDO)
                           │            ├─► backend/api/auth_staff.php
                           │            ├─► backend/api/learning.php ─► data/verses.json etc.
                           │            ├─► backend/api/extras.php
                           │            └─► backend/api/quiz.php|question.php
  frontend/*.html ─► css/fk-theme.css|style.css + js/fk-ui.js|session.js (old)
  database/migrations/*.sql ─► felege_kidusan_test
  tests/frontend-mock/* ─► isolated mock servers (8766 etc.)
```

**Dependents (reverse):** `$_SESSION['fk_auth']` shape is used by `SessionManager`, `AuthGuard`, `index.php` (reads `user['role']`), `AcmRepository` grant flow carries `idempotencyKey` between requests via hidden form field (not dependency). No class imports `Service/` — graph leaf is empty.

---

## S — Potential Problems (no fixes applied)

**Severity key:** 🔴 security / data integrity, 🟠 UX or availability, 🟡 maintainability/debt.

### Live app

1. 🟠 **No token refresh path exercised.** `SessionManager::isAccessTokenExpired()` and `AuthRepository::refresh()` (with `flock`) exist but `index.php` never checks `isAccessTokenExpired()` before `requireRole` or `getMe`. After 15 min the Bearer will be 401; user sees an error page instead of a silent refresh. **Evidence:** `index.php: /profile` calls `getMe(accessToken)` with no expiry guard nor retry on `401/409`. `SessionManager::isAccessTokenExpired` has zero callers (grep). **Impact:** session appears to expire abruptly.
2. 🔴 **DPoP not applied.** `ProfileRepository` accepts `dpopProof` but `index.php:/profile` passes `accessToken` with `null` dpop. If login ever sets `installation_id` (not today — `AuthRepository::login` is called with only `username,password`), platform requires `DPOP: ath…` per request or returns `DEVICE_PROOF_REQUIRED` → 401. **Evidence:** `index.php: $profileRepo->getMe($accessToken)` no third arg; `AuthRepository::login` call omits installationId/deviceFingerprint. No `accessTokenHash()` use.
3. 🟠 **Missing parent path.** `AuthGuard` only allows `member`; `parent` → `403 You do not have permission…` on `/profile` and `/logout`. Handbook lists `parent` as first-class actor (`/users/me/children`, `/members/{id}`). **Evidence:** `index.php: requireRole(['member'])` hard-coded.
4. 🟠 **Incomplete `HttpException` → UX branch coverage.** `index.php` handles `403 activation_pending` → `303 /activation` and `429 retry_after`; other codes (`REFRESH_REUSED, SESSION_REVOKED, PROFILE_CLAIMS_CHANGED, DEVICE_PROOF_INVALID`) are logged as `login_failed` but surface as generic `getMessage()` — user sees raw platform text. `PROFILE_CLAIMS_CHANGED 409` on `updateMe` is caught as generic `error` without hinting to refresh/retry.
5. 🟡 **Hidden field `grant` is replayable within window.** `grant` returned from `verifyCode` is forwarded via hidden `<input grant>` and reused for `setCredentials` and `begin`. No binding to session/CSRF beyond the CSRF token check — CSRF mitigates but grant theft via XSS would be usable. **Evidence:** `templates/pages/activation.php: <input name="grant" value="<?=e(grant)?>">`.
6. 🟠 **`idempotencyKey` lifecycle fragile.** Generated `bin2hex(random_bytes(16))` on `verify` success; re-rendered on `credentials` error via `viewData['idempotencyKey']=$idempotencyKey` (echoes submitted one). But a forward navigation to `/activation` GET clears state (`viewData['step']=verify` default) — no persistence; double-submit after `credentials` success could re-POST with same grant+key correctly (idempotent per ACM) but UX confuses. **Evidence:** `index.php: credentials` catch sets `idempotencyKey=idempotencyKey` but new verify generates fresh.
7. 🟡 **No `Service/` use, no `MemberRepository`.** `src/Service/.gitkeep` empty; parent/attendance screens have no Repository. Handbook First Tasks §4 anticipated `MembersRepository.php` for `/members/{id}` + `ApiClient` pagination. Absence is expected per open decisions but tracked as debt.
8. 🟡 **Logger file not rotated.** `Logger` appends `auth.log` forever under `storage/logs/` with `0750` mkdir and `LOCK_EX` — but no size cap, no rotation, no truncation. On long-lived host, disk can fill and `file_put_contents` silently fails (`@`). **Evidence:** `Logger.php:@file_put_contents` ignores return.
9. 🟡 **Styling divergence.** `assets/css/fk-theme.css` (1058) + `style.css` (2444) are copies of prototype's `frontend/css/*` (verified identical sizes). Live pages `sign-in`, `activation`, `profile` reuse `fk-card` classes but prototype `style.css` also contains rules for lessons/quiz/exam-prep/progress/tasks that no live page renders — dead CSS (~70% unused). No purge.
10. 🟠 **Route `noStore` coverage gap.** Only `/logout→true` (protected) and `/activation→true`, `/profile→true` set `noStore`; `/sign-in` is `noStore=false`. After failed sign-in with `error`, `index.php` sets `no-store` anyway (`!empty(viewData['error'])`), but successful anonymous `GET /sign-in` is cacheable by proxies (contains no PII, risk low — but after logout redirect to `/sign-in` with `csrfToken` in HTML? Token in HTML is cacheable if proxy caches — mitigated by token being per-session random but still `no-store` would be safer.)

### Prototype (archived — defects in record, not to be fixed here, but impact if adopted)

11. 🔴 **No CSRF originally** (`docs/HANDOVER_FOR_DEVELOPER.md: Known gaps: No CSRF token`). `fk_gate` relies on `SameSite=Lax` + JSON-only only — insufficient for `POST` with `Content-Type: application/json` in modern browsers (still bypassable via form+text/plain). Live app demonstrates correct CSRF; prototype did not.
12. 🔴 **Path traversal on uploads:** `backend/router.php` `realpath` check partially mitigates but `api/extras.php` also serves `uploads/` files via `past-papers/{id}/file` with name `file_path` from DB; mitigation `realpath`+`strncmp` present but `uploads/README.txt` check not verified writable dir perms.
13. 🟠 **Client-supplied `student_id` in URLs/bodies accepted** — gate fixes it with `hash_equals(session.id, requested/claimed)` but early `quiz.php` versions (backup) lacked it; `api-backup-before-quiz.php` still present as archive shows drift.
14. 🔴 **Password storage format heterogeneity** — `check_setup.php` notes old accounts with another format must be reset; `password_verify` only succeeds for bcrypt/argon2.
15. 🟠 **Table collisions if migrated to platform DB** — `attendance`, `notifications` exist in platform with different columns; migrations 002/003 would corrupt live data (`docs/ARCHIVE.md: Why archived`).
16. 🟡 **Unused debris:** `api/auth/register.php`, `api/lessons/get.php|unlock.php`, `api-backup-before-quiz.php`, `server.js`, `test_db*.php`, `data/lessons.json` — never referenced; `Handover` lists for removal.
17. 🟠 **Frontend i18n not native-reviewed** — `fk-ui.js` `FK.addStrings` Amharic (`ትምህርተ…`) AI-generated (`Handover: Amharic wording AI, have native speaker review`). `verses.json` and `lesson_text_grade10.json` also need proofreading (`lesson_text_for_proofreading.md` notes PDF font shift `ዘ ዙ …` repaired by rule).
18. 🟠 **No upload grade restriction on generic `/uploads/*`** — any signed-in user can fetch any path (only `past-papers` checks `grade`).

---

## T — Unknowns

| # | Unknown | Why not verifiable |
|---|---|---|
| 1 | Platform `/api/v1` actual host/port in production, CORS allow-list, `SITE_DOMAIN` value (`felegekidusan.arkeonethiopia.com` is fallback) | Private env `school_config.php` not in repo; `docs/05 §5` marks `Unverified` |
| 2 | `member` self-path shape (`/members/me` vs `apiMemberCanSeeSelf`) — whether platform will add it | `docs/05 §2` blocked, platform decision owed |
| 3 | Prototype lessons/quizzes data model for production (subjects/chapters/lessons columns, `status=published` etc. exist in test DB but not platform) | Platform has no lessons tables; `docs/05 §1` decision owed |
| 4 | `network_denied` enforcement on ACM `selector` redemption in production (IP whitelist) | Seeds read; production DB not available (`docs/05 §3`) |
| 5 | `profile_image` handling (`GET/POST/DELETE /users/me/profile-image`) payload limits, media types | Not wired in live app, no verification in this repo |
| 6 | Refresh skew/TL values (`jti TTL 900s`, `skew 300s`, `login 10/m`, etc. — default seeded) | Verified in docs from code snapshot, but prod may differ |
| 7 | Actual production PHP version (shared host runs both 8.4 and 8.5 per CI matrix, but host selection unknown) | `docs/06 §2` deployment target unknown |
| 8 | Subdomain name + HTTPS + `open_basedir` + `.htaccess` allowed (Apache vs nginx) | `docs/05 §6` unknown |
| 9 | Whether `Member` reports `profile_image` as absolute (`SITE_URL`) or root-relative in prod | `docs/03 §4.4` states either; not verifiable offline |
| 10 | Timing of prototype demo data (`felege_kidusan_test` seed content, user accounts) — no dump in repo beyond `lesson_text_grade10.json` (heavy but unreadable here) | Only JSON preview seen |
| 11 | Whether live platform enables `DPoP` per environment (bound vs unbound) — demo tokens assumed unbound | Live `index.php` passes no installation_id; inferred unbound |
| 12 | Platform's `attendance-risk`, `department-status` endpoints' PII roles and exact fields | Not called; handbook says PII roles, no verification done |

---

## U — Documentation Mismatches

| Doc claim | Implementation | Verdict |
|---|---|---|
| `README.md: Status Folder structure only — no behaviour yet.` | `index.php` is 254 lines with full sign-in/activation/profile/logout → four working pages, CSRF, audit log, three repositories. | **MISMATCH — docs stale after PR #6.** README `48 lines` reflects pre-PR skeleton; should say *Phase 1 auth + profile shipped, lessons/quiz not yet*. |
| `docs/00-START-HERE.md: There is no entry point yet (that is the next task), so there is nothing to run today.` | `index.php` is entry point, reachable at `/` and via rewrite. | **MISMATCH — same staleness.** `00-START-HERE` § Get running says nothing to run; now `php -S localhost:8000 -t .` serves `/`, `/sign-in`, `/activation`, `/profile`. |
| `docs/06-FIRST-TASKS.md §3 Frontend scaffold — session.js must be replaced, not adapted.` | Done — `assets/js/session.js` is replaced (ECDSA P-256 + IndexedDB + `rawToDer`), prototype's `frontend/js/session.js` is archived only. | **MATCH — implemented exactly as recommended.** |
| `docs/02-FOLDER-STRUCTURE.md §5 Entry files at repository root` rationale + `docs/HANDBOOK.md correction 1` `There is no /members/* route on platform` | Implemented — `index.php` at root, no `public/` layer, `Router` has no `/members/*`, `.htaccess` rewrites to `index.php`. | **MATCH.** |
| `docs/03 §8 Endpoint reference lists 20 endpoints` | Live `Repository` implements only ~8 (login/verify/logout/acm 4/profile 3). Parents/members routes not yet Repository. | **PARTIAL — docs list what exists, not what is wired.** Not a contradiction, but handbook should flag wiring progress. |
| `docs/04-CONVENTIONS.md §5 Configuration: MEMBERS_API_BASE=https://… + MEMBERS_ENV=production … bootstrap must not guess a base URL` | Implemented as `Config` throws 500 if missing, no fallback, `example` at `.fkss_env.example.php` = `https://example.com/api/v1`. | **MATCH.** |
| `docs/04 §6 Testing: tests next to what they test or top-level tests/… JSON line PASS` | Zero tests in repo; CI only `php -l`. | **MISMATCH by omission — recommended but not done.** Not claimed as done; absence noted. |
| `HANDOVER_FOR_DEVELOPER.md: NOTHING here has been run against a real PHP/MySQL server by the author` | True — `Handover` is prototype note, not live. No claim otherwise. | **MATCH.** |

---

## V — Change Impact Map

| If you change … | You must revisit … | Why |
|---|---|---|
| `src/Repository/ApiClient.php` (headers, timeout, envelope, `Retry-After`) | **Every** `*Repository`, `index.php` error handling, `Logger`, platform contract version | Envelope is the only contract; a narrowing change (e.g. stricter `status` check) breaks all auth/ACM/profile calls. Status `429/503` branching in `index.php` depends on `publicPayload.retry_after`. |
| `src/Auth/SessionManager.php` (cookie name `FKSS_MEMBER_SID`, params, `SESSION_KEY`) | `src/Auth/AuthGuard`, `index.php` session lifecycle, `assets/js/session.js` (if sharing cookie), any proxy | Name/max-age/SameSite change invalidates existing sessions; `setAuth` shape change breaks `isAuthenticated/accessToken/user` consumers. |
| `src/Auth/AuthGuard.php` (`requireRole`) | `index.php` guards on `/profile`, `/logout`, any new protected route, `Logger` | Role logic is the only server-side authorization; widening to `parent` changes accessibility; narrowing breaks `profile`. |
| `src/Http/Router.php` (`ROUTES` finite table) | `index.php` `if($path===…)` branches, `Template` names, `templates/pages/*` files, `.htaccess` | New route needs template + guard + viewData; removing a route changes 404 semantics; `noStore/protected` flags drive cache and auth flow. |
| `src/Repository/AuthRepository.php` (`login/refresh` payload) | `index.php: sign-in` form fields, platform `/auth/login` contract, `SessionManager::setAuth` expectations | Adding `installation_id` flips `device_bound` → DPoP path; lock dir path change affects concurrent refresh safety. |
| `src/Repository/ProfileRepository.php` (paths `/users/me*`) | `index.php:/profile` profile+password forms, `Template` profile card, platform `authorization_version` flow | `PATCH /users/me` requires `profile_version` optimistic concurrency; changing param shape breaks profile save. |
| `src/Support/Config.php` (`FKSS_API_BASE_URL`) | `index.php` boot, all repositories, deployment provisioning | Base URL missing → 500 on first request; host change → CORS/proxy reconsideration. |
| `src/View/Template.php` (`e` escaper) | **Every** `templates/pages/*`, `layouts/base.php` | Escaper change affects XSS posture; page/layout name regex change affects 404 surface. |
| `templates/layouts/base.php` (nav, header, csrf form) | Every page (layout wraps), `index.php viewData` (`title, activeRoute, user, csrfToken`), `assets/css/js` | Nav link change needs `activeRoute` handling; logout form csrf input name `csrf_token` is hard-coded in `index.php` checks. |
| `index.php` dispatcher (handler order, redirect codes) | All routes, all Repositories, Session, Template | First thing that runs; order of `if($path===)` + `requireRole` decides auth boundary; swallow of `Throwable` determines generic 500 message; `303` vs `302` affects history. |
| `assets/js/session.js` (`rawToDer`, `getOrCreateDeviceIdentity`, `createDpopProof`) | `templates/pages/activation.php` inline `<script>` (`signChallenge`, `exportSpkiBase64`), platform `DeviceCrypto::verify()` expects DER | DER encoding bug → `completeDeviceRegistration` 403 `DEVICE_PROOF_INVALID` with no oracle; DB key lifetime affects ceremony retries. |
| `assets/css/*` (`fk-theme.css` tokens) | `templates/*` markup (class names), visual regression surface | Token rename (e.g. `--fk-wine`) recompiles entire theme; deleting from prototype `style.css` removes dead rules but may break unseen pages. |
| `.htaccess` (rewrites, blocklist) | `index.php` dispatch, `storage/logs` access, `docs/` isolation, `assets/*` static serve | Adding a new top-level dir must be blocked if sensitive; removing `RewriteRule ^(src|templates|docs|storage)/ - [F,L]` would expose PHP source. |
| `docs/prototypes/**` (any file) | **Nothing live** — but CI lints `git ls-files '*.php'` includes it; platform collateral | Changing prototype files should not land in live branch; but adding a file that looks like `api/*` could confuse search/IDE; `.htaccess` does not block `/docs` from API introspection though. |
| Platform `api/v1` contract (`/auth/login` shape, `envelope`, error `code`s) | `ApiClient`, all Repositories, `index.php` branches (`activation_pending`, `retry_after`), `SessionManager` user shape | Platform add-field is safe (ignored), removing/renaming is breaking; `device_bound` flip enables DPoP path; adding member self-endpoint would unlock whole parent/child screen set. |

---

## Completeness Checklist

- [x] Repository structure — recursive inventory §C (root, `src`, `templates`, `assets`, `storage`, `docs`, `docs/prototypes/...` four layers deep)
- [x] Technology stack — verified from manifests/imports/config/source/lock absence §B
- [x] Entry points — single `index.php` dispatcher traced §D.3, §G.1
- [x] Application architecture — both live and archived diagrams + lifecycles §D
- [x] Modules — six live + prototype §E
- [x] Important files — file-by-file table ~40 entries §F
- [x] Functions/Classes/Components — per-class method list + prototype functions §G
- [x] Hooks/Services — `AuthGuard/SessionManager` analyzed (no generic hooks, vanilla)
- [x] Controller/Route map — finite `Router` + handler branches in `index.php` §G, §I
- [x] Middleware — `AuthGuard.requireRole`, `verifyCsrfToken` per POST — not generic middleware stack
- [x] Database — live none, prototype migrations 001–003 dissected, FKs/indexes §H
- [x] Models — no ORM; platform members implied, prototype students/chapters/lessons §H.3
- [x] Migrations/Seed — three + three rollbacks + demo SQL verbatim §H.3
- [x] APIs — Platform `/api/v1` 20-row table, prototype `/api` ~29 paths, envelope grammar §I
- [x] Authentication — login/refresh/logout + single-flight + DPoP + ACM ceremony §J.1
- [x] Authorization — ACL allow-lists + live guard + prototype `fk_gate` + `fk_staff_can_manage` §J.2
- [x] State management — cookie/session/csrf/grant/device/IndexedDB/logs §K
- [x] Business rules — unlock engine, attendance percent, notifications etc. §L
- [x] Status/state transitions — ACM, lesson, token family, student active/must_change §N
- [x] Error handling — per-layer strategy + UI mapping + silent failures §Q
- [x] External integrations — platform, mobile app reference, dashboards writer §O
- [x] Configuration — env file, defaults, failure mode §P
- [x] Background jobs / Scheduled tasks / Webhooks — **VERIFIED none** (no queue, cron, webhook handler; stated explicitly)
- [x] Scheduled jobs / Queues — **VERIFIED none**
- [x] Caches — only browser HTTP `Cache-Control: no-store` on protected/error; no app cache
- [x] File/storage operations — `storage/logs/auth.log`, `storage/locks/*.lock`, `uploads/` prototype serving
- [x] Dependency relationships — dependency graph §R
- [x] Data flows / Control flows — lifecycles + state maps §M, §D.3
- [x] Security boundaries — token in session not URL, sanitized logs, CsRF, HttpException separation, blocked `docs/src` §Q + §S
- [x] Potential inconsistencies — 18 items §S
- [x] Unknown/missing dependencies — 12 items §T
- [x] Documentation mismatches — 8 rows §U
- [x] Change impact map — 15 rows §V

---

## Confidence Classification Summary

| Finding | Confidence | Basis |
|---|---|---|
| Live app has one entry `index.php` + five repositories + two auth classes + router+template; no DB; 4 pages | **VERIFIED** | Read each file end-to-end (lines cited) |
| Prototype backend is not adopted, blocked by `.htaccess` + `bootstrap` isolation | **VERIFIED** | `docs/ARCHIVE.md`, `.htaccess`, `bootstrap` import list |
| Browser can complete ACM with WebCrypto + `rawToDer` | **VERIFIED** | `assets/js/session.js` 168 lines + `AcmRepository` 4 methods + handbook verification note |
| Member has no self-endpoint; parent has `safe row` + attendance | **VERIFIED** (live gap) / **INFERENCE** (safe row fields) | `docs/03 §4` from platform source `d7d27c1` but not cross-checked against live platform DB in this checkout |
| CORS blocks subdomain | **VERIFIED** (platform code described) / **INFERENCE** (actual production host) | `docs/01 §6` cites `handleCors()` allow-list; production `SITE_DOMAIN` unknown |
| Refresh single-flight needed but not exercised | **VERIFIED** | `AuthRepository::refresh` flock vs `index.php` zero callers of `isAccessTokenExpired` |
| DPoP required only when `device_bound=true` | **VERIFIED** (mechanic) | `docs/03 §6.5` + `ApiClient` `DPOP:` header support |
| Security: tokens never in URL/log/template | **VERIFIED** | `SessionManager` storage + `Logger.sanitize` blocked keys + `Template.escape` |
| No queue/cron/webhook | **VERIFIED** | `grep -R queue\|cron\|webhook` only in docs/handover text, no runtime code |

---

### Evidence standard compliance

Every section above answers *“Where in the source did you verify this?”* with file path + symbol (function/class) or line range. Claims imported from `docs/03-API-INTEGRATION.md` are marked where they depend on platform source outside this repository (`INFERENCE — requires verification against `fkss-sunday-school-platform@d7d27c1``). No assumption is presented as fact; no modification was made to source behavior; no fix was applied.

**Next-step recommendation (not a change):** Before adding member lessons/quiz screens, resolve open decisions `05 §1` (data model owner) and `05 §2` (member authorization on platform), and wire the missing `Service/MemberRepository` + token refresh before expiry + `parent` guard per §S.1–S.3. Keep this report alongside the handbook — update it when those decisions land rather than adding a parallel doc.

---

*Generated for `arena/dfd990da-ssms-member-app` from a full read of 104 files (25,390 insertions at base). No source file was edited; this report is the only additive artifact.*
