# Backend Senjata Update — Implementation Results

## 1. Execution Summary

Two CI3 files modified:

| File | Change |
|------|--------|
| `application/config/routes.php` | Added PUT route for Senjata update (line 86) |
| `application/controllers/Logistik.php` | Added `senjata_put($senjata_id)` method after `senjata_get()` |

No Dart code touched. No other files changed.

## 2. Code Diff Proof

### New Route (`application/config/routes.php`)

```php
$route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';
$route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';   // NEW
```

### Image Handling Inside `senjata_put` (foto_fisik — optional, keeps existing image when absent)

```php
// ── 6. FOTO (opsional): hanya update bila base64 baru dikirim ──
if (array_key_exists('foto_fisik', $input) && $input['foto_fisik'] !== null && $input['foto_fisik'] !== '') {
    $upload_dir = FCPATH . 'uploads/senjata/';
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg'];
    $result = save_base64_file($input['foto_fisik'], $upload_dir, $allowed_mimes, 512000);

    if (!$result['success']) {
        $status = isset($result['status']) ? $result['status'] : 400;
        $this->output->set_content_type('application/json')->set_status_header($status);
        echo json_encode(array(
            "message" => $result['error'],
            "status" => $status,
            "data" => new stdClass()
        ));
        return;
    }

    $foto_url = 'uploads/senjata/' . $result['file_name'];
    $set[] = "foto_url = '" . $this->db->escape_str($foto_url) . "'";
}
```

Key behaviors:
- `foto_fisik` absent/null/empty → `foto_url` column untouched (existing image preserved).
- `foto_fisik` present → saved via `save_base64_file()`, new `foto_url` written; on DB failure the new file is `@unlink`ed (rollback, mirrors `senjata_post`).
- All text fields (`nomor_seri`, `kategori_id`, `tahun_pengadaan`, `status_kelayakan`) are built into the SET clause only when provided — partial updates supported.

## 3. Verification Status

- **UUID handling: CONFIRMED.** Route uses `(:any)`, matching the existing `personil` pattern (`$route['api/v1/sdm/personil/(:any)']['PUT']`). `senjata_id` is a UUID string; it is passed as `$1` into `senjata_put($senjata_id)` and escaped via `$this->db->escape()` / `$this->db->escape_str()` in all queries (existence check, uniqueness check, WHERE clause).
- **Uniqueness excludes self: CONFIRMED.** Duplicate `nomor_seri` check appends `AND senjata_id != [current]`.
- **Jurisdiction enforced: CONFIRMED.** Existence check and UPDATE both scope `WHERE polda_id = [JWT polda_id]`, so a record can only be updated by its owning polda.
- **`php -l` syntax check: PASSED** on both `application/controllers/Logistik.php` and `application/config/routes.php`.

### Final Endpoint Contract

```
PUT /api/v1/logistik/senjata/{senjata_id}
Content-Type: application/json
Authorization: Bearer <JWT>

Body (all optional, partial updates):
{
  "nomor_seri": "...",        // unique, excludes current record
  "kategori_id": 1,
  "tahun_pengadaan": "2020",
  "status_kelayakan": "...",
  "foto_fisik": "data:image/jpeg;base64,..."  // omit to keep existing photo
}

Responses: 200 updated | 400 no updatable field/bad JSON | 401 no token
           | 404 not found / not in jurisdiction | 415 wrong content-type
           | 422 nomor_seri duplicate | 500 save failure
```
