# Backend Amunisi Audit — `GET` & `POST` Readiness

**Date:** 2026-08-05  
**Auditor:** CI3 Backend Auditor (DEBUG MODE)  
**Scope:** `GET /api/v1/logistik/amunisi` + `POST /api/v1/logistik/amunisi` + CORS `OPTIONS`

---

## 1. Route Status

| Method   | Route Pattern                       | Handler                     | Status |
|----------|-------------------------------------|-----------------------------|--------|
| `GET`    | `api/v1/logistik/amunisi`           | `logistik/amunisi_get`      | ✅ OK  |
| `POST`   | `api/v1/logistik/amunisi`           | `logistik/amunisi_post`     | ✅ OK  |
| `OPTIONS`| `api/v1/logistik/amunisi`           | *(none)*                    | ❌ **MISSING** |

### CORS Preflight — CRITICAL

**No `OPTIONS` route defined for amunisi.** Compare with `senjata` which has:

```php
// routes.php lines 89-90
$route['api/v1/logistik/senjata']['OPTIONS']        = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS']  = 'logistik/senjata_options';
```

Without an amunisi `OPTIONS` route, CI3's router will **404** on preflight requests because no method-constrained route matches the `OPTIONS` verb. The controller constructor already has a global `OPTIONS → 200` guard (line 12-15), but the router never dispatches to the controller.

**Impact:** Flutter `http` / `dio` will receive `XMLHttpRequest cannot load ... Response to preflight ... 404 Not Found`. Same CORS bug from Senjata before the fix.

**Fix needed:**
1. Add `$route['api/v1/logistik/amunisi']['OPTIONS'] = 'logistik/amunisi_options';` in `routes.php`
2. Add empty `amunisi_options()` method in `Logistik.php` (mirror of `senjata_options`, line 752-755)

---

## 2. GET Logic — `amunisi_get()` (lines 502-572)

### Auth & Jurisdiction
| Check | Detail |
|-------|--------|
| JWT extraction | `get_jwt_payload($this)` — line 505 |
| `polda_id` filter | Extracted from JWT `$payload['polda_id']` — line 517; applied in WHERE — line 528 |
| Search filter | `?search=` → `LIKE '%...%'` on `a.kode_batch` — line 534 |

### JOIN with `tbl_kategori_senjata`
```php
$this->db->join('tbl_kategori_senjata k',
    'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');
```
- LEFT JOIN so batches with deleted/nulled kategori still appear
- `k.is_active = 1` gate prevents leaked labels from soft-deleted categories
- Returns `kaliber` from kategori in the nested `kategori` object

### H-90 Alert Engine — ✅ FULLY IMPLEMENTED

```php
// Lines 544-559
$today = time();
$expiry = strtotime($row['tanggal_kedaluwarsa']);
$hari_tersisa = (int) floor(($expiry - $today) / 86400);

$mapped[] = array(
    ...
    'is_h90_alert' => ($hari_tersisa <= 90) ? true : false,
    'hari_tersisa' => $hari_tersisa,
    ...
);
```

| PRD Requirement | Implementation | Status |
|-----------------|----------------|--------|
| Calculate days until expiration | `floor(($expiry - $today) / 86400)` | ✅ |
| Flag batches expiring ≤ 90 days | `is_h90_alert = ($hari_tersisa <= 90)` | ✅ |
| Return remaining days to client | `hari_tersisa` field in response | ✅ |
| Handle already-expired batches | `hari_tersisa` goes negative; `is_h90_alert = true` (0 ≤ 90) | ✅ |

### Response Shape
```json
{
  "status": 200,
  "message": "Daftar amunisi termuat.",
  "data": [
    {
      "batch_id": 1,
      "polda_id": 1,
      "kode_batch": "AM-001",
      "kategori": { "kaliber": "9mm" },
      "jumlah_butir": 500,
      "tanggal_masuk": "2026-01-01",
      "tanggal_kedaluwarsa": "2027-01-01",
      "is_h90_alert": false,
      "hari_tersisa": 148,
      "created_at": "...",
      "updated_at": "..."
    }
  ]
}
```

---

## 3. POST Logic — `amunisi_post()` (lines 407-494)

### Auth & Validation Pipeline
| Step | Detail | Status |
|------|--------|--------|
| JWT auth | `get_jwt_payload($this)` — line 410 | ✅ |
| Content-Type gate | Rejects non-`application/json` with 415 — line 423 | ✅ |
| JSON parse | `json_decode($this->input->raw_input_stream)` — line 434 | ✅ |
| Date validation | `kedaluwarsa > masuk` — line 452 | ✅ |
| polda_id injection | From JWT, auto-injected — line 463 | ✅ |
| DB insert | `$this->db->insert('tbl_amunisi_batch', $data)` — line 475 | ✅ |

### Date Validation (PRD Check)
```php
if (strtotime($tanggal_kedaluwarsa) <= strtotime($tanggal_masuk)) {
    // 400: "Validasi gagal. Tanggal kedaluwarsa harus lebih besar dari tanggal masuk."
}
```
- Uses `<=` — equal dates also rejected (correct: expiry must be strictly after entry) ✅

### Target Table: `tbl_amunisi_batch`
```sql
CREATE TABLE IF NOT EXISTS `tbl_amunisi_batch` (
    `batch_id` int(11) NOT NULL AUTO_INCREMENT,
    `polda_id` int(11) DEFAULT NULL,
    `kode_batch` varchar(100),
    `kategori_id` int(11) DEFAULT NULL,
    `jumlah_butir` int(11) DEFAULT 0,
    `tanggal_masuk` date DEFAULT NULL,
    `tanggal_kedaluwarsa` date DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`batch_id`)
)
```
- Fields in POST payload match table columns exactly ✅

### Missing Validations (non-critical, not in PRD)
- No mandatory field check — `kode_batch`, `kategori_id`, `jumlah_butir`, dates can all be empty/0/null on POST
- No unique `kode_batch` check per polda
- `ponytail:` add mandatory field checks + unique batch code when UX defines error states

---

## 4. Verdict

| Capability | Ready? | Blocker? |
|------------|--------|----------|
| GET endpoint logic (JWT, polda filter, JOIN, H-90) | ✅ | No |
| POST endpoint logic (JWT, date validation, insert) | ✅ | No |
| CORS preflight (OPTIONS route) | ❌ | **YES** |
| `amunisi_options()` method | ❌ | **YES** |

**Bottom line:** Backend logic is production-ready. **Only blocker is the missing OPTIONS route** — Flutter will fail on CORS preflight identically to the Senjata bug. Add 2 lines in `routes.php` + 4 lines in `Logistik.php` and the backend is unblocked.
