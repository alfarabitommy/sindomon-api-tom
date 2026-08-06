# Satwa K9 & Turangga — Backend Audit

> **Date:** 2025-07-16  
> **Target:** `tbl_satwa` module (`Logistik` controller)  
> **Goal:** Upgrade architecture to Multipart/form-data + WebP, matching the new Sarpras reference implementation, and close CORS + route gaps.

---

## 1. Route Status (`application/config/routes.php`)

### 1.1 Current Satwa Routes

| Route | Method | Handler | Status |
|-------|--------|---------|--------|
| `api/v1/logistik/satwa` | `POST` | `logistik/satwa_post` | ✅ EXISTS (create only) |

**That's it. Only ONE route exists (line 97).**

### 1.2 Missing Satwa Routes (vs Sarpras Gold Standard)

| Route | Method | Purpose | Status |
|-------|--------|---------|--------|
| `api/v1/logistik/satwa` | `GET` | List/inventarisasi satwa | ❌ MISSING |
| `api/v1/logistik/satwa/(:any)` | `POST` | Update satwa (reuse post method) | ❌ MISSING |
| `api/v1/logistik/satwa/(:any)` | `DELETE` | Delete satwa | ❌ MISSING |
| `api/v1/logistik/satwa` | `OPTIONS` | CORS preflight (exact) | ❌ MISSING |
| `api/v1/logistik/satwa/(:any)` | `OPTIONS` | CORS preflight (wildcard) | ❌ MISSING |

### 1.3 Sarpras Reference (Lines 98-103)

```
$route['api/v1/logistik/sarpras']['POST']                = 'logistik/sarpras_post';
$route['api/v1/logistik/sarpras/(:any)']['POST']         = 'logistik/sarpras_post/$1';
$route['api/v1/logistik/sarpras']['GET']                 = 'logistik/sarpras_get';
$route['api/v1/logistik/sarpras/(:any)']['DELETE']       = 'logistik/sarpras_delete/$1';
$route['api/v1/logistik/sarpras']['OPTIONS']             = 'logistik/sarpras_options';
$route['api/v1/logistik/sarpras/(:any)']['OPTIONS']      = 'logistik/sarpras_options';
```

### 1.4 CORS Impact

The `Logistik` constructor (line 12-15) handles `OPTIONS` globally — but **only if CI3 routes the request to the controller**. Without the `OPTIONS` routes registered, CodeIgniter's router (`CodeIgniter.php:423`) fails `method_exists()` before the controller is even instantiated. This means the browser's CORS preflight (`OPTIONS /api/v1/logistik/satwa/123`) will return a **404 or 500**, causing the Flutter app to fail with a CORS network error on any satwa mutation (update/delete).

**This is identical to the CORS bug we fixed for Sarpras.**

---

## 2. Controller Status (`application/controllers/Logistik.php`)

### 2.1 Existing Satwa Methods

| Method | Lines | Purpose | Status |
|--------|-------|---------|--------|
| `satwa_post()` | 779-880 | Create satwa | ✅ EXISTS (needs refactor) |
| `satwa_get()` | — | List satwa | ❌ MISSING |
| `satwa_delete()` | — | Delete satwa | ❌ MISSING |
| `satwa_options()` | — | CORS preflight dummy | ❌ MISSING |

### 2.2 `satwa_post()` — Current Architecture (What Needs Refactoring)

The current `satwa_post()` is an old-style **JSON + Base64** endpoint. Here's what must change:

#### A. Content-Type Gate (Lines 790-795)

```php
// CURRENT: BLOCKS multipart
if (strpos($this->input->server('CONTENT_TYPE'), 'application/json') === false) {
    // 415 error
}
```

**Must become** — exactly like `sarpras_post()` (line 1083-1092):

```php
// NEW: REJECT application/json, ACCEPT multipart/form-data
if (strpos($content_type, 'application/json') !== false) {
    // 415: "Content-Type harus multipart/form-data"
}
```

**Rationale:** PHP only populates `$_FILES` when the request is `multipart/form-data`. The old gate actively blocks it.

#### B. Payload Parsing (Lines 797-812)

```php
// CURRENT: json_decode($this->input->raw_input_stream)
$input = json_decode($this->input->raw_input_stream);
$nomor_registrasi = isset($input->nomor_registrasi) ? trim($input->nomor_registrasi) : '';
// ... all fields from JSON
```

**Must become** — form fields via `$this->input->post()`:

```php
// NEW: read from multipart form fields
$nomor_registrasi = $this->input->post('nomor_registrasi') !== null ? trim($this->input->post('nomor_registrasi')) : '';
$jenis_satwa      = $this->input->post('jenis_satwa') !== null ? trim($this->input->post('jenis_satwa')) : '';
// etc.
```

#### C. File Upload (Lines 833-846)

```php
// CURRENT: Base64 decode via save_base64_file()
$upload_dir = FCPATH . 'uploads/satwa/';
$result = save_base64_file($foto_fisik, $upload_dir, array('image/jpeg', 'image/png', 'image/jpg'), 512000);
$foto_url = $upload_dir . $result['file_name'];  // BUG: absolute path stored!
```

**Must become** — CI3 Upload library + `$_FILES['foto']` (matching `sarpras_post()` lines 1206-1247):

```php
// NEW: multipart file upload via CI3 Upload library
if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_path = FCPATH . 'uploads/satwa/';
    if (!is_dir($upload_path)) {
        mkdir($upload_path, 0755, true);
    }

    $config = array(
        'upload_path'   => $upload_path,
        'allowed_types' => 'jpg|jpeg|png|webp',   // WebP supported
        'max_size'      => 2048,                   // 2MB
        'encrypt_name'  => TRUE
    );
    $this->load->library('upload', $config);

    if (!$this->upload->do_upload('foto')) {
        // error handling with size/type detection
    }

    $upload_data = $this->upload->data();
    $foto_url = 'uploads/satwa/' . $upload_data['file_name'];  // RELATIVE path
}
```

#### D. Missing WebP Support

```php
// CURRENT allowed MIME types (line 834):
array('image/jpeg', 'image/png', 'image/jpg')
```

WebP (`image/webp`) is NOT in the list. Must add `webp` to `allowed_types` and ensure the MIME check accepts `image/webp`.

#### E. Missing UPDATE Path

The current `satwa_post()` has **no `$id` parameter** and **no update logic**. The Sarpras reference (`sarpras_post($id = null)`) handles both create (when `$id` is null/empty) and update (when `$id` is provided from the `/(:any)` route) in a single method. Satwa must adopt this same pattern.

#### F. Database Transaction

Current satwa_post uses `$this->db->trans_begin()` / `trans_rollback()` / `trans_commit()` (lines 830, 837, 863, 871). The Sarpras reference does NOT use CI3 transactions — it uses manual rollback (`@unlink` on failure). This is inconsistent and should be aligned.

#### G. File URL Path Bug (Line 846)

```php
$foto_url = $upload_dir . $result['file_name'];
// Result: /home/tommy/dev/sindomon-api-tom/uploads/satwa/somefile.jpg
```

This stores the **absolute filesystem path** in the database, not a relative URL. Compare with Sarpras:

```php
$foto_url = 'uploads/sarpras/' . $upload_data['file_name'];
// Result: uploads/sarpras/somefile.jpg   (relative, correct)
```

#### H. No `satwa_options()` Method

The constructor's OPTIONS handler only fires if CI3 instantiates the controller. Without the routes (see §1.2) AND the corresponding `satwa_options()` method, CORS preflight fails. The Sarpras fix adds:

```php
public function sarpras_options($id = null) {
    http_response_code(200);
    exit;
}
```

Satwa needs the identical pattern.

---

## 3. Summary: Refactor Checklist

### Routes (5 to add)

| # | Route | Method | Handler |
|---|-------|--------|---------|
| 1 | `api/v1/logistik/satwa` | `GET` | `logistik/satwa_get` |
| 2 | `api/v1/logistik/satwa/(:any)` | `POST` | `logistik/satwa_post/$1` |
| 3 | `api/v1/logistik/satwa/(:any)` | `DELETE` | `logistik/satwa_delete/$1` |
| 4 | `api/v1/logistik/satwa` | `OPTIONS` | `logistik/satwa_options` |
| 5 | `api/v1/logistik/satwa/(:any)` | `OPTIONS` | `logistik/satwa_options` |

### Controller (4 methods to add/refactor)

| # | Method | Action |
|---|--------|--------|
| 1 | `satwa_post($id = null)` | **REFACTOR**: JSON→multipart, base64→CI3 Upload, add `$id` param for update path, add WebP |
| 2 | `satwa_get()` | **ADD**: List satwa with jurisdiction + search (mirror `sarpras_get`) |
| 3 | `satwa_delete($id)` | **ADD**: Delete satwa with file cleanup (mirror `sarpras_delete`) |
| 4 | `satwa_options($id = null)` | **ADD**: CORS preflight dummy (mirror `sarpras_options`) |

### Critical Anti-Patterns to Fix

1. **`(object)[]` vs `new stdClass()`** — satwa uses `(object)[]` throughout; all other methods use `new stdClass()`. Standardize to `new stdClass()` for Flutter compatibility.
2. **Mixed language error messages** — satwa uses English messages ("Invalid JSON payload", "Content-Type must be..."); other methods use Indonesian. Standardize to Indonesian.
3. **No `status` in early error responses** — some satwa error responses omit the `"status"` key (e.g. line 784-786), violating the response envelope contract.
