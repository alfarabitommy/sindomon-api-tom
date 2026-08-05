# Debug Report: Senjata List Image Broken

Trace: `GET /api/v1/logistik/senjata` → Flutter `Image.network`.

---

## 1. Backend Findings

**File:** `application/controllers/Logistik.php`

**Method:** `senjata_get()` (lines 169–234)

**Response key for the image:** `foto_url`

- Line 222: `'foto_url' => $row['foto_url']`
- The column `tbl_senjata.foto_url` is populated by `senjata_post()` (line 117) with a **relative path, no leading slash**:
  ```
  uploads/senjata/<generated-file-name>.jpg
  ```
- File physically written by `save_base64_file()` to `dirname(FCPATH) . '/uploads/senjata/'` (line 102).
- **There is NO `foto_fisik` key in the GET response.** `foto_fisik` exists only as the POST request payload key (base64 input, line 74) and is never persisted/returned.

**Typical GET value format:**
```json
{
  "senjata_id": "...",
  "nomor_seri": "...",
  "status_kelayakan": "...",
  "foto_url": "uploads/senjata/7f3a...jpg",
  "created_at": "..."
}
```

**Implication:** Frontend fallback `e["foto_fisik"] ?? e["foto_url"]` is correct — `foto_fisik` is always absent from GET, so `foto_url` (relative, slash-less) is always what reaches the URL builder.

---

## 2. Frontend Injection

**File:** `lib/pages/senjata.dart` (project `sindomon-tom`)

**Method:** `_fotoUrl()` (line 106)

**Modified code with injected `debugPrint`:**
```dart
String _fotoUrl(Map<String, dynamic> e) {
  final raw = e["foto_fisik"] ?? e["foto_url"];
  final url = raw?.toString() ?? "";
  if (url.isEmpty) return "";
  if (url.startsWith("http://") || url.startsWith("https://")) return url;
  final parsed = url.startsWith("/") ? "$apiBaseUrl$url" : "$apiBaseUrl/$url";
  debugPrint("DEBUG IMAGE URL: $url");
  return parsed;
}
```

**Expected console output when a row has a photo:**
```
DEBUG IMAGE URL: uploads/senjata/7f3a...jpg
```
`Image.network` then requests `https://sindomon.cml-indonesia.com/uploads/senjata/7f3a...jpg`.

---

## 3. Next Step After Logs

Collect the debug run. Two verdicts:

- **URL prints correctly** (`uploads/senjata/...`) → failure is server-side serving. Check:
  - Whether the file physically exists in `{webroot}/uploads/senjata/` (note: `save_base64_file` writes to `dirname(FCPATH)` — confirm FCPATH's parent is the web root of `https://sindomon.cml-indonesia.com`, not a sibling/deployed directory).
  - Missing `.htaccess`/static-file serving rules, or nginx `try_files` blocking `/uploads/`.
- **URL prints empty or wrong** → failure is data-side. Check:
  - GET response actually containing `foto_url` (vs `foto` / null).
  - Rows created before the POST fix stored `foto_url` as empty → empty `Image.network("")` hits `errorBuilder` silently.
