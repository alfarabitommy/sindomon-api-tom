# Plan: Base64 Image Format Handover to Flutter Dev

## Goal
Produce exactly one Markdown snippet for the Flutter developer specifying the exact Base64 string format the CodeIgniter 3 backend expects, backed by real code inspection and a valid dummy payload.

## Phase 1 — Code Inspection (DONE)

### File: `application/helpers/base64_file_helper.php:15-23`
The central helper `save_base64_file()` handles both formats:

```php
if (strpos($base64_input, 'data:') === 0) {
    $parts = explode('base64,', $base64_input, 2);
    ...
    $base64_input = $parts[1];
}
```

**Behavior:**
- If string starts with `data:` → strips everything up to and including `base64,`, then decodes the remainder.
- If string does NOT start with `data:` → passes raw string directly to `base64_decode()`.

### Files: `application/controllers/Logistik.php:101-104`, `Kamtibmas.php:116-118`
Both controllers call `save_base64_file()` with the raw payload value (no pre-processing). MIME validation happens inside the helper via `finfo_buffer()` on the decoded binary.

**Finding: both formats are accepted.** No format errors reported in production.

### Recommendation rationale
- Flutter's `base64Encode()` returns raw base64 (no prefix), so raw is the path of least surprise.
- Sending the prefix works too, but raw is simpler and avoids potential mobile encoding edge-cases with `data:` URI length limits on certain HTTP clients.
- The backend logs the MIME type automatically from magic bytes, so the prefix's embedded MIME is redundant.

## Phase 2 — Dummy Data

Use the well-known 1x1 transparent PNG:

**Raw base64 (no prefix):**
```
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
```

**With prefix:** `data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==`

## Phase 3 — Output Structure

A single Markdown block addressed to the Flutter developer containing:

1. **Clear format instruction:** "Send raw base64 — do NOT include `data:image/...;base64,` prefix."
2. **Rationale one-liner:** "The backend accepts both but raw is simpler. Prefix is silently stripped if accidentally included."
3. **JSON payload example** for the `senjata_post` endpoint:
   ```json
   {
       "nomor_seri": "SN-001",
       "kategori_id": 1,
       "tahun_pengadaan": "2025",
       "status_kelayakan": "Baik",
       "foto_fisik": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
   }
   ```
4. **Dart snippet** showing how to encode in Flutter:
   ```dart
   import 'dart:convert';
   import 'dart:typed_data';
   
   String imageToBase64(Uint8List bytes) => base64Encode(bytes);
   // Do NOT prepend "data:image/png;base64,"
   ```

## Validation

- Verify raw base64 decodes correctly: `php -r "echo base64_decode('iVBOR...');"` produces 67-byte PNG.
- Verify with-prefix path: the helper `strpos` branch correctly strips and decodes.
- Both controllers (`Logistik::senjata_post`, `Kamtibmas::laporan_post`) use the same helper — no endpoint-specific quirks.

## Execution

1. Open `application/helpers/base64_file_helper.php` — read it (already done, confirm in output).
2. Open either controller to confirm the call chain.
3. Generate the Markdown snippet with exact raw base64 string.
4. Output to stdout with brief intro explaining the inspection finding.
