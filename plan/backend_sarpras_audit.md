# Backend Sarpras & Altmatsus — Audit Report

**Date:** $(date +%Y-%m-%d)
**Auditor:** CI3 Backend Auditor (DEBUG MODE)
**Module:** `tbl_sarpras` (Sarpras & Altmatsus)
**Reference Modules:** `tbl_senjata`, `tbl_amunisi`

---

## 1. Route Status

**File:** `application/config/routes.php`

### Existing Sarpras Routes

| Method   | Route                                       | Exists? |
|----------|---------------------------------------------|---------|
| `GET`    | `api/v1/logistik/sarpras`                   | ❌ MISSING |
| `POST`   | `api/v1/logistik/sarpras`                   | ❌ MISSING |
| `PUT`    | `api/v1/logistik/sarpras/(:any)`            | ❌ MISSING |
| `DELETE` | `api/v1/logistik/sarpras/(:any)`            | ❌ MISSING |
| `OPTIONS`| `api/v1/logistik/sarpras`                   | ❌ MISSING |
| `OPTIONS`| `api/v1/logistik/sarpras/(:any)`            | ❌ MISSING |

**Result: 0 out of 6 required routes exist.** The entire `sarpras` route block is absent from `routes.php`.

### Comparison with Sibling Modules

| Route Group   | GET | POST | PUT | DELETE | OPTIONS (exact) | OPTIONS (wildcard) |
|---------------|-----|------|-----|--------|-----------------|-------------------|
| `senjata`     | ✅  | ✅   | ✅  | ✅     | ✅              | ✅                |
| `amunisi`     | ✅  | ✅   | ✅  | ✅     | ✅              | ✅                |
| `satwa`       | ❌  | ✅   | ❌  | ❌     | ❌              | ❌                |
| **`sarpras`** | ❌  | ❌   | ❌  | ❌     | ❌              | ❌                |

**Pattern to replicate (from `senjata`, lines 85-90):**
```php
$route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';
$route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';
```

⚠️ **CRITICAL NOTE:** The `OPTIONS` wildcard route `$route['api/v1/logistik/sarpras/(:any)']['OPTIONS']` is NECESSARY. Without it, the Flutter browser will fail CORS preflight on `PUT` and `DELETE` requests. CI3's Router checks `method_exists()` on the controller class BEFORE instantiating it — if the route points to a method that doesn't exist, the controller's constructor never runs, and the CORS headers in `__construct()` are never emitted. This was the root cause of the previous CORS bug with Senjata/Amunisi.

---

## 2. Controller Status

**File:** `application/controllers/Logistik.php`

### Existing Sarpras Methods

| Method             | Exists? | Notes |
|--------------------|---------|-------|
| `sarpras_get()`    | ❌ | Not implemented |
| `sarpras_post()`   | ❌ | Not implemented |
| `sarpras_put($id)` | ❌ | Not implemented |
| `sarpras_delete($id)` | ❌ | Not implemented |
| `sarpras_options($id = null)` | ❌ | Not implemented |

**Result: 0 out of 5 required methods exist.**

### Existing Methods in Logistik.php (for reference)

| Method                          | Lines    | Has File Upload? |
|---------------------------------|----------|-----------------|
| `senjata_post()`                | 32-161   | ✅ Base64       |
| `senjata_get()`                 | 169-234  | —               |
| `senjata_put($senjata_id)`      | 243-398  | ✅ Base64 (optional) |
| `senjata_delete($senjata_id)`   | 888-941  | —               |
| `senjata_options($id = null)`   | 951-954  | —               |
| `amunisi_post()`                | 407-485+ | ❌ (no file)    |
| `amunisi_get()`                 | 640-710  | —               |
| `amunisi_put($batch_id)`        | ∼520-632 | ❌ (no file)    |
| `amunisi_delete($batch_id)`     | 718-771  | —               |
| `amunisi_options($id = null)`   | 964-967  | —               |
| `satwa_post()`                  | 779-880  | ✅ Base64       |

---

## 3. Upload Mechanism

### Current State: Base64-in-JSON ONLY

**All** existing file uploads in this project use the **Base64-in-JSON** pattern exclusively:

1. **Content-Type Gate:** Every POST/PUT method enforces `Content-Type: application/json`. If the client sends `multipart/form-data`, the controller returns `415 Unsupported Media Type` immediately.

   ```php
   // From senjata_post(), lines 47-56
   $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
   if (strpos($content_type, 'application/json') === false) {
       // 415 error
   }
   ```

2. **Base64 Decode Helper:** `application/helpers/base64_file_helper.php` — `save_base64_file()` function:
   - Strips `data:image/xxx;base64,` prefix
   - Decodes base64 → binary
   - Validates MIME via `finfo_buffer()` (magic bytes, not extension)
   - Checks file size
   - Saves to disk with random hex filename + MIME-derived extension
   - Returns `['success' => true, 'file_name', 'file_path', 'mime', 'size']` or error array

3. **Allowed MIME types** are hardcoded per endpoint:
   - `senjata_post`: `['image/jpeg', 'image/png', 'image/jpg']` (line 103)
   - `satwa_post`: `['image/jpeg', 'image/png', 'image/jpg']` (line 834)
   - **No WebP support anywhere.**

4. **CI3's `$this->upload->do_upload()` is NEVER used** anywhere in the Logistik controller.

### What Needs to Change for Multipart/WebP Uploads

If the Flutter client will send real image files as `multipart/form-data` (converted to WebP), the following adaptations are needed:

#### Option A: Switch to Multipart (Recommended for real image files)

| Area | Change Required |
|------|----------------|
| **Content-Type gate** | Replace `application/json` check with `multipart/form-data` acceptance (or allow both). |
| **Field parsing** | Switch from `json_decode($this->input->raw_input_stream)` to `$this->input->post()` for text fields. |
| **File upload** | Use CI3's `$this->upload->do_upload()` or manual `$_FILES` handling instead of `save_base64_file()`. |
| **MIME validation** | Add `image/webp` to allowed MIMEs in `save_base64_file()` (or wherever validation happens). |
| **CORS headers** | Add `Access-Control-Allow-Headers: Content-Type` (already present). No extra headers needed for multipart. |
| **PHP config** | Ensure `upload_max_filesize` and `post_max_size` in php.ini are adequate for image files. |

#### Option B: Extend Base64 to Support WebP (Minimal change)

| Area | Change Required |
|------|----------------|
| **MIME map** | Add `'image/webp' => 'webp'` to the `$ext_map` in `save_base64_file()`. |
| **Allowed MIMEs** | Add `'image/webp'` to the `$allowed_mimes` array in `sarpras_post()` / `sarpras_put()`. |
| **Everything else** | Works as-is — but Flutter must still send Base64, not raw binary. |

### Recommendation

If the Flutter team plans to send **WebP files via multipart/form-data**, go with **Option A** for `sarpras_post` and `sarpras_put`. The rest of the module (`sarpras_get`, `sarpras_delete`) follows the same JSON-only pattern as `senjata_*`.

If you want to keep consistency with the rest of the codebase (all modules use Base64), go with **Option B** and just add WebP to the MIME whitelist.

---

## 4. Database Schema (Already Exists)

`tbl_sarpras` is already defined in the Seeder (`application/controllers/Seeder.php`, lines 230-243):

```sql
CREATE TABLE IF NOT EXISTS `tbl_sarpras` (
    `sarpras_id`      varchar(36)  NOT NULL,
    `polda_id`        int(11)      DEFAULT NULL,
    `kode_barang`     varchar(100) NOT NULL,
    `nama_barang`     varchar(255) NOT NULL,
    `kategori`        varchar(50)  DEFAULT NULL,
    `kondisi`         enum('Baik','Rusak Ringan','Rusak Berat') DEFAULT 'Baik',
    `tahun_pengadaan` varchar(10)  DEFAULT NULL,
    `foto_url`        varchar(500) DEFAULT NULL,
    `created_at`      datetime     NOT NULL DEFAULT current_timestamp(),
    `updated_at`      datetime     DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`sarpras_id`),
    UNIQUE KEY `uq_kode_barang` (`kode_barang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

30 seed rows exist with placeholder images (`https://placehold.co/400x300`).

---

## 5. Summary — What's Missing

| # | Item | Priority | Effort |
|---|------|----------|--------|
| 1 | 6 route entries in `routes.php` | 🔴 CRITICAL | Small |
| 2 | `sarpras_options($id=null)` in `Logistik.php` | 🔴 CRITICAL | Tiny |
| 3 | `sarpras_get()` in `Logistik.php` | 🔴 CRITICAL | Medium |
| 4 | `sarpras_post()` in `Logistik.php` | 🔴 CRITICAL | Medium |
| 5 | `sarpras_put($id)` in `Logistik.php` | 🟡 HIGH | Medium |
| 6 | `sarpras_delete($id)` in `Logistik.php` | 🟡 HIGH | Small |
| 7 | WebP MIME support (in helper and/or controller) | 🟡 HIGH | Tiny |
| 8 | Multipart/form-data handling (if switching from Base64) | 🟢 MEDIUM | Medium |
