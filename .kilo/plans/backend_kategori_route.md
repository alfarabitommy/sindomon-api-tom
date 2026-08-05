# Backend Audit: Kategori Senjata Endpoint

## 1. Route Definition

**File:** `application/config/routes.php:102`

```php
// Master / Kategori Senjata (Logistik master data)
$route['api/v1/master/kategori-senjata']['GET']           = 'master/kategori_senjata_get';
$route['api/v1/master/kategori-senjata']['POST']          = 'master/kategori_senjata_post';
$route['api/v1/master/kategori-senjata/(:num)']['PUT']    = 'master/kategori_senjata_put/$1';
$route['api/v1/master/kategori-senjata/(:num)']['DELETE'] = 'master/kategori_senjata_delete/$1';
```

## 2. Controller Method

**File:** `application/controllers/Master.php:534-562`

**Method:** `kategori_senjata_get()`

**Auth:** JWT required (returns 401 if missing/invalid).

**Query:** `SELECT * FROM tbl_kategori_senjata WHERE is_active = 1 ORDER BY kategori_id ASC`

**Returned JSON structure:**

```json
{
  "status": 200,
  "message": "Daftar Kategori Senjata berhasil dimuat.",
  "data": [
    {
      "kategori_id": 1,
      "tipe_laras": "Panjang",
      "kaliber": "5.56mm",
      "is_active": 1,
      "created_at": "2025-01-01 00:00:00",
      "updated_at": null
    }
  ]
}
```

- `kategori_id` is cast to `int`.
- Only `is_active = 1` rows are returned (soft-deleted rows hidden).
- Results ordered by `kategori_id` ascending.

## 3. Exact Endpoint URL

```
GET /api/v1/master/kategori-senjata
```

**Flutter usage:**

```dart
final response = await http.get(
  Uri.parse('$apiBaseUrl/api/v1/master/kategori-senjata'),
  headers: {'Authorization': 'Bearer $token'},
);
```
