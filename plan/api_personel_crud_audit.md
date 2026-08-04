# API Personel CRUD Audit

> **Date:** 2026-08-04
> **Controller:** `application/controllers/Sdm.php` (630 lines)
> **Routes:** `application/config/routes.php` (lines 89-93)
> **Target Table:** `tbl_personil`

---

## 1. Controller Audit

### 1.1 Method Inventory

| Method | Lines | Route | Exists? |
|---|---|---|---|
| `personil_get()` | 125–232 | `GET /api/v1/sdm/personil` | ✅ |
| `personil_post()` | 240–347 | `POST /api/v1/sdm/personil` | ✅ |
| `personil_put($personil_id)` | 356–458 | `PUT /api/v1/sdm/personil/(:any)` | ✅ |
| `personil_delete($personil_id)` | — | — | ❌ **MISSING** |

### 1.2 `personil_get` — ✅ COMPLIANT

**Auth & Jurisdiction:**
- Operator (role_id=2): forcefully locked to JWT `polda_id` via `$this->db->where('p.polda_id', $jwt_polda_id)` (line 145). Client cannot override.
- Admin/Eksekutif (role_id=1/3): optional `?polda_id=` query param (lines 148-151).
- Other roles: 403 "Akses ditolak".

**Filters:**
- `?search=` — GROUPed LIKE on `p.nama_lengkap` OR `p.nrp` (lines 182-188) ✅
- `?polres_id=` — integer cast WHERE (lines 191-194) ✅
- `?status=` — whitelisted against `['Aktif', 'Mutasi', 'Pensiun']`; invalid → 400 (lines 197-211) ✅

**Query:** SELECT with LEFT JOINs to `tbl_pangkat`, `tbl_jabatan`, `tbl_polres`. Ordered by `nrp ASC`. Type-casts `polres_id` to int-or-null and `polda_id` to int for Flutter compatibility (lines 219-222).

### 1.3 `personil_post` — ✅ COMPLIANT

**Auth:** Operator-only. `role_id != 2` → 403 (lines 253-262).

**UUID Generation:** `$personil_id = generate_uuid4()` from `uuid_helper.php` (line 313) ✅

**NRP Uniqueness:** `SELECT personil_id FROM tbl_personil WHERE nrp = escape(nrp)`; any row → 422 "Pendaftaran gagal. NRP sudah terdaftar di sistem." (lines 294-305) ✅

**polda_id Injection:** Extracted from JWT payload (`$jwt_polda_id = (int) $payload['polda_id']`, line 264), hard-coded into INSERT (line 323). Client cannot set polda_id. ✅

**polres_id Nullable:** `''`, `'0'`, `0`, or `null` → stored as SQL `NULL`; otherwise cast to int (lines 307-311, 324). ✅

**Validation:** Missing `nrp`, `nama_lengkap`, or `pangkat_id`/`jabatan_id` == 0 → 422 (lines 284-292). ✅

**Response:** 201 Created with `{"personil_id": $personil_id}` in data.

### 1.4 `personil_put` — ⚠️ BUG FOUND

**Auth:** Operator-only (same as POST). ✅

**NRP Uniqueness (excluding self):** `WHERE nrp = escape($nrp) AND personil_id != escape($personil_id)` (lines 410-413). ✅

**polres_id Nullable:** Same normalization as POST (lines 424-428). ✅

**polda_id Enforcement:** `WHERE personil_id = '...' AND polda_id = jwt_polda_id` in UPDATE (lines 437-438). polda_id itself is never updatable. ✅

**🐛 Bug: affected_rows false 404 (lines 442-450):**
```php
if ($this->db->affected_rows() === 0) {
    // BUG: unchanged data → affected_rows=0 → false 404
    $this->output->set_status_header(404);
    echo json_encode(array(
        "message" => "Personel tidak ditemukan.",
        ...
    ));
    return;
}
```
When a PUT sends data identical to the current row, MySQL returns `affected_rows = 0` because no columns actually changed. The code misinterprets this as "row not found" and returns a false 404. **Fix needed:** pre-check row existence before the UPDATE, then return 200 regardless of affected_rows.

### 1.5 `personil_delete` — ❌ DOES NOT EXIST

- No `personil_delete` method in `Sdm.php`
- No DELETE route in `routes.php` (only GET/POST at lines 90-91, PUT at 92)
- Confirmed by full file audit and grep across `application/controllers/`

### 1.6 FK State: `tbl_proses_hukum.personil_id`

| Source | FK Definition |
|---|---|
| `database/v5/sindomondb.sql:167` | `CONSTRAINT fk_proses_hukum_personil FOREIGN KEY (personil_id) REFERENCES tbl_personil (personil_id) ON DELETE CASCADE` |
| `Seeder.php:162-172` | No FK, only `KEY idx_personil_id` |

The DB may be in either state depending on whether it was created via SQL dump or Seeder. The DELETE implementation must handle both cases by explicitly deleting `tbl_proses_hukum` rows first.

### 1.7 Table Schema

From `Seeder.php:149-160`:
```sql
CREATE TABLE IF NOT EXISTS `tbl_personil` (
    `personil_id` varchar(36) NOT NULL PRIMARY KEY,
    `nrp` varchar(20) NOT NULL,
    `nama_lengkap` varchar(255) NOT NULL,
    `pangkat_id` int(11) DEFAULT NULL,
    `jabatan_id` int(11) DEFAULT NULL,
    `status_aktif` varchar(50) DEFAULT NULL,
    `polda_id` int(11) DEFAULT NULL,
    `polres_id` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

Notes:
- No UNIQUE constraint on `nrp` in DDL — uniqueness enforced in application layer
- No soft-delete columns (no `is_active`, `updated_at`)
- `polres_id`, `pangkat_id`, `jabatan_id`, `polda_id` all nullable

---

## 2. Refactor Plan

### Summary

| Task | File | Change |
|---|---|---|
| 1 | `Sdm.php:356-458` | Fix `personil_put` false-404 bug |
| 2 | `Sdm.php` (after line 458) | Add `personil_delete` method |
| 3 | `routes.php` (after line 92) | Add DELETE route |

### Task 1: Fix `personil_put` affected_rows false-404

**Problem:** `affected_rows() === 0` is misinterpreted as "not found" when data is unchanged.

**Fix (two changes):**

**1a.** Insert existence + jurisdiction pre-check **before** field validation (after JSON parse block, before `$nrp = trim(...)`):

```php
// ── 3. EXISTENCE & JURISDICTION PRE-CHECK ──
$personil = $this->db->query(
    "SELECT personil_id FROM tbl_personil "
    . "WHERE personil_id = " . $this->db->escape($personil_id)
    . " AND polda_id = " . $this->db->escape($jwt_polda_id)
)->row_array();

if (!$personil) {
    $this->output->set_status_header(404);
    echo json_encode(array(
        "message" => "Personel tidak ditemukan.",
        "status" => 404,
        "data" => new stdClass()
    ));
    return;
}
```

Placing before field validation means PUT to non-existent ID → 404 (not 422) — correct REST semantics. Operator touching another polda's personil gets 404 (no existence leak).

**1b.** Delete lines 442-450 (the `if ($this->db->affected_rows() === 0)` block). The UPDATE stays, code falls through to the existing 200 response.

### Task 2: Add `personil_delete` method

Insert between `personil_put` closing brace (line 458) and `hukum_post` docblock (line 460):

```php
/**
 * DELETE /api/v1/sdm/personil/(:any)
 * Hapus Personel (hard delete) beserta riwayat proses hukumnya
 *
 * Auth: role_id=2 (Operator Polda) only.
 * Jurisdiction: personil_id must belong to JWT polda_id.
 *
 * NOTE: tbl_proses_hukum may or may not carry FK ON DELETE CASCADE
 * (database/v5/sindomondb.sql has it; Seeder.php does not). The
 * hukum rows are therefore always deleted explicitly first inside a
 * transaction — correct in both schema variants.
 */
public function personil_delete($personil_id)
{
    // ── 1. AUTH ──
    $payload = $this->_extract_jwt_payload();
    if (!$payload) {
        $this->output->set_status_header(401);
        echo json_encode(array(
            "message" => "Token tidak ditemukan",
            "status" => 401,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 2. ROLE ──
    $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
    if ($role_id != 2) {
        $this->output->set_status_header(403);
        echo json_encode(array(
            "message" => "Akses ditolak",
            "status" => 403,
            "data" => new stdClass()
        ));
        return;
    }

    $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

    // ── 3. EXISTENCE & JURISDICTION ──
    $personil = $this->db->query(
        "SELECT polda_id FROM tbl_personil WHERE personil_id = " . $this->db->escape($personil_id)
    )->row_array();

    if (!$personil) {
        $this->output->set_status_header(404);
        echo json_encode(array(
            "message" => "Personel tidak ditemukan.",
            "status" => 404,
            "data" => new stdClass()
        ));
        return;
    }

    if ((int) $personil['polda_id'] !== $jwt_polda_id) {
        $this->output->set_status_header(403);
        echo json_encode(array(
            "message" => "Akses ditolak. Personel berada di luar yurisdiksi Anda.",
            "status" => 403,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 4. DELETE (transaction; both tables are InnoDB) ──
    $this->db->trans_start();

    $this->db->query(
        "DELETE FROM tbl_proses_hukum WHERE personil_id = " . $this->db->escape($personil_id)
    );
    $this->db->query(
        "DELETE FROM tbl_personil WHERE personil_id = " . $this->db->escape($personil_id)
        . " AND polda_id = " . $this->db->escape($jwt_polda_id)
    );

    $this->db->trans_complete();

    if ($this->db->trans_status() === FALSE) {
        $this->output->set_status_header(500);
        echo json_encode(array(
            "message" => "Gagal menghapus data personel",
            "status" => 500,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 5. SUCCESS ──
    $this->output->set_status_header(200);
    echo json_encode(array(
        "status" => 200,
        "message" => "Personel berhasil dihapus.",
        "data" => new stdClass()
    ));
}
```

**Design decisions:**
- **Transaction** wraps both DELETEs for atomicity
- **Hukum deleted first** — safe with or without FK CASCADE
- **`AND polda_id`** in personil DELETE is defense-in-depth (jurisdiction already enforced in step 3)
- **Returns 403** for cross-polda attempts (not 404) — different from PUT which returns 404 to avoid leaking existence info for cross-polda lookups via PUT. For DELETE, the operator already has the UUID (from GET results within their polda), so a 403 is more informative.
- Uses Sdm's private `_extract_jwt_payload()` — consistent with the rest of the controller

### Task 3: Add DELETE route

Insert after `routes.php` line 92:

```php
$route['api/v1/sdm/personil/(:any)']['DELETE'] = 'sdm/personil_delete/$1';
```

Uses `(:any)` (not `(:num)`) because `personil_id` is a VARCHAR(36) UUID. Uppercase `'DELETE'` matches the convention of other SDM routes.

---

## 3. Verification Checklist

| # | Method | Scenario | Expected |
|---|---|---|---|
| 1 | PUT | Identical data to existing personil | **200** (was 404 before fix) |
| 2 | PUT | Changed data to existing personil | 200 |
| 3 | PUT | Non-existent personil_id | 404 |
| 4 | PUT | Other polda's personil | 404 |
| 5 | PUT | Duplicate NRP (different personil) | 422 |
| 6 | DELETE | Own polda's personil (with hukum records) | 200, both rows deleted |
| 7 | DELETE | Own polda's personil (no hukum records) | 200 |
| 8 | DELETE | Non-existent personil_id | 404 |
| 9 | DELETE | Other polda's personil | 403 |
| 10 | DELETE | Admin token (role_id=1) | 403 |
| 11 | DELETE | No token | 401 |
| 12 | OPTIONS | Preflight on personil/(:any) | 200 |

### Test Commands

```bash
# Syntax check
php -l application/controllers/Sdm.php
php -l application/config/routes.php

# Start dev server
php -S localhost:8080 tests/router.php &

# Run E2E tests
npm test
```
