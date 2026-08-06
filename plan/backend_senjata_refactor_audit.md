# Senjata Refactor Audit: Base64/JSON → Multipart/WebP

**Date:** 2025-07-15
**Author:** Senior CI3 Backend Auditor (DEBUG/PASSIVE MODE)
**Status:** PLAN CONFIRMED — awaiting implementation approval

---

## 1. Current Architecture Audit

### 1.1 Route Map (routes.php lines 85–90)

```php
$route['api/v1/logistik/senjata']['POST']             = 'logistik/senjata_post';
$route['api/v1/logistik/senjata']['GET']              = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['PUT']       = 'logistik/senjata_put/$1';        // ❌ PROBLEM
$route['api/v1/logistik/senjata/(:any)']['DELETE']    = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata']['OPTIONS']          = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS']   = 'logistik/senjata_options';
```

**Problem:** The update route uses `['PUT']`. PHP's built-in `$_FILES` superglobal is **not populated on PUT requests** — multipart/form-data file uploads are invisible. This is why both `sarpras` and `satwa` already use `['POST']` for updates.

### 1.2 Target Pattern (Sarpras & Satwa — already refactored)

```php
// routes.php — Satwa (lines 97-102) and Sarpras (lines 103-108)
$route['api/v1/logistik/satwa']['POST']     = 'logistik/satwa_post';
$route['api/v1/logistik/satwa/(:any)']['POST'] = 'logistik/satwa_post/$1';   // ✅ update via POST

$route['api/v1/logistik/sarpras']['POST']      = 'logistik/sarpras_post';
$route['api/v1/logistik/sarpras/(:any)']['POST'] = 'logistik/sarpras_post/$1'; // ✅ update via POST
```

### 1.3 Controller Methods — Current State

| Method | Lines | Purpose | Content-Type Gate | File Handling |
|--------|-------|---------|-------------------|---------------|
| `senjata_post()` | 32–161 | Create only | Blocks non-JSON (415) | `save_base64_file()` — jpeg/png/jpg, 512KB |
| `senjata_put($senjata_id)` | 243–398 | Update only | Blocks non-JSON (415) | `save_base64_file()` — jpeg/png/jpg, 512KB |
| `senjata_get()` | 169–234 | List (GET) | N/A | N/A (reads `foto_url` only) |
| `senjata_delete($senjata_id)` | 1203–1256 | Delete | N/A | ⚠️ No `@unlink` for orphaned photo files |
| `senjata_options($id)` | 1266–1269 | CORS preflight | N/A | N/A |

### 1.4 Database Schema — `tbl_senjata`

```sql
CREATE TABLE `tbl_senjata` (
    `senjata_id`       varchar(36) NOT NULL,         -- UUID v4
    `nomor_seri`       varchar(100) DEFAULT NULL,
    `kategori_id`      int(11) DEFAULT NULL,          -- FK → tbl_kategori_senjata
    `polda_id`         int(11) DEFAULT NULL,
    `tahun_pengadaan`  varchar(10) DEFAULT NULL,
    `status_kelayakan` varchar(50) DEFAULT NULL,
    `foto_url`         varchar(500) DEFAULT NULL,     -- relative path: uploads/senjata/xxx.ext
    `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`senjata_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Note:** No `updated_at` column. The current `senjata_put()` does not set one. The Satwa/Sarpras refactored methods also do not set `updated_at`. **Out of scope for this refactor** unless explicitly requested.

### 1.5 Field Name Mapping

| Current JSON field | Current multipart name (Sarpras/Satwa) | Refactored Senjata name |
|--------------------|----------------------------------------|--------------------------|
| `foto_fisik` (base64 string in JSON) | `foto` (multipart file part) | `foto` |

All other form fields remain the same names: `nomor_seri`, `kategori_id`, `tahun_pengadaan`, `status_kelayakan`.

### 1.6 Tests

No existing Playwright test files reference `senjata` at all. Green field for testing.

---

## 2. Defects Found in Current Code

### 2.1 `senjata_delete()` — Missing Photo Cleanup (LOW)

`senjata_delete()` (line 1236) executes `DELETE FROM tbl_senjata` but never calls `@unlink()` on the associated `foto_url`. Compare with `sarpras_delete()` (line 1698) which properly cleans up:

```php
// sarpras_delete does this:
if ($delete && $sarpras['foto_url'] !== null && strpos($sarpras['foto_url'], 'uploads/') === 0) {
    @unlink(FCPATH . $sarpras['foto_url']);
}

// senjata_delete does NOT — orphaned files accumulate on disk.
```

**Plan:** Add the same `@unlink` block when refactoring `senjata_delete()`. The existence check must also SELECT `foto_url` (currently only selects `senjata_id`).

### 2.2 `senjata_put()` — Rollback Variable Scope Bug (MEDIUM)

Line 379 references `$result['file_path']` which is only defined inside the `if` block at line 342 (the `save_base64_file` return). If the UPDATE fails for a non-photo reason (e.g., DB went away mid-transaction), `$result` is undefined → PHP Notice, no rollback occurs. This goes away entirely when we merge into the multipart pattern (which uses `$foto_url` consistently).

### 2.3 `senjata_post()` — 512KB Limit Too Tight (LOW)

Current limit is 512,000 bytes (500 KB). Satwa and Sarpras use 2,048 KB (2 MB). A modern smartphone photo at reasonable quality is easily 1–2 MB. **Recommend bumping to 2 MB** to match the other modules.

---

## 3. Refactoring Plan — Confirmed

### 3.1 Route Changes (`application/config/routes.php`)

**DELETE:**
```php
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
```

**ADD:**
```php
$route['api/v1/logistik/senjata/(:any)']['POST'] = 'logistik/senjata_post/$1';
```

Final state (matching Satwa/Sarpras pattern exactly):

```php
$route['api/v1/logistik/senjata']['POST']             = 'logistik/senjata_post';
$route['api/v1/logistik/senjata/(:any)']['POST']      = 'logistik/senjata_post/$1';
$route['api/v1/logistik/senjata']['GET']              = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['DELETE']    = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata']['OPTIONS']          = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS']   = 'logistik/senjata_options';
```

### 3.2 Controller Changes (`application/controllers/Logistik.php`)

#### 3.2.1 Signature Change

```php
// OLD (two methods):
public function senjata_post()           // create only
public function senjata_put($senjata_id) // update only

// NEW (one method):
public function senjata_post($id = null) // create + update
```

#### 3.2.2 Content-Type Gate

```php
// OLD: Rejects non-JSON
if (strpos($content_type, 'application/json') === false) { ... 415 ... }

// NEW: Rejects JSON (same as satwa_post, sarpras_post)
if (strpos($content_type, 'application/json') !== false) {
    // "Content-Type harus multipart/form-data (upload file tidak mendukung JSON)."
}
```

#### 3.2.3 Form Field Extraction

```php
// OLD: JSON decode
$input = json_decode($this->input->raw_input_stream, true);
$nomor_seri = isset($input['nomor_seri']) ? trim($input['nomor_seri']) : '';

// NEW: CI3 input->post() (same as satwa/sarpras)
$nomor_seri       = $this->input->post('nomor_seri') !== null ? trim($this->input->post('nomor_seri')) : '';
$kategori_id      = $this->input->post('kategori_id') !== null ? (int) $this->input->post('kategori_id') : 0;
$tahun_pengadaan  = $this->input->post('tahun_pengadaan') !== null ? trim($this->input->post('tahun_pengadaan')) : '';
$status_kelayakan = $this->input->post('status_kelayakan') !== null ? trim($this->input->post('status_kelayakan')) : '';
```

#### 3.2.4 File Upload: base64 → Multipart

```php
// OLD: save_base64_file()
$foto_fisik = isset($input['foto_fisik']) ? $input['foto_fisik'] : '';
$result = save_base64_file($foto_fisik, $upload_dir, $allowed_mimes, 512000);
$foto_url = 'uploads/senjata/' . $result['file_name'];

// NEW: CI3 Upload library with multipart (matching satwa/sarpras)
$foto_url = null;
if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $config = array(
        'upload_path'   => FCPATH . 'uploads/senjata/',
        'allowed_types' => 'jpg|jpeg|png|webp',    // WebP added
        'max_size'      => 2048,                    // 2MB (was 512KB)
        'encrypt_name'  => TRUE
    );
    $this->load->library('upload', $config);
    if (!$this->upload->do_upload('foto')) { ... }
    $foto_url = 'uploads/senjata/' . $this->upload->data()['file_name'];
}
```

#### 3.2.5 Create Path (when `$id` is null)

- Identical to current `senjata_post()` create logic, but using multipart form fields and `$foto_url` variable.
- Keep mandatory foto rule on create (foto wajib dilampirkan).
- Keep unique `nomor_seri` check.
- Keep auto-inject `polda_id` from JWT.

#### 3.2.6 Update Path (when `$id` is provided)

- Carry over from `senjata_put()`: existence + jurisdiction check, partial-update `$set` array, uniqueness check for `nomor_seri` change.
- Foto is **optional** on update — only replaced when a new file is sent.
- Empty `$set` → 400 "Tidak ada field yang dapat diperbarui."
- Update SQL must enforce `polda_id` in WHERE for jurisdiction (current `senjata_put` does this at line 373).

#### 3.2.7 Delete `senjata_put()` Entirely

The method `senjata_put($senjata_id)` (lines 243–398) is **deleted**.

#### 3.2.8 Fix `senjata_delete()` Photo Cleanup

```php
// Change existence check to also select foto_url:
SELECT senjata_id, foto_url FROM tbl_senjata WHERE ...

// Add cleanup after successful DELETE:
if ($delete && $senjata['foto_url'] !== null && strpos($senjata['foto_url'], 'uploads/') === 0) {
    @unlink(FCPATH . $senjata['foto_url']);
}
```

### 3.3 Files Touched

| File | Change |
|------|--------|
| `application/config/routes.php` | Replace `PUT senjata_put` with `POST senjata_post/$1` |
| `application/controllers/Logistik.php` | Rewrite `senjata_post()` → `senjata_post($id=null)`, delete `senjata_put()`, fix `senjata_delete()` |

### 3.4 What Does NOT Change

- `senjata_get()` — untouched (already only reads `foto_url`, no upload logic).
- `senjata_options()` — untouched.
- DB schema (`tbl_senjata`) — no migration needed.
- JWT auth flow — same.
- Jurisdiction enforcement — same.
- Response envelope — same `{status, message, data}` pattern.

---

## 4. Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| Flutter client must change from PUT to POST for updates | **BREAKING** | Must coordinate with frontend. Same breaking change was already made for Sarpras and Satwa — Flutter team should follow the same pattern. |
| Flutter client must change from JSON body to MultipartRequest | **BREAKING** | Must coordinate with frontend. Field names stay the same (except `foto_fisik` → `foto` file part). |
| Orphaned `senjata_put` route after deletion | LOW | CI3 returns 404 for unmatched routes — graceful degradation. |
| Orphaned uploaded files from deleted records | LOW (existing) | Fix `senjata_delete()` to `@unlink` — cleanup going forward. Old orphans remain but are harmless. |

---

## 5. Implementation Checklist

- [ ] **Step 1:** Routes — add `POST senjata_post/$1` route, remove `PUT senjata_put/$1` route
- [ ] **Step 2:** Rewrite `senjata_post()` → `senjata_post($id = null)` with create + update logic
- [ ] **Step 2a:** Content-type gate: reject JSON, accept multipart
- [ ] **Step 2b:** Extract form fields via `$this->input->post()`
- [ ] **Step 2c:** File upload via CI3 `$this->upload->do_upload('foto')` — WebP, 2MB
- [ ] **Step 2d:** Create path: mandatory foto, unique nomor_seri, polda_id auto-inject
- [ ] **Step 2e:** Update path: existence + jurisdiction, partial update, foto optional
- [ ] **Step 3:** Delete `senjata_put($senjata_id)` method entirely
- [ ] **Step 4:** Fix `senjata_delete()` — SELECT foto_url, add @unlink cleanup
- [ ] **Step 5:** Write/update Playwright E2E tests for senjata CRUD with multipart
- [ ] **Step 6:** Verify `GET /api/v1/logistik/senjata` still works (untouched, but regression check)
- [ ] **Step 7:** Coordinate with Flutter team for client-side changes

---

## 6. Quick Reference: Target Method Shape

The final `senjata_post($id = null)` should follow this structure (mirroring `satwa_post` exactly):

```
senjata_post($id = null):
  1. AUTH: JWT → get polda_id
  2. CONTENT-TYPE: reject application/json (multipart only)
  3. EXTRACT FORM FIELDS: nomor_seri, kategori_id, tahun_pengadaan, status_kelayakan
  4. is_update = ($id !== null)
  5. CREATE PATH (if !is_update): mandatory validations
  6. UPDATE PATH (if is_update): existence + jurisdiction, build $set
  7. FILE UPLOAD: $_FILES['foto'] → CI3 upload lib → $foto_url
  8. CREATE: INSERT → 201
  9. UPDATE: build $set, append foto if new, UPDATE WHERE polda_id → 200
```

---

**AUDIT COMPLETE.** No code has been written. All findings and the plan are confirmed and ready for implementation upon approval.
