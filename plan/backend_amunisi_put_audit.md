# Backend Amunisi PUT Audit

**Date:** $(date +%Y-%m-%d)
**Request:** `PUT /api/v1/logistik/amunisi/4` → **404 HTML response**

---

## 1. Route Status: ❌ MISSING

In `application/config/routes.php`, the existing amunisi routes (lines 91–95):

| Method | Route | Controller Method | Present? |
|--------|-------|------------------|----------|
| POST | `api/v1/logistik/amunisi` | `logistik/amunisi_post` | ✅ |
| GET | `api/v1/logistik/amunisi` | `logistik/amunisi_get` | ✅ |
| OPTIONS | `api/v1/logistik/amunisi` | `logistik/amunisi_options` | ✅ |
| OPTIONS | `api/v1/logistik/amunisi/(:any)` | `logistik/amunisi_options` | ✅ |
| DELETE | `api/v1/logistik/amunisi/(:any)` | `logistik/amunisi_delete/$1` | ✅ |
| **PUT** | `api/v1/logistik/amunisi/(:any)` | — | **❌ MISSING** |

**No PUT route exists.** The 404 is caused by the router not having a matching `$route['api/v1/logistik/amunisi/(:any)']['PUT']` entry.

For comparison, `senjata` has a complete CRUD set (lines 85–90), including:

```php
$route['api/v1/logistik/senjata/(:any)']['PUT']    = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata/(:any)']['DELETE']  = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';
```

The `amunisi` set is missing the PUT line but has DELETE and OPTIONS, making the gap obvious.

---

## 2. Controller Status: ❌ MISSING

In `application/controllers/Logistik.php`, the existing amunisi methods:

| Method | Line | Exists? |
|--------|------|---------|
| `amunisi_post()` | ~407 | ✅ |
| `amunisi_get()` | ~502 | ✅ |
| `amunisi_delete($batch_id)` | ~580 | ✅ |
| `amunisi_options($id = null)` | ~826 | ✅ |
| **`amunisi_put($batch_id)`** | — | **❌ MISSING** |

No `amunisi_put` method exists. Even if the route were added, it would 404 because the controller method it points to doesn't exist.

---

## 3. Action Plan

Two items must be added, following the proven `senjata_put` pattern (lines 243–353+):

### 3.1 Add the PUT route in `application/config/routes.php`

Insert after line 95 (`$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = ...`):

```php
$route['api/v1/logistik/amunisi/(:any)']['PUT'] = 'logistik/amunisi_put/$1';
```

### 3.2 Add `amunisi_put($batch_id)` method in `application/controllers/Logistik.php`

Follow the same structure as `senjita_put` but adapted for `tbl_amunisi_batch`. Key sections:

1. **Load** `base64_file` helper.
2. **Auth**: `get_jwt_payload($this)` — reject 401 if missing.
3. **Content-Type check**: require `application/json` — reject 415 otherwise.
4. **Parse JSON body** via `$this->input->raw_input_stream` — reject 400 if invalid.
5. **Existence + jurisdiction check** on `tbl_amunisi_batch` by `batch_id` + JWT `polda_id` — reject 404 if not found.
6. **Build dynamic SET clause** from the following updatable fields (only if present in input):
   - `nomor_batch` — with uniqueness check excluding current record
   - `kaliber`
   - `jumlah_peluru`
   - `tanggal_kadaluarsa`
   - `catatan`
   - (plus any other fields the Flutter app sends)
7. **Execute UPDATE** on `tbl_amunisi_batch`.
8. **Return** `{"status": 200, "message": "Data berhasil diperbarui", "data": {...updated row...}}`.

### 3.3 Estimated effort

- ~1 line in `routes.php`
- ~80–120 lines in `Logistik.php` (mirroring `senjata_put` structure)
- Should align with the Flutter app's expected payload fields — verify with the Flutter team what fields they send in the PUT body.
