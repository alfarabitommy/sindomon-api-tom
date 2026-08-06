# Backend Audit — Delete Stok Amunisi

> **Audit date:** 2025-07-17  
> **Module:** Logistik / Stok Amunisi  
> **Feature:** DELETE batch amunisi  

---

## 1. Route Status

**MISSING.** No `DELETE` route exists for the amunisi endpoint.

### Current amunisi routes (`application/config/routes.php`):

| Line | Method   | Route                                | Handler                     |
|------|----------|--------------------------------------|-----------------------------|
| 91   | `POST`   | `api/v1/logistik/amunisi`            | `logistik/amunisi_post`     |
| 92   | `GET`    | `api/v1/logistik/amunisi`            | `logistik/amunisi_get`      |
| 93   | `OPTIONS`| `api/v1/logistik/amunisi`            | `logistik/amunisi_options`  |
| 94   | `OPTIONS`| `api/v1/logistik/amunisi/(:any)`     | `logistik/amunisi_options`  |

### Reference pattern (senjata, line 88):

```php
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
```

### What needs to be added:

```php
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1';
```

---

## 2. Controller Status

**MISSING.** No `amunisi_delete()` method exists in `application/controllers/Logistik.php` (769 lines total).

### Existing amunisi methods:

| Line | Method                          | Purpose          |
|------|---------------------------------|------------------|
| 407  | `amunisi_post()`                | Create batch     |
| 502  | `amunisi_get()`                 | List batches     |
| 765  | `amunisi_options($id = null)`   | CORS preflight   |

### Reference pattern (`senjata_delete`, line 689–742):

```php
public function senjata_delete($senjata_id)
{
    // 1. JWT auth
    // 2. Existence + jurisdiction check (polda_id from JWT)
    // 3. DELETE FROM tbl_senjata WHERE senjata_id = ?
    // 4. Return 200 with {"status":200,"message":"Data berhasil dihapus","data":{}}
}
```

### What needs to be created:

A new method `amunisi_delete($batch_id)` that:

1. **Authenticates** via JWT (`get_jwt_payload($this)`)
2. **Checks existence + jurisdiction** — queries `tbl_amunisi_batch` for the given `batch_id` scoped to the JWT `polda_id`
3. **Deletes** the record: `DELETE FROM tbl_amunisi_batch WHERE batch_id = ?`
4. **Returns** HTTP 200 on success, 404 if not found, 401 if unauthenticated

---

## 3. Action Plan

Two changes are required to enable backend deletion:

### A. Add route (`application/config/routes.php`, after line 94)

```php
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1';
```

### B. Add controller method (`application/controllers/Logistik.php`, after `amunisi_get()`)

Create `amunisi_delete($batch_id)` following the `senjata_delete($senjata_id)` pattern at line 689, adapted for:

| Aspect           | senjata_delete                          | amunisi_delete (to build)               |
|------------------|-----------------------------------------|-----------------------------------------|
| Table            | `tbl_senjata`                           | `tbl_amunisi_batch`                     |
| PK column        | `senjata_id` (VARCHAR UUID)             | `batch_id` (INT AUTO_INCREMENT)         |
| URL param        | `$senjata_id`                           | `$batch_id`                             |
| Jurisdiction col | `polda_id`                              | `polda_id` (same)                       |
| Success message  | `"Data berhasil dihapus"`               | Same or `"Batch amunisi berhasil dihapus"` |

### C. Scope / constraints

- **Authorization:** All authenticated roles can delete — same as `senjata_delete` (no role gate beyond JWT + jurisdiction).
- **No soft delete:** The pattern is a hard `DELETE FROM` — no `is_deleted` flag. Follow this convention.
- **No file cleanup needed:** Unlike `senjata` which stores `foto_url`, `tbl_amunisi_batch` has no file column — simpler.
- **No cascading dependencies known:** `batch_id` is not referenced as a foreign key in the seeder DDL.

---

## 4. Implementation Status ✅ COMPLETE

Both changes have been applied and verified:

| # | Change | File | Status |
|---|--------|------|--------|
| A | Add `DELETE` route | `application/config/routes.php:95` | ✅ Done |
| B | Add `amunisi_delete($batch_id)` | `application/controllers/Logistik.php:574-633` | ✅ Done |

### Verification results
- **`npm test`:** 13 passed, 0 new failures (2 pre-existing failures unrelated to amunisi)
- **Endpoint test (auth):** `DELETE /api/v1/logistik/amunisi/99999` → 404 `{"status":404,"message":"Batch amunisi tidak ditemukan.","data":{}}`
- **Endpoint test (no auth):** `DELETE /api/v1/logistik/amunisi/99999` → 401 `{"status":401,"message":"Token tidak ditemukan","data":{}}`
