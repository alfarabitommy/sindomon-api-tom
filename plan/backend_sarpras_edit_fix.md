# Backend Sarpras — Edit Fix: Multipart Field Name `foto_url` → `foto`

**Date:** 2026-08-06
**Mode:** CODE/EXECUTE (fix applied + live E2E verified)
**Bug fixed:** Flutter sends the multipart file under field name `foto`; the CI3 controller was reading `$_FILES['foto_url']` — silently dropping every photo upload.

---

## 1. Execution Summary

### File Modified

**`application/controllers/Logistik.php`** — only the `sarpras_post($id = null)` upload block (step 6, ~line 1207).

### Exact Changes

| Before | After |
|--------|-------|
| `isset($_FILES['foto_url'])` | `isset($_FILES['foto'])` |
| `$_FILES['foto_url']['error']` | `$_FILES['foto']['error']` |
| `$this->upload->do_upload('foto_url')` | `$this->upload->do_upload('foto')` |

### Deliberately NOT changed (correct as-is)

- `$foto_url` PHP variable — holds the saved relative path; name is internal.
- `foto_url` in SQL (`INSERT ... foto_url ...`, `UPDATE ... foto_url = ...`) — that is the **database column name** in `tbl_sarpras`; must stay.
- `'uploads/sarpras/' . $upload_data['file_name']` — the stored path value.
- All other modules (`senjata`, `satwa`) — untouched; they use Base64-in-JSON, not multipart.

### Live E2E Verification (curl, real WebP files)

| Test | Field name | Result |
|------|-----------|--------|
| `POST` create + photo | `foto` | ✅ 201, encrypted `.webp` written to `uploads/sarpras/` |
| `POST /{id}` update + photo | `foto` | ✅ 200, `foto_url` replaced with new encrypted file |
| `POST /{id}` field-only (no file) | — | ✅ 200, photo untouched |
| `DELETE /{id}` | — | ✅ 200, row photo unlinked from disk |
| `php -l` | — | ✅ No syntax errors |

*Note:* replacing a photo on UPDATE leaves the previous file orphaned on disk (update does not unlink the old photo) — pre-existing behavior consistent with `senjata_put`/`satwa_post`; not part of this fix.

---

## 2. Code Diff Proof

```diff
@@ sarpras_post() — file upload block (application/controllers/Logistik.php) @@

         // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
         //    Only when a real file was submitted; foto_url is optional and
         //    on UPDATE it is only replaced when a new file is sent.
+        //    Field name is `foto` (matches Flutter MultipartRequest).
         $foto_url = null;
-        if (isset($_FILES['foto_url']) && $_FILES['foto_url']['error'] !== UPLOAD_ERR_NO_FILE) {
+        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
             $upload_path = FCPATH . 'uploads/sarpras/';
             if (!is_dir($upload_path)) {
                 mkdir($upload_path, 0755, true);
             }

             $config = array(
                 'upload_path'   => $upload_path,          // ./uploads/sarpras/ (app root)
                 'allowed_types' => 'jpg|jpeg|png|webp',   // WebP supported
                 'max_size'      => 2048,                  // 2MB (KB)
                 'encrypt_name'  => TRUE                   // random filename
             );
             $this->load->library('upload', $config);

-            if (!$this->upload->do_upload('foto_url')) {
+            if (!$this->upload->do_upload('foto')) {
                 $error = $this->upload->display_errors('', '');
                 $err = strtolower($error);
                 if (strpos($err, 'size') !== false) {
                     $status = 413; // file too large
                 } elseif (strpos($err, 'filetype') !== false || strpos($err, 'extension') !== false) {
                     $status = 415; // disallowed type
                 } else {
                     $status = 400;
                 }
                 ...
             }

             $upload_data = $this->upload->data();
             $foto_url = 'uploads/sarpras/' . $upload_data['file_name'];
         }
```

### Resulting Contract (Flutter → Backend)

```
POST /api/v1/logistik/sarpras            multipart, file field: foto
POST /api/v1/logistik/sarpras/{id}       multipart, file field: foto (optional)
```

CI3's `do_upload('foto')` now reads the exact field name Flutter sends via `MultipartFile('foto', ...)` — photo uploads are no longer silently dropped.
