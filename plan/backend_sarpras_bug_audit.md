# Backend Sarpras — Bug Audit: CORS / Failed to Fetch on `/{id}` endpoints

**Date:** 2026-08-06
**Mode:** DEBUG (read-only investigation)
**Symptom:** Flutter client receives CORS/Failed to fetch when hitting `/api/v1/logistik/sarpras/{id}` — likely DELETE or update requests.

---

## 1. Findings Summary

| # | Severity | Finding | Effect |
|---|----------|---------|--------|
| A | 🔴 HIGH | **No `PUT` route exists** — but preflight advertises `PUT` | Browser allows PUT, then gets CI3 404 with ZERO CORS headers → "Failed to fetch" |
| B | 🟡 MEDIUM | **Content-Type gate blocks JSON-only updates** | Flutter sending `POST /{id}` with `application/json` for a field-only (no photo) update gets 415 |
| C | 🟢 LOW | **No `PATCH` route** (same class as PUT) | Minor variant of A |
| D | ✅ OK | Table/column names, SQL, `generate_uuid4()`, file unlink logic | All correct — no typos, no fatal-crash paths |

---

## 2. Issue A: PUT Preflight Passes, But No PUT Route Exists 🔴

### The Mechanic

1. Flutter sends `PUT /api/v1/logistik/sarpras/{id}` (standard REST convention for updates).
2. Browser preflights: `OPTIONS /api/v1/logistik/sarpras/{id}` with `Access-Control-Request-Method: PUT`.
3. Route line 103 catches the OPTIONS → `sarpras_options()` → controller constructor runs → emits:
   ```
   Access-Control-Allow-Origin: *
   Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS
   ```
   **PUT is explicitly allowed** in the constructor's hardcoded header.
4. Browser: "PUT is allowed, proceed." Sends actual `PUT` request.
5. CI3 Router iterates routes:
   - Line 98: `POST` — no
   - Line 99: `(:any) POST` — no
   - Line 100: `GET` — no
   - Line 101: `(:any) DELETE` — no
   - Line 102: `OPTIONS` — no
   - Line 103: `(:any) OPTIONS` — no
   - **No match.** Fall through to CI3 default `show_404()`.
6. CI3's 404 handler emits an HTML error page. **The `Logistik` controller was never instantiated** — no `Access-Control-Allow-Origin: *` header exists.
7. Browser: "Response has no CORS header → block it → `TypeError: Failed to fetch`."

### Why This Didn't Fail in Our E2E Test

Our `curl` smoke test (build report) tested ONLY the defined methods:
- `POST /api/v1/logistik/sarpras` ✅ (create)
- `POST /api/v1/logistik/sarpras/{id}` ✅ (update — using POST, our design)
- `DELETE /api/v1/logistik/sarpras/{id}` ✅
- `OPTIONS` ✅

We never sent `PUT`. `curl` also doesn't enforce CORS. The Flutter browser client does — and the Flutter `http` / `dio` package likely uses `PUT` for updates by convention.

### Evidence

**routes.php lines 98-103** (the entire sarpras block):
```php
$route['api/v1/logistik/sarpras']['POST'] = 'logistik/sarpras_post';
$route['api/v1/logistik/sarpras/(:any)']['POST'] = 'logistik/sarpras_post/$1';
$route['api/v1/logistik/sarpras']['GET'] = 'logistik/sarpras_get';
$route['api/v1/logistik/sarpras/(:any)']['DELETE'] = 'logistik/sarpras_delete/$1';
$route['api/v1/logistik/sarpras']['OPTIONS'] = 'logistik/sarpras_options';
$route['api/v1/logistik/sarpras/(:any)']['OPTIONS'] = 'logistik/sarpras_options';
```
0 `PUT` routes.

**Constructor (Logistik.php:9):**
```php
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
```
Advertises `PUT` and `PATCH` — both unhandled for sarpras.

### Fix Options

**Option 1 (recommended):** Add a PUT route that delegates to the same handler. Since PHP doesn't populate `$_FILES` on PUT, this route can only handle **field-only updates (no photo)**. Detect `$_FILES` absence and either accept JSON body (for field-only) or return a clear error.

```php
$route['api/v1/logistik/sarpras/(:any)']['PUT'] = 'logistik/sarpras_put/$1';
```

Requires a new `sarpras_put($id)` method that:
- Reads JSON body (not multipart)
- Validates the same fields as the update path in `sarpras_post`
- Does NOT support file uploads (422 if `$_FILES` present)

**Option 2:** If the Flutter client can be changed to use `POST /{id}` for updates (matching our documented contract), no backend change is needed — but the Allow-Methods header should still be tightened to not advertise false capabilities.

**Option 3 (quick fix):** Remove `PUT` and `PATCH` from the constructor's `Allow-Methods` header so the browser never attempts them. This pushes the error to the Flutter side earlier (preflight fails), preventing the cryptic "Failed to fetch."

---

## 3. Issue B: JSON Updates Rejected by Content-Type Gate 🟡

### The Mechanic

`sarpras_post()` at line 1083-1092:
```php
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($content_type, 'application/json') !== false) {
    // 415: "Content-Type harus multipart/form-data..."
}
```

If Flutter sends `POST /api/v1/logistik/sarpras/{id}` with `Content-Type: application/json` for a field-only update (no photo), the gate rejects it with 415.

**This does NOT cause a CORS error** (constructor already emitted CORS headers, the 415 JSON response includes them). But the Flutter client gets a confusing "multipart/form-data required" error when it's just updating `nama_barang` or `kondisi`.

### Fix Options

**Option 1:** Allow `application/json` through the gate when `$id` is present AND no file is being uploaded (field-only update). Fall back to `json_decode($this->input->raw_input_stream)` for the field values.

**Option 2:** Keep the gate but document clearly that ALL POST requests (create and update) require `multipart/form-data` — even if no file is included. Flutter's `http.MultipartRequest` works without files attached.

---

## 4. Issue C: Same Class — PATCH Route 🟢

The constructor also advertises `PATCH` in `Allow-Methods`. The same mechanism as Issue A applies: PATCH preflight passes, but no PATCH route exists → CI3 404 → CORS error. Same fix approach as Issue A, but lower priority since Flutter is unlikely to use PATCH for this module.

---

## 5. Verified-NOT-Bug Items ✅

| Check | Result |
|-------|--------|
| Table name `tbl_sarpras` in all queries | ✅ Correct (`DELETE FROM tbl_sarpras`, `INSERT INTO tbl_sarpras`, `UPDATE tbl_sarpras`) |
| PK column `sarpras_id` in all WHERE clauses | ✅ Correct |
| Schema columns match INSERT (9 columns) | ✅ All match: `sarpras_id, polda_id, kode_barang, nama_barang, kategori, kondisi, tahun_pengadaan, foto_url, created_at` |
| `generate_uuid4()` helper exists and is loaded | ✅ `uuid_helper.php:5`, loaded in constructor at line 19 |
| `FCPATH` usage in `unlink()` | ✅ Defined in `index.php`, resolves to project root |
| File path saved as `uploads/sarpras/<encrypted>.webp` | ✅ Line 1245: `$foto_url = 'uploads/sarpras/' . $upload_data['file_name'];` |
| `updated_at = NOW()` in UPDATE | ✅ Column exists (`datetime DEFAULT NULL ON UPDATE current_timestamp()`) |
| `$set` array built with `escape_str()` | ✅ All user values escape single-quotes |
| Rollback: file deleted on INSERT/UPDATE failure | ✅ `@unlink(FCPATH . $foto_url)` at lines 1270 and 1317 |
| DELETE also cleans up local photo | ✅ Line 1382-1383, guarded by `uploads/` prefix check |
| OPTIONS wildcard `(:any)` route exists | ✅ Line 103 — covers preflight for `/sarpras/{id}` |
| `row_array()` → falsy check for 404 | ✅ Empty array from `->row_array()` when no rows returned is falsy |

---

## 6. Root Cause Verdict

The "CORS/Failed to fetch" error on `/api/v1/logistik/sarpras/{id}` is almost certainly **Issue A**: the Flutter client is sending `PUT` for updates (standard REST convention), which passes the OPTIONS preflight (because the constructor advertises `PUT` in `Allow-Methods`) but then gets a CI3 default 404 page with **zero CORS headers** — because no `PUT` route exists for sarpras, so the `Logistik` controller (and its CORS-emitting constructor) never runs.

The same mechanism applies for `PATCH` (Issue C) and would apply for `DELETE` if the route table somehow missed it — but `DELETE` IS defined at line 101, so DELETE works.
