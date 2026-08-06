# Backend Sarpras — Edit/Update Bug Audit: CORS / Failed to Fetch on POST Update

**Date:** 2026-08-06  
**Mode:** DEBUG (read-only investigation)  
**Symptom:** Flutter client gets "CORS/Failed to fetch" when submitting an update to `/api/v1/logistik/sarpras/{id}`.

---

## 1. Route Check

```
$ grep sarpras application/config/routes.php
```

| Line | Route | Handler |
|------|-------|---------|
| 98 | `api/v1/logistik/sarpras ['POST']` | `logistik/sarpras_post` |
| **99** | **`api/v1/logistik/sarpras/(:any) ['POST']`** | **`logistik/sarpras_post/$1`** |
| 100 | `api/v1/logistik/sarpras ['GET']` | `logistik/sarpras_get` |
| 101 | `api/v1/logistik/sarpras/(:any) ['DELETE']` | `logistik/sarpras_delete/$1` |
| 102 | `api/v1/logistik/sarpras ['OPTIONS']` | `logistik/sarpras_options` |
| 103 | `api/v1/logistik/sarpras/(:any) ['OPTIONS']` | `logistik/sarpras_options` |

**✅ The POST `(:any)` update route exists at line 99.** It correctly maps `POST /api/v1/logistik/sarpras/{id}` to `Logistik::sarpras_post($id)`.

---

## 2. Controller Crash Analysis — `sarpras_post($id = null)` (lines 1066–1335)

### 2.1 Auth Gate (lines 1068–1079)

```php
$payload = get_jwt_payload($this);
if (!$payload) { ... 401 JSON return; }
$polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
```

No crash path. If token is missing/invalid → proper JSON 401 with CORS headers (constructor already emitted them).

### 2.2 Content-Type Gate (lines 1081–1092)

```php
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($content_type, 'application/json') !== false) {
    ... 415 JSON return;
}
```

- ✅ `$_SERVER['CONTENT_TYPE']` is safely tested with `isset()` before access.
- ✅ If `Content-Type` is missing → `$content_type = ''` → gate passes.
- ✅ If `Content-Type: multipart/form-data; boundary=...` → gate passes.
- ✅ If `Content-Type: application/json` → returns 415 **with CORS headers** (constructor ran first) — NOT a CORS error, but a functional mismatch if the Flutter client sends JSON field-only updates.

No crash. No CORS issue here.

### 2.3 Form Field Extraction (lines 1094–1099)

```php
$kode_barang     = $this->input->post('kode_barang')     !== null ? trim(...) : '';
$nama_barang     = $this->input->post('nama_barang')     !== null ? trim(...) : '';
$kategori        = $this->input->post('kategori')        !== null ? trim(...) : '';
$kondisi         = $this->input->post('kondisi')         !== null ? trim(...) : '';
$tahun_pengadaan = $this->input->post('tahun_pengadaan') !== null ? trim(...) : '';
```

- ✅ Every call is guarded: `!== null ? trim : ''`. Missing POST keys produce `null` → `''`. No crash.
- ✅ `$this->input->post()` reads from `$_POST`, which PHP populates from `multipart/form-data` body parts. For empty body, `$_POST` is empty → all fields become `''`.
- ⚠️ **Functional note:** If Flutter sends an update with form field names that don't match (e.g., `nama` instead of `nama_barang`), the field is silently empty — the existing value is NOT cleared (update path only builds `$set` for non-empty fields), but neither is the value updated. No crash.

### 2.4 Update Existence & Jurisdiction Check (lines 1144–1159)

```php
$sarpras = $this->db->query(
    "SELECT sarpras_id FROM tbl_sarpras "
    . "WHERE sarpras_id = " . $this->db->escape($id)
    . " AND polda_id = " . $this->db->escape($polda_id)
)->row_array();
```

- ✅ Table: `tbl_sarpras` — correct.
- ✅ PK: `sarpras_id` — correct.
- ✅ `$this->db->escape($id)` — properly escapes the UUID from the URL segment.
- ✅ `$this->db->escape($polda_id)` — escapes the int-cast `$polda_id`.
- ✅ If row not found → `row_array()` returns empty array → `!$sarpras` → 404 JSON with CORS headers. No crash.

### 2.5 `$set` Array Construction (lines 1161–1204)

For update, each field is checked with `!== ''` before building the `$set` array. All values use `$this->db->escape_str()`. No crash path.

### 2.6 🔬 FILE UPLOAD — The Critical Section (lines 1207–1246)

```php
$foto_url = null;
if (isset($_FILES['foto_url']) && $_FILES['foto_url']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_path = FCPATH . 'uploads/sarpras/';
    if (!is_dir($upload_path)) {
        mkdir($upload_path, 0755, true);
    }
    $config = array(
        'upload_path'   => $upload_path,
        'allowed_types' => 'jpg|jpeg|png|webp',
        'max_size'      => 2048,
        'encrypt_name'  => TRUE
    );
    $this->load->library('upload', $config);
    if (!$this->upload->do_upload('foto_url')) {
        ... return JSON error (400/413/415) WITH CORS headers;
    }
    $upload_data = $this->upload->data();
    $foto_url = 'uploads/sarpras/' . $upload_data['file_name'];
}
```

#### "What happens if `$_FILES['foto_url']` is EMPTY or not set?" — ANSWER: no crash.

| Scenario | `isset($_FILES['foto_url'])` | Result | Crash? |
|----------|------------------------------|--------|--------|
| Flutter sends NO file field at all | `false` | Entire upload block SKIPPED. `$foto_url` stays `null`. Code proceeds to update. | ✅ No |
| Flutter sends empty file field (name exists, no bytes) | `true`; `error === UPLOAD_ERR_NO_FILE` (4) | Entire upload block SKIPPED. `$foto_url` stays `null`. | ✅ No |
| Flutter sends a real file | `true`; `error !== UPLOAD_ERR_NO_FILE` | Upload attempted. CI3 handles success/failure gracefully. | ✅ No |
| **Flutter sends file as field name `foto` (not `foto_url`)** | `false` (key `foto_url` doesn't exist) | Upload SKIPPED silently. `$foto_url` stays `null`. Photo is **not saved**, but update still succeeds with other fields. | ✅ No crash, but photo is lost |

⚠️ **Field name mismatch is a functional bug, not a crash.** If Flutter names the file field `foto` instead of `foto_url`, the upload is silently skipped — the `$set` array still contains any text fields, the UPDATE runs, returns 200, but the photo is never saved. The client thinks the update succeeded but the photo didn't change.

#### `$this->load->library('upload', $config)` — can it crash?

- CI3's `Upload` library at `system/libraries/Upload.php` is a core file. It does not throw exceptions.
- `do_upload('foto_url')` returns `false` on failure (bad file, wrong type, too large, no upload_dir, etc.) — all handled by the `if (!$this->upload->do_upload(...))` block.
- If the directory doesn't exist, `do_upload()` sets an error → returns false → our code returns JSON error. No crash.
- In our E2E tests, WebP and JPEG uploads succeeded cleanly.

### 2.7 UPDATE Execution (lines 1293–1334)

```php
if ($foto_url !== null) {
    $set[] = "foto_url = '" . $this->db->escape_str($foto_url) . "'";
}
if (empty($set)) {
    ... 400 "Tidak ada field yang dapat diperbarui." (CORS headers present)
}
$update = $this->db->query(
    "UPDATE tbl_sarpras SET " . implode(', ', $set) . ", updated_at = NOW() "
    . "WHERE sarpras_id = " . $this->db->escape($id)
    . " AND polda_id = " . $this->db->escape($polda_id)
);
```

- ✅ `$foto_url` is only added to `$set` when a new file was successfully uploaded.
- ✅ `empty($set)` → 400 prevents executing `UPDATE` with empty SET clause (which would be a SQL syntax error → DB-level crash).
- ✅ Table: `tbl_sarpras`.
- ✅ Column: `updated_at` exists in the schema (`datetime DEFAULT NULL ON UPDATE current_timestamp()`).
- ✅ `implode(', ', $set)` produces valid SQL — all values are `escape_str()`'d.
- ✅ If `$this->db->query()` fails (DB down, deadlock): `!$update` → 500 JSON with CORS + file rollback.

No crash path.

### 2.8 What if DB `db_debug = TRUE` and a SQL error occurs?

In CI3 with `db_debug = TRUE` (the default in `development` environment — `database.php:85`), a failed query calls `show_error()`, which outputs an HTML error page and terminates. This page does **NOT** go through the controller and has **NO CORS headers**.

However, the SQL in `sarpras_post` is syntactically valid:
- All values are `escape_str()`'d (no unescaped quotes).
- Column names are hardcoded and verified against the schema.
- `empty($set)` is checked before the UPDATE.
- The INSERT path also uses validated columns only.

A SQL syntax error is extremely unlikely — it would require the database schema to have changed since the code was written (e.g., a column was dropped).

---

## 3. Verdict

### ✅ The `sarpras_post()` update path is crash-free in its current form.

Every code path through the update logic either:
1. Returns a proper JSON response **with CORS headers** (constructor already emitted them), or
2. Gracefully degrades (empty POST body → 400; missing file → skipped; invalid file → 415/413).

There is **no PHP fatal error path**, **no unguarded `$_FILES`/`$_POST` access**, **no SQL injection**, **no table/column name typo**.

### 🔴 The "CORS/Failed to fetch" on update is almost certainly the SAME root cause as the previous audit:

**The Flutter client is sending `PUT /api/v1/logistik/sarpras/{id}`, not `POST`.**

Why:
- `senjata` and `amunisi` modules (the established patterns in this codebase) use **`PUT` for updates** (routes.php lines 87, 95).
- Flutter naturally follows PUT-for-update REST convention.
- The `sarpras` module was designed with `POST /{id}` for updates (a deliberate choice to support multipart file uploads, since PHP doesn't populate `$_FILES` on PUT).
- There is **no `PUT` route** for sarpras (verified above — only `POST` and `DELETE` `(:any)` routes exist).
- `PUT /api/v1/logistik/sarpras/{id}` → no route match → CI3 default `show_404()` → HTML 404 page with **zero CORS headers** → browser blocks → "Failed to fetch."

This is documented in detail as **Issue A** in the previous audit: `plan/backend_sarpras_bug_audit.md`.

### 🟡 Secondary: File field name mismatch

If Flutter sends the photo under field name `foto` (not `foto_url`), it is **silently dropped** — no crash, but the photo is never saved to disk or database. The client gets a 200 success with no indication that the photo was skipped.

---

## 4. Quick Diagnostic Checklist

| Check | Expected | Actual |
|-------|----------|--------|
| HTTP method Flutter uses for update | `POST` | Likely `PUT` (per REST convention matching senjata/amunisi) |
| File field name in multipart body | `foto_url` | Possibly `foto` |
| Content-Type header | `multipart/form-data` | Unknown; if `application/json` → 415, not CORS issue |
| URL trailing slash | `/id` (no slash) | If `/id/` → CI3 404, no CORS |

**Action:** Capture the actual HTTP request from Flutter (DevTools Network tab) — the HTTP method, Content-Type, and field names will immediately pinpoint which of these applies.
