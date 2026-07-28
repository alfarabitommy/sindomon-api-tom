# STEP 1: Static Code Sweep & Stability Check — Execution Plan

## Objective
Audit all existing CodeIgniter 3 routes, controllers, and Playwright tests before generating the Postman Collection. Verify JSON envelope compliance (`{"status": INT, "message": "STRING", "data": {}}`), JWT auth patterns, and test suite stability.

---

## Phase 1: Route & Controller Extraction

### 1a. Parse `routes.php` — extract every registered API route
Source: `application/config/routes.php`

| # | HTTP Method | Route Pattern | Controller Method | Exists in Controller? |
|---|------------|--------------|-------------------|----------------------|
| 1 | ANY | `/api/v1/auth/insert` | `auth/insert_user` | Yes (Auth.php:44) |
| 2 | ANY | `/api/v1/auth/login` | `auth/login` | Yes (Auth.php:16) |
| 3 | ANY | `/api/v1/user` | `auth/all` | Yes (Auth.php:57) |
| 4 | GET | `/api/v1/role` | `role/get` | Yes (Role.php:33) |
| 5 | POST | `/api/v1/role` | `role/post` | Yes (Role.php:51) |
| 6 | PUT | `/api/v1/role` | `role/put` | Yes (Role.php:68) |
| 7 | DELETE | `/api/v1/role` | `role/delete` | Yes (Role.php:85) |
| 8 | GET | `/api/v1/profile` | `profile/get` | Yes (Profile.php:25) |
| 9 | GET | `/api/v1/polda` | `polda/get` | Yes (Polda.php:27) |
| 10 | GET | `/api/v1/pengaduan/tiket` | `pengaduan/tiket` | Yes (Pengaduan.php:36) |
| 11 | PATCH | `/api/v1/pengaduan/tiket/(num)/status` | `pengaduan/ubah_status/$1` | Yes (Pengaduan.php:118) |
| 12 | GET | `/api/v1/knowledge/dokumen` | `knowledge/dokumen` | Yes (Knowledge.php:33) |
| 13 | POST | `/api/v1/kamtibmas/laporan` | `kamtibmas/laporan` | Yes (Kamtibmas.php:40) |
| 14 | POST | `/api/v1/dms/surat` | `dms/surat` | Yes (Dms.php:41) |
| 15 | GET | `/api/v1/dms/surat` | `dms/inbox_outbox` | Yes (Dms.php:193) |
| 16 | GET | `/api/v1/dms/surat/(any)/download` | `dms/download/$1` | Yes (Dms.php:285) |
| 17 | PATCH | `/api/v1/dms/surat/(any)/read` | `dms/read/$1` | Yes (Dms.php:375) |
| 18 | POST | `/api/v1/logistik/senjata` | `logistik/senjata_post` | Yes (Logistik.php:32) |
| 19 | POST | `/api/v1/logistik/amunisi` | `logistik/amunisi_post` | Yes (Logistik.php:170) |
| 20 | GET | `/api/v1/logistik/amunisi` | `logistik/amunisi_get` | Yes (Logistik.php:263) |
| 21 | POST | `/api/v1/logistik/satwa` | `logistik/satwa_post` | Yes (Logistik.php:339) |
| 22 | GET | `/api/v1/sdm/org-tree` | `sdm/org_tree_get` | Yes (Sdm.php:33) |
| 23 | GET | `/api/v1/sdm/personil` | `sdm/personil_get` | Yes (Sdm.php:125) |
| 24 | POST | `/api/v1/sdm/personil` | `sdm/personil_post` | Yes (Sdm.php:240) |
| 25 | PUT | `/api/v1/sdm/personil/(any)` | `sdm/personil_put/$1` | Yes (Sdm.php:356) |
| 26 | POST | `/api/v1/sdm/hukum` | `sdm/hukum_post` | Yes (Sdm.php:467) |
| 27 | POST | `/api/v1/master/polres` | `master/polres_post` | Yes (Master.php:26) |
| 28 | PUT | `/api/v1/master/polres/(num)` | `master/polres_put/$1` | Yes (Master.php:84) |
| 29 | DELETE | `/api/v1/master/polres/(num)` | `master/polres_delete/$1` | Yes (Master.php:128) |

**Total: 29 registered route entries, 100% controller-method match.**

### 1b. JSON Envelope Compliance Audit
- **Standard envelope:** `{"status": INT, "message": "STRING", "data": {|[]}}`

| Controller | Status | Notes |
|------------|--------|-------|
| **Auth.php** | ❌ NOT COMPLIANT | `login()` uses `"jwt_token"` at root level instead of nested in `data`. `insert_user()` / `all()` ok. |
| **Role.php** | ❌ PARTIAL | `get()` returns `"Unauthorize"` string (not JSON) for auth failure. All methods return `data` but `post()`/`put()` lack `(object)[]` for empty. |
| **Profile.php** | ❌ BROKEN | `get()` sends raw `Authorization` header as DB token query param (security bug). Returns string `"Unauthorize"` instead of JSON envelope. |
| **Polda.php** | ❌ BROKEN | Returns string `"Unauthorize"`. Also a bug: the for-loop references `$data[0]` for every row (always reads row 0). |
| **All others** (Master, Sdm, Pengaduan, Knowledge, Kamtibmas, Dms, Logistik) | ✅ COMPLIANT | Full `status`/`message`/`data` envelope with `http_response_code()` / `$this->output->set_status_header()`. JWT extracted via `get_jwt_payload()` helper. |

### 1c. JWT Auth Pattern Audit

| Pattern | Controllers |
|---------|-------------|
| Uses modern `get_jwt_payload($this)` helper (Bearer + raw token) | Master, Sdm, Pengaduan, Knowledge, Kamtibmas, Dms, Logistik |
| Uses legacy `jwt_decode($authorization)` directly (Bearer-only, fragile) | Role, Polda |
| Uses raw `$headers['Authorization']` as DB token lookup (broken) | Profile |
| No JWT at all | Auth (login/register endpoints are public) |

---

## Phase 2: Playwright Stability Check

### Test files to execute
1. `tests/api/master_polres.spec.ts` — 9 tests (auth + CRUD + integrity traps for Master Polres)
2. `tests/api/sindomon_e2e.spec.ts` — 5 tests (auth JWT verification + SDM personil CRUD + hukum cross-region)

### Execution command (sequential, serial tests)
```bash
npx playwright test tests/api/ --reporter=list
```

### Pre-conditions
- MySQL running, database `sindomondb` exists with required tables
- PHP built-in server starts automatically via `playwright.config.ts` `webServer` config (port 8080)
- Seed script runs inside `sindomon_e2e.spec.ts` `beforeAll` hook via `php tests/seed.php`

### Expected outcomes
- All tests should pass (green)
- Max latency per request: 1000ms (enforced by `assertLatency`)
- JSON envelope check on every response via `assertEnvelope()`

### If failures occur, we will:
1. Read the failure output to determine root cause
2. Check if the seed script ran correctly (DB state)
3. Report which test(s) failed with the exact assertion message

---

## Phase 3: Audit Report Generation

After completing Phases 1–2, output a Markdown report with these sections:

### 1. Test Results
- Overall pass/fail status
- Number of tests passed / failed / skipped
- Latency compliance

### 2. Implemented Endpoints (Ready for Postman Collection)
Full list of 29 route entries grouped by Blueprint group:
- **Auth:** Login, Register (insert_user), All Users
- **Master Data:** Polres CRUD (POST/PUT/DELETE)
- **SDM:** Org Tree GET, Personil GET/POST/PUT, Hukum POST
- **Role:** GET/POST/PUT/DELETE
- **Profile:** GET
- **Polda:** GET
- **Pengaduan:** Tiket GET, Ubah Status PATCH
- **Knowledge:** Dokumen GET
- **Kamtibmas:** Laporan POST
- **DMS:** Surat POST, Inbox/Outbox GET, Download GET, Read PATCH
- **Logistik:** Senjata POST, Amunisi POST/GET, Satwa POST

### 3. Missing/Incomplete Endpoints (Blueprint Groups not in routes.php)
Based on standard police API blueprints, the following groups may be missing:
- **Group: Laporan & Analytics** — no `/api/v1/laporan/*` or `/api/v1/analytics/*` routes
- **Group: Command Center / Dashboard** — no aggregate/dashboard endpoints
- **Group: Penindakan / Gakkum** — no enforcement routes
- **Group: Intelijen** — no intelligence routes
- **Group: Public Service** — no public-facing endpoints

### 4. Discrepancies (Must Fix Before Postman Collection)
1. **Auth.php::login()** — `jwt_token` lives at root level, not in `data`. Flutter expects `{"status":200,"message":"success","data":{"jwt_token":"..."}}`. Fix.
2. **Role.php::get() / Role.php::delete()** — returns bare string `"Unauthorize"` instead of JSON envelope. Fix.
3. **Polda.php::get()** — row-0 bug: `$data[0]` hardcoded in loop. Returns string `"Unauthorize"`. Fix.
4. **Profile.php::get()** — broken auth: sends raw token as DB lookup. Returns string `"Unauthorize"`. Fix.
5. **General SQL Injection risk** — Auth, Role, Polda, Profile use raw string interpolation without `escape_str()` or query binding. Modern controllers (Master, Sdm, etc.) are better but still use manual escaping in some spots.

---

## Execution Steps (Ordered)

1. **Run Playwright tests** — `npx playwright test tests/api/ --reporter=list`
2. **Report test results** — pass/fail summary, any failures with assertion messages
3. **Cross-reference route table** — verify all 29 routes have matching controller methods
4. **Audit JSON envelope** — check each controller method for `status`/`message`/`data` structure
5. **Check JWT auth pattern** — note which controllers use legacy vs modern auth
6. **Identify missing Blueprint groups** — compare routes against expected API groups
7. **Compile Markdown audit report** — include all findings from steps 1–6
8. **Output final report path** — saved to `.kilo/plans/` or displayed in terminal

---

## Validation Criteria
- [ ] Playwright test suite passes with 0 failures
- [ ] All 29 route-to-controller mappings verified correct
- [ ] Every controller method's JSON response format documented
- [ ] Discrepancy list is exhaustive and actionable
- [ ] Report is ready for future Postman Collection generation
