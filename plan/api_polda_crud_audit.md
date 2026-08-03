# API Polda CRUD Audit — Edit (PUT) & Delete (DELETE)

> **Status:** Investigation Complete — Awaiting user review before any code changes
> **Date:** 2026-08-03
> **Scope:** `Master Wilayah` Polda endpoint — PUT and DELETE only

---

## 1. Documentation & Route Audit

### 1.1 `.docs/` Directory

The `.docs/` directory contains 4 PDF files — **no markdown-based API contracts or specs exist:**

| File | Size |
|------|------|
| `Detailed API Documentation v1.0.pdf` | 514 KB |
| `Detailed Entity Relationship Diagram (ERD) v1.0.pdf` | 216 KB |
| `Detailed Product Requirement Document (PRD) v1.0.pdf` | 490 KB |
| `Detailed UI_UX Specification Document v1.0.pdf` | 1.3 MB |

The API Documentation PDF (`Detailed API Documentation v1.0.pdf`) was extracted via `pdftotext`. It is titled **"API BLUEPRINT DOCUMENT"** and **Group 2: Master Data Module** defines these relevant endpoints:

| Endpoint | Doc Status | Implementation Status |
|----------|-----------|----------------------|
| `GET /api/v1/master/wilayah` | ✅ Documented (2.1) | ✅ Implemented |
| `POST /api/v1/master/polda` | ✅ Documented (2.2) | ✅ Implemented |
| `DELETE /api/v1/master/polda/{polda_id}` | ✅ Documented (2.4) | ✅ Implemented |
| `POST /api/v1/master/polres` | ✅ Documented (2.5) | ✅ Implemented |
| `PUT /api/v1/master/polres/{polres_id}` | ✅ Documented (2.6) | ✅ Implemented |
| `DELETE /api/v1/master/polres/{polres_id}` | ✅ Documented (2.7) | ✅ Implemented |
| **`PUT /api/v1/master/polda/{polda_id}`** | **❌ NOT DOCUMENTED** | **✅ Implemented** |

**Notable gap:** The API Blueprint document does NOT include a PUT/EDIT endpoint for Polda. The `polda_put()` implementation exists in `Master.php` and has a registered route, but was never formally specified in the API doc.

**Spec highlights from the API doc for DELETE Polda (Endpoint 2.4):**
- Title: "HAPUS MASTER POLDA — RESTRICTED DELETE VALIDATION"
- Success: 200 only if Polda has no relations (no child Polres)
- Conflict: **409** if Polda still has Polres jajaran (FK `ON DELETE RESTRICT` from `tbl_polres`)
- Also mentions 422 case
- Instructs catching the MariaDB SQL error instead of crashing — which the current implementation does via `$this->db->db_debug = FALSE` + error code 1451 check

**Spec highlights for POST Polda (Endpoint 2.2):**
- `nama_polda` String Wajib, **harus UNIQUE**
- Success returns `data: {"polda_id": 39}`
- Backend note: "don't let ORM round decimal lat/lng" (stored as VARCHAR, so no rounding issue)

**⚠️ Spec vs Implementation Gap:** The spec requires `nama_polda` to be UNIQUE. Neither `polda_post()` nor `polda_put()` enforce this — there is no unique constraint in the database and no duplicate-name check in the PHP code.

### 1.2 Existing Plan Files

The `plan/` directory contains two prior plans:
- `api_polda_debug_plan.md` — "API Polda — Latitude & Longitude Missing in Response". **Task 1 (add legacy route) is already executed** — `routes.php` line 67 now maps `$route['api/v1/polda']['GET'] = 'polda/get';`. **Task 2 (modernize Polda controller auth) and Task 3 (load JWT library in constructor) are NOT yet implemented.**
- `api_users_management_plan.md` — "User Management API — Edit & Soft Delete Endpoints". Appears fully executed (routes exist, `Auth.php` has `user_put`/`user_delete` with `is_active` soft delete).

### 1.3 Routes for Polda PUT & DELETE

**File: `application/config/routes.php` lines 69–72**

```php
$route['api/v1/master/polda']['GET']           = 'master/polda_get';
$route['api/v1/master/polda']['POST']          = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']    = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE'] = 'master/polda_delete/$1';
```

**Both PUT and DELETE routes ARE registered.** The URI pattern is:
- `PUT  /api/v1/master/polda/{id}` → `Master::polda_put($polda_id)`
- `DELETE /api/v1/master/polda/{id}` → `Master::polda_delete($polda_id)`

---

## 2. Controller Audit

**Controller: `application/controllers/Master.php`** (447 lines)

This is the canonical controller for all Polda/Polres/Wilayah CRUD. A second controller `Polda.php` exists but is **read-only** (only a `get()` method for the legacy Flutter `GET /api/v1/polda` endpoint).

### 2.1 `polda_put($polda_id)` — Lines 341–395

**Status: EXISTS and FUNCTIONAL**

```php
public function polda_put($polda_id)
{
    // 1. JWT Auth gate → 401 if token missing/invalid
    $payload = get_jwt_payload($this);

    // 2. Super Admin role gate → 403 if role_id !== 1
    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // 3. Existence check → 404 if not found
    $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
    if ($polda_exists === 0) {
        http_response_code(404);
        echo json_encode([...]);
        return;
    }

    // 4. Parse body + validation → 422 if nama_polda empty
    $input = json_decode($this->input->raw_input_stream, true);
    $nama_polda = trim($input['nama_polda'] ?? '');
    if ($nama_polda === '') {
        http_response_code(422);
        echo json_encode([...]);
        return;
    }

    // 5. Update (latitude/longitude optional, null if omitted)
    $latitude  = isset($input['latitude'])  ? trim($input['latitude'])  : null;
    $longitude = isset($input['longitude']) ? trim($input['longitude']) : null;

    $this->db->where('id', $polda_id)->update('tbl_polda', [
        'nama_polda' => $nama_polda,
        'latitude'   => $latitude,
        'longitude'  => $longitude
    ]);

    // 6. Success → 200
    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Data Polda berhasil diperbarui.',
        'data' => (object)[]
    ]);
}
```

**Observations:**
- Auth: ✅ Correctly uses `get_jwt_payload($this)` (project standard)
- Role gate: ✅ Super Admin only (`role_id !== 1`)
- Validation: ✅ `nama_polda` required, HTTP 422 if empty
- Update fields: `nama_polda`, `latitude`, `longitude`
- **Minor issue:** `latitude`/`longitude` silently set to `null` when omitted from the request body — no partial-update preservation of existing values
- **Minor issue:** No duplicate name check (unlike some other endpoints)
- Response envelope: ✅ Standard `{status, message, data}` with `(object)[]` for empty data

### 2.2 `polda_delete($polda_id)` — Lines 397–446

**Status: EXISTS and FUNCTIONAL**

```php
public function polda_delete($polda_id)
{
    // 1. JWT Auth gate → 401 if token missing/invalid
    $payload = get_jwt_payload($this);

    // 2. Super Admin role gate → 403 if role_id !== 1
    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([...]);
        return;
    }

    // 3. Existence check → 404 if not found
    $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
    if ($polda_exists === 0) {
        http_response_code(404);
        echo json_encode([...]);
        return;
    }

    // 4. Hard DELETE with FK guard
    $this->db->db_debug = FALSE;
    $this->db->delete('tbl_polda', ['id' => $polda_id]);
    $error = $this->db->error();
    $this->db->db_debug = TRUE;

    // 5. FK constraint violation → 409 (has child Polres rows)
    if ($error['code'] == 1451) {
        http_response_code(409);
        echo json_encode([
            'status' => 409,
            'message' => 'Polda tidak dapat dihapus karena masih menaungi Polres aktif (Restricted by System).',
            'data' => (object)[]
        ]);
        return;
    }

    // 6. Success → 200
    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Data Polda berhasil dihapus.',
        'data' => (object)[]
    ]);
}
```

**Observations:**
- Delete type: **HARD DELETE** — the row is physically removed from `tbl_polda`
- FK guard: MySQL error code 1451 (from `ON DELETE RESTRICT` on `fk_polres_polda`) returns HTTP 409
- No `is_active` column exists on `tbl_polda` — soft delete is not possible without schema changes
- **Minor issue:** No `affected_rows()` check after delete — if the row is deleted by a concurrent request between the existence check and the delete, it still returns 200 "berhasil dihapus"

### 2.3 Database Schema — `tbl_polda`

**Source: `application/controllers/Seeder.php` lines 51–58**

```sql
CREATE TABLE IF NOT EXISTS `tbl_polda` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `nama_polda` varchar(100) DEFAULT NULL,
    `latitude` varchar(100) DEFAULT NULL,
    `longitude` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

**Key facts:**
- ❌ No `is_active` column — hard delete is the only delete mechanism. Confirmed across ALL schema sources: `database/v5/sindomondb.sql` (lines 79-85), `database/v6/migrate_polres.sql`, and `Seeder::_ensure_tables()`. None define `is_active` on `tbl_polda` or `tbl_polres`.
- ❌ No `updated_at` column — no audit trail for edits
- ❌ No UNIQUE constraint on `nama_polda` — the API spec requires it, but neither the DB schema nor the PHP code enforce it
- The FK `fk_polres_polda` on `tbl_polres.polda_id` uses `ON DELETE RESTRICT` (added in `database/v6/migrate_polres.sql`), which is what triggers error 1451
- For comparison: `tbl_users` DOES have `is_active` — soft delete exists only for users (`Auth::user_delete()`), though ironically this column is also absent from all SQL dump files and appears to have been applied manually

---

## 3. Conclusion

### Bottom Line: Both endpoints ARE built and functional. We do NOT need to build from scratch.

| Aspect | `polda_put` | `polda_delete` |
|--------|------------|----------------|
| Exists? | ✅ Yes (line 341) | ✅ Yes (line 397) |
| Route registered? | ✅ Yes (route line 71) | ✅ Yes (route line 72) |
| Auth (JWT)? | ✅ `get_jwt_payload()` | ✅ `get_jwt_payload()` |
| Role gate? | ✅ Super Admin only | ✅ Super Admin only |
| Validation? | ✅ `nama_polda` required | ✅ Existence pre-check |
| FK guard? | N/A | ✅ Error 1451 → 409 |
| Delete type? | N/A | Hard delete |
| Response envelope? | ✅ Standard | ✅ Standard |

### Spec-vs-Implementation Gaps Found

| Gap | Severity | Detail |
|-----|----------|--------|
| `PUT /api/v1/master/polda/{id}` | Low | Implemented but NOT in the API Blueprint doc |
| `nama_polda` UNIQUE | **Medium** | Spec requires it; neither DB constraint nor PHP check enforces it |
| `is_active` soft delete | Low | Spec says nothing about soft vs hard delete; current hard delete + FK guard matches the documented 409 behavior |

### Whether to patch depends on what your requirements are:

1. **If the current behavior is acceptable** (hard delete, Super Admin only, no audit trail) → **No changes needed.** The endpoints are production-ready.

2. **If you want to close the spec gap on UNIQUE `nama_polda`** → **Small patch.** Add a unique index on `tbl_polda.nama_polda` via Seeder migration, and/or add a duplicate-name pre-check in `polda_post()`/`polda_put()`. The `nama_polda` field is `varchar(100)` which can hold a unique index.

3. **If you want soft delete** (set `is_active = 0` instead of hard delete) → **Larger patch.** This requires: adding an `is_active` column to `tbl_polda` (migration in Seeder via the INFORMATION_SCHEMA pattern), rewriting `polda_delete()`, updating `polda_get()`/`wilayah_get()` to filter `is_active = 1`, and updating `polda_post()` to set `is_active = 1` on insert.

4. **If you want minor hardening** → **Small patches.** Options include: adding `affected_rows` check to `polda_delete`, adding `updated_at` timestamp to `polda_put`, or preserving existing lat/lng values when omitted in PUT.

5. **If you want non-Super-Admin access** (e.g., Operator Polda editing their own Polda) → **Patch needed.** The current role gate is hardcoded to `role_id !== 1`.

The next step depends entirely on what behavior you want. Please review and let me know which direction to take.
