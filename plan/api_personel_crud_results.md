# API Personel CRUD — Implementation Results

> **Date:** 2026-08-04
> **Scope:** Manajemen Personel (Operator Polda) — Sdm controller refactor
> **Plan Source:** `plan/api_personel_crud_audit.md`

---

## 1. Execution Summary

| # | File | Change | Status |
|---|---|---|---|
| 1 | `application/controllers/Sdm.php` | **False-404 fix** in `personil_put()` — added existence & jurisdiction pre-check; removed the buggy `affected_rows() === 0` → 404 block | ✅ Done |
| 2 | `application/controllers/Sdm.php` | **New `personil_delete()` method** — Operator-only auth, strict `polda_id` jurisdiction, transactional cascade delete of `tbl_proses_hukum` → `tbl_personil` | ✅ Done |
| 3 | `application/config/routes.php` | **New route** `DELETE api/v1/sdm/personil/(:any)` → `sdm/personil_delete/$1` | ✅ Done |

**Diff stats:** `Sdm.php` +112 lines, `routes.php` +1 line.

---

## 2. Code Diff Proof

### 2.1 False-404 Fix in `personil_put()` (Sdm.php:393-412)

**Problem:** `$this->db->affected_rows() === 0` fired when the client PUT identical data (MySQL reports 0 affected rows when nothing changed), producing a false `404 Personel tidak ditemukan`.

**Fix — pre-check injected before field validation (404 now determined by row existence, not affected_rows):**

```php
        // ── 3. EXISTENCE & JURISDICTION PRE-CHECK ──
        // (Fix: previously affected_rows()==0 on an unchanged row was
        //  misread as "not found", producing a false 404.)
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

**Removed block:**

```php
-        if ($this->db->affected_rows() === 0) {
-            $this->output->set_status_header(404);
-            echo json_encode(array(
-                "message" => "Personel tidak ditemukan.",
-                "status" => 404,
-                "data" => new stdClass()
-            ));
-            return;
-        }
```

The existence check combines `personil_id` **and** `polda_id` — an operator touching another polda's personil still gets 404 (no existence leak), identical to prior behavior, while idempotent PUTs now correctly return 200. Placing the check **before** field validation also means PUT to an unknown ID returns 404 rather than 422 (correct REST semantics).

### 2.2 New `personil_delete()` Method (Sdm.php:481-559)

```php
    public function personil_delete($personil_id)
    {
        // ── 1. AUTH ──
        $payload = $this->_extract_jwt_payload();
        if (!$payload) { ... 401 ... }

        // ── 2. ROLE ──
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2) { ... 403 "Akses ditolak" ... }

        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. EXISTENCE & JURISDICTION ──
        $personil = $this->db->query(
            "SELECT polda_id FROM tbl_personil WHERE personil_id = " . $this->db->escape($personil_id)
        )->row_array();

        if (!$personil) { ... 404 "Personel tidak ditemukan." ... }

        if ((int) $personil['polda_id'] !== $jwt_polda_id) {
            ... 403 "Akses ditolak. Personel berada di luar yurisdiksi Anda." ...
        }

        // ── 4. DELETE (transaction; both tables are InnoDB) ──
        $this->db->trans_start();

        // Delete hukum rows first (safe with or without FK CASCADE)
        $this->db->query(
            "DELETE FROM tbl_proses_hukum WHERE personil_id = " . $this->db->escape($personil_id)
        );
        // Delete personil (AND polda_id = defense-in-depth)
        $this->db->query(
            "DELETE FROM tbl_personil WHERE personil_id = " . $this->db->escape($personil_id)
            . " AND polda_id = " . $this->db->escape($jwt_polda_id)
        );

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) { ... 500 ... }

        // ── 5. SUCCESS ──
        ... 200 "Personel berhasil dihapus." ...
    }
```

**Security design:**
- **Role gate:** `role_id != 2` → 403 (Operator Polda only; Admin/Eksekutif rejected)
- **Jurisdiction gate:** personil's `polda_id` must equal JWT `polda_id` → else 403
- **Transaction:** `trans_start()` / `trans_complete()` / `trans_status()` wrap both DELETEs — atomic (both tables are InnoDB per Seeder.php:160,172)
- **Cascade strategy:** `tbl_proses_hukum` rows deleted explicitly **first** — correct in both schema variants (FK `ON DELETE CASCADE` exists in `database/v5/sindomondb.sql:167` but NOT in Seeder.php:162-172)
- **Defense-in-depth:** `AND polda_id = jwt_polda_id` also in the personil DELETE statement
- **SQL injection:** all values via `$this->db->escape()`

### 2.3 Route Registration (routes.php:93)

```php
$route['api/v1/sdm/personil/(:any)']['DELETE'] = 'sdm/personil_delete/$1';
```

Uses `(:any)` (not `(:num)`) because `personil_id` is a VARCHAR(36) UUID. Uppercase verb key matches the SDM block convention.

---

## 3. Verification Status

### 3.1 Syntax Checks — ✅ PASS

```
No syntax errors detected in application/controllers/Sdm.php
No syntax errors detected in application/config/routes.php
```

### 3.2 Route Registration — ✅ CONFIRMED

```
90:$route['api/v1/sdm/personil']['GET'] = 'sdm/personil_get';
91:$route['api/v1/sdm/personil']['POST'] = 'sdm/personil_post';
92:$route['api/v1/sdm/personil/(:any)']['PUT'] = 'sdm/personil_put/$1';
93:$route['api/v1/sdm/personil/(:any)']['DELETE'] = 'sdm/personil_delete/$1';
```

### 3.3 Live API Verification — ✅ ALL 11 CASES PASSED

Environment: `php -S localhost:8090 tests/router.php`, operator JWT `polda_id=12` (from `tests/seed.php`), real `sindomondb`.

| # | Case | Method/URL | Expected | Actual | Result |
|---|---|---|---|---|---|
| 1 | Create personil | `POST /sdm/personil` | 201 | 201 `personil_id` UUID | ✅ |
| 2 | **PUT identical data** (regression) | `PUT /sdm/personil/{id}` | **200** | **200** "Data personel berhasil diperbarui." | ✅ **Bug fixed** |
| 3 | PUT non-existent | `PUT /sdm/personil/00000000-...` | 404 | 404 "Personel tidak ditemukan." | ✅ |
| 4 | Add hukum record | `POST /sdm/hukum` | 201 | 201 | ✅ |
| 5 | **DELETE with cascade** | `DELETE /sdm/personil/{id}` | 200 | 200 "Personel berhasil dihapus." | ✅ |
| 6 | **Cascade proof (DB)** | `SELECT COUNT(*)` | 0 / 0 | `tbl_personil: 0, tbl_proses_hukum: 0` | ✅ |
| 7 | DELETE cross-region (polda 15) | `DELETE /sdm/personil/...dead` | 403 | 403 "Akses ditolak. Personel berada di luar yurisdiksi Anda." | ✅ |
| 8 | DELETE non-existent | `DELETE /sdm/personil/00000000-...` | 404 | 404 "Personel tidak ditemukan." | ✅ |
| 9 | DELETE no token | `DELETE /sdm/personil/...` | 401 | 401 "Token tidak ditemukan" | ✅ |
| 10 | DELETE as Admin (role 1) | `DELETE /sdm/personil/...` | 403 | 403 "Akses ditolak" | ✅ |
| 11 | PUT cross-region | `PUT /sdm/personil/...dead` | 404 | 404 (no existence leak) | ✅ |

### 3.4 Existing E2E Suite — ✅ 4/4 PASSED

```
npx playwright test tests/api/sindomon_e2e.spec.ts
✓ 4 passed (3.9s)  — Auth JWT, personil create+duplicate NRP, hukum create+cross-region 403, admin login
```

No regressions introduced in the existing SDM flow.

---

## Notes

- **Not committed:** The changes are staged in the working tree only; no commit was made (per plan, commit follows your approval).
- **Test artifacts:** `test-results/` files changed/deleted by Playwright runs are unrelated to the refactor.
- **New audit doc:** `plan/api_personel_crud_audit.md` (untracked) was created during the planning phase as the source document.
