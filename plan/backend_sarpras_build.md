# Backend Sarpras & Altmatsus — Build Report

**Date:** 2026-08-06
**Mode:** CODE/EXECUTE (verified with live E2E smoke tests against MySQL `sindomondb`)
**Module:** `tbl_sarpras` — CRUD + Multipart Image Upload (jpg|jpeg|png|webp, ≤2MB)

---

## 1. Execution Summary

### Files Modified

| File | Change |
|------|--------|
| `application/config/routes.php` | Added **6 routes** for the Sarpras module (lines 98-103) |
| `application/controllers/Logistik.php` | Added **4 methods**: `sarpras_options()`, `sarpras_get()`, `sarpras_post($id = null)`, `sarpras_delete($id)` (lines 977-1366) |
| `application/config/mimes.php` | Added `'webp' => 'image/webp'` entry (line 89) — **required** for CI3 Upload library to accept WebP; without it every WebP upload fails with 415 "filetype not allowed" |

### Routes Added (`routes.php`)

```php
$route['api/v1/logistik/sarpras']['POST'] = 'logistik/sarpras_post';
$route['api/v1/logistik/sarpras/(:any)']['POST'] = 'logistik/sarpras_post/$1';
$route['api/v1/logistik/sarpras']['GET'] = 'logistik/sarpras_get';
$route['api/v1/logistik/sarpras/(:any)']['DELETE'] = 'logistik/sarpras_delete/$1';
$route['api/v1/logistik/sarpras']['OPTIONS'] = 'logistik/sarpras_options';
$route['api/v1/logistik/sarpras/(:any)']['OPTIONS'] = 'logistik/sarpras_options';
```

### Design Decisions

1. **UPDATE uses POST + `$id` in URL** (as specified) — PHP only populates `$_POST`/`$_FILES` on POST, so `multipart/form-data` updates cannot work via PUT.
2. **No JSON content-type gate** — `sarpras_post()` rejects only `application/json` (415); every other content type passes, so multipart is never blocked like in `senjata_post()`.
3. **`kondisi` ENUM validated** server-side against `Baik | Rusak Ringan | Rusak Berat` (422).
4. **`kode_barang` UNIQUE enforced** on create and on update (excluding current record) → 422.
5. **Jurisdiction**: `polda_id` auto-injected from JWT on create; existence+ownership check on update/delete (404 if foreign).
6. **Upload errors mapped to HTTP codes**: size → `413`, disallowed type → `415`, other → `400`.
7. **Rollback**: uploaded file is `unlink()`ed if the DB INSERT/UPDATE fails.
8. **Delete hardening**: `DELETE` re-enforces `polda_id` in the WHERE clause (TOCTOU parity with UPDATE path) and unlinks the local photo file (`uploads/*` only — remote placeholder URLs are skipped).

### E2E Verification Results (live, curl multipart)

| Test | Result |
|------|--------|
| `OPTIONS /api/v1/logistik/sarpras/(:any)` preflight | ✅ 200 + full CORS headers |
| `GET` list (admin) | ✅ 200, 30 seeded rows |
| `GET ?search=kode_barang` / `?search=nama_barang` | ✅ 200, filtered |
| `POST` create + WebP file (142 B) | ✅ 201 + UUID; file saved as encrypted hex name under `uploads/sarpras/` |
| `POST /:any` update + new WebP | ✅ 200; `foto_url` replaced (new encrypted filename) |
| `POST /:any` update, field-only (no file) | ✅ 200; `foto_url` untouched |
| `POST` create + JPEG file | ✅ 201 |
| Invalid `kondisi` | ✅ 422 |
| Duplicate `kode_barang` | ✅ 422 |
| Disallowed type (`.txt`) | ✅ 415 |
| Oversize (2.8 MB > 2 MB) | ✅ 413 |
| `DELETE` then re-`DELETE` | ✅ 200 then 404 |
| Operator Polda jurisdiction (GET scope + foreign DELETE) | ✅ only own `polda_id` rows; foreign → 404 |

---

## 2. Code Diff Proof — `sarpras_post()` multipart handling

The full method lives at `application/controllers/Logistik.php:1066-1341`. The multipart upload core (steps 1-2 and 6):

```php
public function sarpras_post($id = null)
{
    // ── 1. AUTH: JWT ──
    $payload = get_jwt_payload($this);
    if (!$payload) {
        $this->output->set_status_header(401);
        echo json_encode(array(
            "message" => "Token tidak ditemukan",
            "status" => 401,
            "data" => new stdClass()
        ));
        return;
    }
    $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

    // ── 2. CONTENT-TYPE: JSON payloads rejected (multipart is the only
    //    way PHP populates $_FILES; do NOT block multipart like senjata_post) ──
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($content_type, 'application/json') !== false) {
        $this->output->set_content_type('application/json')->set_status_header(415);
        echo json_encode(array(
            "message" => "Content-Type harus multipart/form-data (upload file tidak mendukung JSON).",
            "status" => 415,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 3. EXTRACT FORM FIELDS ($this->input->post(), NOT raw_input_stream) ──
    $kode_barang     = $this->input->post('kode_barang') !== null ? trim($this->input->post('kode_barang')) : '';
    $nama_barang     = $this->input->post('nama_barang') !== null ? trim($this->input->post('nama_barang')) : '';
    $kategori        = $this->input->post('kategori') !== null ? trim($this->input->post('kategori')) : '';
    $kondisi         = $this->input->post('kondisi') !== null ? trim($this->input->post('kondisi')) : '';
    $tahun_pengadaan = $this->input->post('tahun_pengadaan') !== null ? trim($this->input->post('tahun_pengadaan')) : '';

    $is_update = ($id !== null && $id !== '');
    // ... validation (422) + uniqueness checks omitted for brevity ...

    // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
    $foto_url = null;
    if (isset($_FILES['foto_url']) && $_FILES['foto_url']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_path = FCPATH . 'uploads/sarpras/';      // ./uploads/sarpras/ (app root)
        if (!is_dir($upload_path)) {
            mkdir($upload_path, 0755, true);
        }

        $config = array(
            'upload_path'   => $upload_path,
            'allowed_types' => 'jpg|jpeg|png|webp',   // WebP supported
            'max_size'      => 2048,                  // 2MB (KB)
            'encrypt_name'  => TRUE                   // random filename
        );
        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('foto_url')) {
            $error = $this->upload->display_errors('', '');
            $err = strtolower($error);
            if (strpos($err, 'size') !== false) {
                $status = 413; // file too large
            } elseif (strpos($err, 'filetype') !== false || strpos($err, 'extension') !== false) {
                $status = 415; // disallowed type
            } else {
                $status = 400;
            }
            $this->output->set_content_type('application/json')->set_status_header($status);
            echo json_encode(array(
                "message" => "Gagal mengunggah foto: " . $error,
                "status" => $status,
                "data" => new stdClass()
            ));
            return;
        }

        $upload_data = $this->upload->data();
        $foto_url = 'uploads/sarpras/' . $upload_data['file_name'];
    }

    // ── 7. CREATE: INSERT (UUID) — or ──
    // ── 8. UPDATE: "UPDATE tbl_sarpras SET ... , foto_url = ... , updated_at = NOW()" ──
    //      foto_url only added to $set when a NEW file was uploaded.
}
```

### Key diff vs `senjata_post()` (the previous Base64-only pattern)

| Aspect | `senjata_post()` (old pattern) | `sarpras_post()` (new) |
|--------|-------------------------------|------------------------|
| Content-Type | `application/json` required (else 415) | `multipart/form-data` accepted; only `application/json` rejected (415) |
| File input | Base64 string in JSON body → `save_base64_file()` helper | `$_FILES['foto_url']` → CI3 `$this->upload->do_upload()` |
| Allowed types | jpeg/png only, no WebP | `jpg\|jpeg\|png\|webp` |
| Size limit | 512 KB (helper constant) | 2 MB (`max_size = 2048`) |
| Filename | random hex + MIME ext (helper) | encrypted random name (`encrypt_name = TRUE`) |
| Text fields | `json_decode($this->input->raw_input_stream)` | `$this->input->post()` |

### Prerequisite Fix: `config/mimes.php`

CI3's Upload library resolves `allowed_types` extensions → MIME via `application/config/mimes.php`. `webp` was absent, so even valid WebP files failed with 415. Added:

```php
'webp'	=>	'image/webp',
```

---

## 3. Flutter Client Contract

```
POST   /api/v1/logistik/sarpras                 → 201 {sarpras_id}
POST   /api/v1/logistik/sarpras/{sarpras_id}    → 200 (update)
GET    /api/v1/logistik/sarpras?search=         → 200 [ ... ]
DELETE /api/v1/logistik/sarpras/{sarpras_id}    → 200
OPTIONS ...                                     → 200 + CORS headers
```

**Multipart fields:** `kode_barang`*, `nama_barang`*, `kategori`, `kondisi`, `tahun_pengadaan`, `foto_url` (file; optional, replaces on update).
**Content-Type:** `multipart/form-data; boundary=...` (Flutter `http.MultipartRequest`).
**Auth:** `Authorization: Bearer <jwt_token>`.
**Errors:** 401 no token · 404 not found/foreign · 413 file >2MB · 415 bad type or JSON payload · 422 validation/duplicate · 500 DB failure.

*required on create.
