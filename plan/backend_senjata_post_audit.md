# Backend Audit: `Logistik::senjata_post()`

File: `application/controllers/Logistik.php` (lines 32–161)
Helper: `application/helpers/base64_file_helper.php` → `save_base64_file()`

## Expected JSON Payload

Keys read by `senjata_post()`:

| Key | Type | Source | Required |
|---|---|---|---|
| `nomor_seri` | string | body | — |
| `kategori_id` | int | body | — |
| `tahun_pengadaan` | string | body | — |
| `status_kelayakan` | string | body | — |
| `foto_fisik` | string (base64) | body | **YES (hard 422 gate)** |
| `polda_id` | int | **JWT, not body** | auto-injected |

```json
{
  "nomor_seri": "SN-2026-0001",
  "kategori_id": 1,
  "tahun_pengadaan": "2026",
  "status_kelayakan": "layak",
  "foto_fisik": "data:image/jpeg;base64,/9j/4AAQSkZJRg..."
}
```

Notes:
- `polda_id` is never read from the body (line 120): it is taken from the JWT payload. Sending `polda_id` in the body is harmless but ignored.
- Validation gate order (line 77) is the **mandatory photo check**, which runs BEFORE the unique-serial check (line 88). So a missing/empty image always produces the 422 photo error, regardless of other fields.
- Other fields are not validated for presence — only `foto_fisik` is mandatory.

## Image Format Rules

`save_base64_file()` (helper) accepts **both** formats:

1. **Raw base64** string (no header) — decoded directly.
2. **Data URI** with header, e.g. `data:image/jpeg;base64,<data>` — line 17 checks `strpos($base64_input, 'data:') === 0`, then `explode('base64,', $input, 2)` (line 18) strips the prefix. If the string starts with `data:` but contains no `base64,`, it returns 400 "Format base64 tidak valid".

Constraints applied by the helper:
- Allowed MIME (magic-byte detection via `finfo_buffer`): `image/jpeg`, `image/png`, `image/jpg` → else **415** "Format file tidak didukung".
- Max size: `512000` bytes (500 KB) passed by `senjata_post()` (line 104). Enforced pre-decode via encoded-length check and post-decode via binary length → else **400** "Ukuran file melebihi batas".
- Output filename: random hex + ext derived from detected MIME.

The mandatory-photo gate only checks non-null/non-empty on the string (line 77); format/type/size validation happens later inside `save_base64_file()`.

## Root Cause

Flutter sends `{"foto": "..."}`, but the backend reads `$input['foto_fisik']` (line 74). With no `foto_fisik` key present, it defaults to `''`, tripping the mandatory-photo rule (line 77) → **422** `"Validasi gagal. Foto bukti fisik senjata wajib dilampirkan."`

The `foto` key is never read anywhere in `senjata_post()`.

**Fix (Flutter side):** rename the payload key `foto` → `foto_fisik`, keep base64 (raw or Data URI both work), keep size ≤ 500 KB, and send only `image/jpeg|png|jpg` data.
