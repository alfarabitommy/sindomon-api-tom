# API Polda CRUD — Soft Delete Refactor (Implementation & Verification Report)

> **Status:** ✅ COMPLETE — implemented, tested, verified
> **Date:** 2026-08-03
> **Scope:** `application/controllers/Master.php` — secure Soft Deletes, data-loss prevention, API spec enforcement
> **Related:** [api_polda_crud_audit.md](./api_polda_crud_audit.md) (pre-refactor audit)

---

## 1. Summary

Refactored the Master Wilayah (Polda) CRUD in `application/controllers/Master.php` from **hard deletes** to **soft deletes** via the `is_active` column, added duplicate-name enforcement and partial-update protection for GPS coordinates, and filtered all read endpoints to exclude deactivated rows. Business rules recorded to persistent project memory.

## 2. Critical Pre-Refactor Finding

**The `is_active`/`updated_at` columns did NOT exist in the local `sindomondb` database**, despite being described as manually added. Only `tbl_users` had them. Verified via `SHOW COLUMNS` — `tbl_polda` had only `id, nama_polda, latitude, longitude, created_at`; `tbl_polres` had only `polres_id, polda_id, nama_polres`.

**Resolution:** Added the columns to both tables, matching the `tbl_users` convention:

```sql
ALTER TABLE tbl_polda  ADD COLUMN IF NOT EXISTS is_active   tinyint(1) NOT NULL DEFAULT 1 AFTER longitude;
ALTER TABLE tbl_polda  ADD COLUMN IF NOT EXISTS updated_at  datetime DEFAULT NULL AFTER is_active;
ALTER TABLE tbl_polres ADD COLUMN IF NOT EXISTS is_active   tinyint(1) NOT NULL DEFAULT 1 AFTER nama_polres;
ALTER TABLE tbl_polres ADD COLUMN IF NOT EXISTS updated_at  datetime DEFAULT NULL AFTER is_active;
```

**Durability:** Added matching `INFORMATION_SCHEMA` ALTER guards + updated `CREATE TABLE IF NOT EXISTS` definitions in `application/controllers/Seeder.php` `_ensure_tables()` (the project's migration mechanism), so fresh environments get the columns too.

## 3. Code Changes — `application/controllers/Master.php`

### 3.1 `polda_delete($polda_id)` — Soft Delete with manual child pre-check

```php
// Existence check → 404 (unchanged)
$polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
if ($polda_exists === 0) { /* 404 */ }

// NEW: manual pre-check — FK 1451 no longer fires on soft delete
$active_polres = $this->db->get_where('tbl_polres', ['polda_id' => $polda_id, 'is_active' => 1])->num_rows();
if ($active_polres > 0) {
    http_response_code(409);
    echo json_encode([
        'status' => 409,
        'message' => 'Polda tidak dapat dihapus karena masih menaungi Polres aktif (Restricted by System).',
        'data' => (object)[]
    ]);
    return;
}

// NEW: soft delete instead of physical delete
$this->db->where('id', $polda_id)->update('tbl_polda', [
    'is_active' => 0,
    'updated_at' => date('Y-m-d H:i:s')
]);
```

Removed: `$this->db->delete()` + `db_debug` toggle + error 1451 inspection.

### 3.2 `polda_put($polda_id)` — Unique name + partial update

```php
// NEW: unique name check excluding self
$duplicate = $this->db->where('nama_polda', $nama_polda)
    ->where('id !=', $polda_id)
    ->get('tbl_polda')->num_rows();
if ($duplicate > 0) {
    http_response_code(409);
    echo json_encode([
        'status' => 409,
        'message' => 'Validasi gagal. Nama Polda sudah digunakan oleh Polda lain.',
        'data' => (object)[]
    ]);
    return;
}

// NEW: partial update — lat/lng only touched when explicitly provided
$update = [
    'nama_polda' => $nama_polda,
    'updated_at' => date('Y-m-d H:i:s')
];
if (array_key_exists('latitude', $input))  { $update['latitude']  = trim($input['latitude']); }
if (array_key_exists('longitude', $input)) { $update['longitude'] = trim($input['longitude']); }
```

Fixed bug: previously omitted lat/lng were silently set to `NULL`, erasing GPS coordinates on a simple rename.

### 3.3 Read endpoints — exclude deactivated rows

| Method | Change |
|--------|--------|
| `polda_get()` | `$this->db->get('tbl_polda')` → `get_where('tbl_polda', ['is_active' => 1])` |
| `polres_get()` | Added `$this->db->where('r.is_active', 1)` after the join |
| `wilayah_get()` | Polda query filtered `is_active = 1`; nested `polres_jajaran` query filtered `['polda_id' => ..., 'is_active' => 1]` |

### 3.4 Supporting changes (same module, spec compliance)

- **`polda_post()`** — duplicate-name check → 409 before insert (API spec: `nama_polda` harus UNIQUE); explicit `'is_active' => 1` on insert
- **`polres_delete($polres_id)`** — soft delete + manual pre-check: `tbl_personil WHERE polres_id = X` → 409 (exact original message); keeps the 404 path via explicit existence check
- **`polres_put($polres_id)`** — stamps `updated_at`

## 4. Verification Results

### 4.1 Syntax
```
$ php -l application/controllers/Master.php   → No syntax errors
$ php -l application/controllers/Seeder.php   → No syntax errors
```

### 4.2 Playwright E2E tests
```
$ npx playwright test tests/api/master_polres.spec.ts tests/api/seeder_master.spec.ts
→ 12 passed, 1 failed (6.7s)
$ npx playwright test tests/api/sindomon_e2e.spec.ts
→ 4 passed (3.9s)
```

The single failure (`Phase 2 — Dirsamapta shows is_vacancy_alert: true` in the SDM org-tree test) is **pre-existing and unrelated**: verified by `git stash` (reverting all changes) + rerun → fails identically on the unmodified code.

### 4.3 Live E2E (curl against `php -S localhost:8080 tests/router.php`)

| # | Action | Response |
|---|--------|----------|
| 1 | `POST /master/polda` create test polda | `201 {"polda_id": 39}` |
| 2 | `POST /master/polda` duplicate name | `409 "Validasi gagal. Nama Polda sudah digunakan oleh Polda lain."` |
| 3 | `PUT /master/polda/39` rename only (no lat/lng) | `200 "Data Polda berhasil diperbarui."` |
| 4 | `PUT /master/polda/39` with name "Polda Aceh" | `409` (duplicate excluding self) |
| 5 | `DELETE /master/polda/1` (has 2 active Polres) | `409 "Polda tidak dapat dihapus karena masih menaungi Polres aktif (Restricted by System)."` |
| 6 | `DELETE /master/polda/39` (no Polres) | `200 "Data Polda berhasil dihapus."` |

### 4.4 Database state after soft delete (row preserved, GPS intact)

```
id  nama_polda                       latitude  longitude  is_active  updated_at
1   Polda Aceh                       5.550000  95.316666  1          NULL
39  Polda Test Soft Delete Renamed   -6.9      107.6      0          2026-08-03 05:03:59
```

- Row **still exists** (soft delete — not physically removed) with `is_active = 0` ✅
- `updated_at` stamped ✅
- **lat/lng preserved** after partial update ✅

### 4.5 GET filters

| Endpoint | Result |
|----------|--------|
| `GET /master/polda` | 38 rows — soft-deleted #39 **not returned** ✅ |
| `GET /master/wilayah` | 38 rows — #39 **not returned** ✅ |
| `GET /master/polres?polda_id=1` | 2 rows — active Polres still listed ✅ |

### 4.6 Cleanup
Test row (`Polda Test Soft Delete%`) physically removed from DB; dev server stopped. Database returned to the 38-seeded-Polda state.

## 5. Persistent Memory (business rules recorded)

`codebase-memory-mcp` was **not available** in the session; the three rules were recorded to the standard persistent project memory instead:

| File | Rule |
|------|------|
| `memory/master-wilayah-soft-delete.md` | Master Wilayah (Polda) & Master Polres use Soft Deletes via `is_active`; all reads filter `is_active = 1` |
| `memory/polda-soft-delete-precheck.md` | Soft-deleting a Polda requires a manual active-child-Polres (and Polres → personel) check — FK 1451 no longer fires |
| `memory/polda-edit-partial-update.md` | Editing a Polda requires partial-update logic for GPS coordinates to prevent accidental data erasure |

All indexed in `memory/MEMORY.md`.

## 6. Follow-ups

1. ✅ **RESOLVED — Legacy `Polda.php`** (`GET /api/v1/polda`, used by the Flutter Map Dashboard): patched `get()` — main query is now `select * from tbl_polda where is_active = 1` and the nested `polres` sub-query is `... and is_active = 1`. Verified live: a created-then-soft-deleted Polda (id 40) did **not** appear in the response (38 active rows returned). `php -l` clean. (2026-08-03)
2. **Pre-existing test failure** — `seeder_master.spec.ts:94` Dirsamapta `is_vacancy_alert` assertion fails on unmodified code; unrelated to this refactor.
3. **Uncommitted** — `Master.php`, `Seeder.php`, `Polda.php` (this refactor) plus pre-existing `routes.php`, `Auth.php` modifications are all still in the working tree.
