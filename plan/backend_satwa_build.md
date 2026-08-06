# Satwa K9 & Turangga — Backend Build Report

> **Date:** 2025-07-16  
> **Module:** `tbl_satwa` (Logistik controller)  
> **Architecture:** Legacy JSON+Base64 → Multipart/form-data + WebP (Sarpras pattern)

---

## 1. Execution Summary

| File | Change |
|------|--------|
| `application/config/routes.php` | Added 5 missing satwa routes (POST `/(:any)` update, GET, DELETE `/(:any)`, OPTIONS, OPTIONS `/(:any)`) — the plain `POST` route already existed (line 97). Full block now at lines 97–102. |
| `application/controllers/Logistik.php` | Rewrote `satwa_post($id = null)` — now multipart/form-data + CI3 Upload + WebP, dual create/update. Added `satwa_get()`, `satwa_delete($id)`, `satwa_options($id = null)`. |

### Routes (final state, lines 97–102)

```php
$route['api/v1/logistik/satwa']['POST']                = 'logistik/satwa_post';
$route['api/v1/logistik/satwa/(:any)']['POST']         = 'logistik/satwa_post/$1';
$route['api/v1/logistik/satwa']['GET']                 = 'logistik/satwa_get';
$route['api/v1/logistik/satwa/(:any)']['DELETE']       = 'logistik/satwa_delete/$1';
$route['api/v1/logistik/satwa']['OPTIONS']             = 'logistik/satwa_options';
$route['api/v1/logistik/satwa/(:any)']['OPTIONS']      = 'logistik/satwa_options';
```

> **CORS note:** the `OPTIONS` wildcard route is the critical fix — without it, CI3's pre-dispatch `method_exists()` gate fails and the controller never instantiates, so the constructor's CORS headers never emit and browser preflights 404. Both OPTIONS routes now point at `satwa_options($id = null)`, which returns HTTP 200 + the constructor-emitted CORS headers.

### Controller methods (final state)

| Method | Line | Purpose |
|--------|------|---------|
| `satwa_post($id = null)` | 788 | Create (`$id` null) / update (`$id` from `/(:any)` segment) via multipart |
| `satwa_get()` | 1052 | List with strict `polda_id` jurisdiction + `?search=` (nomor_registrasi OR nama_satwa) |
| `satwa_delete($id)` | 1122 | Jurisdiction-verified delete + `foto_url` file cleanup |
| `satwa_options($id = null)` | 1192 | CORS preflight — `http_response_code(200); exit;` |

### Key architecture decisions

- **Multipart gate (flipped):** rejects `application/json` with 415 instead of requiring it — PHP only populates `$_FILES` on multipart.
- **Upload config:** `./uploads/satwa/`, `allowed_types = jpg|jpeg|png|webp`, `max_size = 2048` (2MB), `encrypt_name = TRUE`. Error mapping: size → 413, filetype/extension → 415, else 400.
- **Relative path stored:** `uploads/satwa/<encrypted>.<ext>` (fixes the old bug that stored `FCPATH`-absolute paths).
- **`foto` is optional** on create and only replaced on update when a new file is sent (Sarpras parity).
- **Rollback:** `@unlink(FCPATH . $foto_url)` on DB failure (manual, matching Sarpras — dropped the old CI3 `trans_begin()` dance).
- **Schema-aware UPDATE:** `tbl_satwa` has **no `updated_at` column**, so the UPDATE omits it (Sarpras has one; Satwa does not).
- **Validation:** `nomor_registrasi` required on create (only NOT NULL data column; unique key `uq_nomor_registrasi`), uniqueness re-checked on update excluding the current record.
- **Response envelope:** `new stdClass()` for empty `data`, HTTP 201 (create, returns `satwa_id`) / 200 (update, list, delete), Indonesian messages — matching project conventions.

---

## 2. Code Diff Proof — New `satwa_post()`

```php
/**
 * POST /api/v1/logistik/satwa        (create, $id = null)
 * POST /api/v1/logistik/satwa/(:any)  (update, $id set)
 *
 * Registrasi / perbarui aset satwa (K9 & Turangga).
 * Upload via multipart/form-data — CI3 Upload library handles real
 * image files (jpg|jpeg|png|webp, max 2MB, encrypted filename).
 * Deliberately does NOT enforce the application/json gate that
 * senjata_post uses, because PHP only populates $_FILES on multipart.
 *
 * Form fields: nomor_registrasi, jenis_satwa, nama_satwa, nama_handler,
 *              kualifikasi, jadwal_vaksin
 * File field:  foto (optional; only replaced when a new file is sent)
 * Auth: JWT (polda_id auto-inject / jurisdiction)
 */
public function satwa_post($id = null)
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

    // ── 3. EXTRACT FORM FIELDS ──
    $nomor_registrasi = $this->input->post('nomor_registrasi') !== null ? trim($this->input->post('nomor_registrasi')) : '';
    $jenis_satwa      = $this->input->post('jenis_satwa') !== null ? trim($this->input->post('jenis_satwa')) : '';
    $nama_satwa       = $this->input->post('nama_satwa') !== null ? trim($this->input->post('nama_satwa')) : '';
    $nama_handler     = $this->input->post('nama_handler') !== null ? trim($this->input->post('nama_handler')) : '';
    $kualifikasi      = $this->input->post('kualifikasi') !== null ? trim($this->input->post('kualifikasi')) : '';
    $jadwal_vaksin    = $this->input->post('jadwal_vaksin') !== null ? trim($this->input->post('jadwal_vaksin')) : '';

    $is_update = ($id !== null && $id !== '');

    // ── 4. CREATE PATH ──
    if (!$is_update) {
        // Required field (NOT NULL column in tbl_satwa)
        if ($nomor_registrasi === '') {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Validasi gagal. nomor_registrasi wajib diisi.",
                "data" => new stdClass()
            ));
            return;
        }

        // Unique nomor_registrasi rule
        $check = $this->db->query(
            "SELECT satwa_id FROM tbl_satwa WHERE nomor_registrasi = " . $this->db->escape($nomor_registrasi)
        );
        if ($check->num_rows() > 0) {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Nomor registrasi ini sudah terdaftar di pangkalan data.",
                "data" => new stdClass()
            ));
            return;
        }
    }

    // ── 5. UPDATE PATH: EXISTENCE & JURISDICTION CHECK ──
    if ($is_update) {
        $satwa = $this->db->query(
            "SELECT satwa_id FROM tbl_satwa "
            . "WHERE satwa_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$satwa) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Data satwa tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        $set = array();

        if ($nomor_registrasi !== '') {
            // Uniqueness check — exclude current record
            $check = $this->db->query(
                "SELECT satwa_id FROM tbl_satwa WHERE nomor_registrasi = " . $this->db->escape($nomor_registrasi)
                . " AND satwa_id != " . $this->db->escape($id)
            );
            if ($check->num_rows() > 0) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Nomor registrasi ini sudah terdaftar di pangkalan data.",
                    "data" => new stdClass()
                ));
                return;
            }
            $set[] = "nomor_registrasi = '" . $this->db->escape_str($nomor_registrasi) . "'";
        }

        if ($jenis_satwa !== '') {
            $set[] = "jenis_satwa = '" . $this->db->escape_str($jenis_satwa) . "'";
        }

        if ($nama_satwa !== '') {
            $set[] = "nama_satwa = '" . $this->db->escape_str($nama_satwa) . "'";
        }

        if ($nama_handler !== '') {
            $set[] = "nama_handler = '" . $this->db->escape_str($nama_handler) . "'";
        }

        if ($kualifikasi !== '') {
            $set[] = "kualifikasi = '" . $this->db->escape_str($kualifikasi) . "'";
        }

        if ($jadwal_vaksin !== '') {
            $set[] = "jadwal_vaksin = '" . $this->db->escape_str($jadwal_vaksin) . "'";
        }
    }

    // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
    //    Only when a real file was submitted; foto is optional and
    //    on UPDATE it is only replaced when a new file is sent.
    //    Field name is `foto` (matches Flutter MultipartRequest).
    $foto_url = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_path = FCPATH . 'uploads/satwa/';
        if (!is_dir($upload_path)) {
            mkdir($upload_path, 0755, true);
        }

        $config = array(
            'upload_path'   => $upload_path,          // ./uploads/satwa/ (app root)
            'allowed_types' => 'jpg|jpeg|png|webp',   // WebP supported
            'max_size'      => 2048,                  // 2MB (KB)
            'encrypt_name'  => TRUE                   // random filename
        );
        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('foto')) {
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
        $foto_url = 'uploads/satwa/' . $upload_data['file_name'];
    }

    // ── 7. CREATE: INSERT ──
    if (!$is_update) {
        $satwa_id = generate_uuid4();

        $sql = "INSERT INTO tbl_satwa (satwa_id, polda_id, nomor_registrasi, jenis_satwa, nama_satwa, nama_handler, kualifikasi, jadwal_vaksin, foto_url, created_at) "
             . "VALUES ("
             . "'" . $this->db->escape_str($satwa_id) . "', "
             . "'" . $this->db->escape_str($polda_id) . "', "
             . "'" . $this->db->escape_str($nomor_registrasi) . "', "
             . ($jenis_satwa !== '' ? "'" . $this->db->escape_str($jenis_satwa) . "'" : "NULL") . ", "
             . ($nama_satwa !== '' ? "'" . $this->db->escape_str($nama_satwa) . "'" : "NULL") . ", "
             . ($nama_handler !== '' ? "'" . $this->db->escape_str($nama_handler) . "'" : "NULL") . ", "
             . ($kualifikasi !== '' ? "'" . $this->db->escape_str($kualifikasi) . "'" : "NULL") . ", "
             . ($jadwal_vaksin !== '' ? "'" . $this->db->escape_str($jadwal_vaksin) . "'" : "NULL") . ", "
             . ($foto_url !== null ? "'" . $this->db->escape_str($foto_url) . "'" : "NULL") . ", "
             . "NOW()"
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            // Rollback: delete saved file
            if ($foto_url !== null) {
                @unlink(FCPATH . $foto_url);
            }
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan data satwa",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // SUCCESS: HTTP 201 Created
        $this->output->set_content_type('application/json')->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Data satwa berhasil didaftarkan.",
            "data" => array(
                "satwa_id" => $satwa_id
            )
        ));
        return;
    }

    // ── 8. UPDATE: EXECUTE (jurisdiction re-enforced in WHERE) ──
    if ($foto_url !== null) {
        $set[] = "foto_url = '" . $this->db->escape_str($foto_url) . "'";
    }

    if (empty($set)) {
        $this->output->set_content_type('application/json')->set_status_header(400);
        echo json_encode(array(
            "message" => "Tidak ada field yang dapat diperbarui.",
            "status" => 400,
            "data" => new stdClass()
        ));
        return;
    }

    $update = $this->db->query(
        "UPDATE tbl_satwa SET " . implode(', ', $set) . " "
        . "WHERE satwa_id = " . $this->db->escape($id)
        . " AND polda_id = " . $this->db->escape($polda_id)
    );

    if (!$update) {
        // Rollback: delete newly saved file
        if ($foto_url !== null) {
            @unlink(FCPATH . $foto_url);
        }
        $this->output->set_content_type('application/json')->set_status_header(500);
        echo json_encode(array(
            "message" => "Gagal memperbarui data satwa",
            "status" => 500,
            "data" => new stdClass()
        ));
        return;
    }

    // SUCCESS: HTTP 200 OK
    $this->output->set_content_type('application/json')->set_status_header(200);
    echo json_encode(array(
        "status" => 200,
        "message" => "Data satwa berhasil diperbarui.",
        "data" => new stdClass()
    ));
}
```

---

## 3. Verification

- `php -l application/controllers/Logistik.php` → **No syntax errors detected**
- `php -l application/config/routes.php` → **No syntax errors detected**
- `grep function satwa_` → all 4 methods present (post L788, get L1052, delete L1122, options L1192)
- `grep satwa` routes.php → all 6 routes present (lines 97–102)
- `grep save_base64_file|raw_input_stream|foto_fisik` → zero hits inside the satwa code path; remaining hits belong to `senjata_post`/`senjata_put` (legacy JSON contract, intentionally untouched)

## 4. Out of Scope / Follow-ups

- `uploads/satwa/` directory is created on demand by `mkdir()` — no seeder change needed.
- Old rows with `FCPATH`-absolute `foto_url` values remain in DB; Flutter should treat `foto_url` starting with `/` or `http` as legacy. A data migration could normalize these, but existing DELETE cleanup already skips them (`strpos($foto_url, 'uploads/') === 0` guard).
- E2E Playwright coverage for satwa CRUD (multipart + WebP) is not part of this build and should be added in a follow-up.
