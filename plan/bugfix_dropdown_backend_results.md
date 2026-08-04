# Bugfix Report — Dropdown Backend: Missing Pangkat & Jabatan Endpoints

**Bug:** The Flutter app's SDM form dropdowns for Pangkat (rank) and Jabatan (position) had no backend endpoints to populate from — only the table-level master endpoints (`/api/v1/master/polda`, `/api/v1/master/polres`, `/api/v1/master/kategori-senjata`) existed.

**Fix:** Added two read-only GET endpoints under the `Master` controller that return the lookup rows of `tbl_pangkat` and `tbl_jabatan`, registered at `api/v1/pangkat` and `api/v1/jabatan`.

---

## 1. Execution Summary

- **File modified:** `application/controllers/Master.php`
  - **Method added:** `pangkat_get()` — queries `tbl_pangkat` (`pangkat_id`, `nama_pangkat`), ordered by `pangkat_id ASC`.
  - **Method added:** `jabatan_get()` — queries `tbl_jabatan` (`jabatan_id`, `nama_jabatan`), ordered by `jabatan_id ASC`.
  - Both methods follow the existing controller pattern: JWT auth gate via `get_jwt_payload($this)` (401 on missing/invalid token), strict integer casting of ID columns (`(int)` cast in a reference loop — required for Flutter JSON parsing), and the standard `{status, message, data}` envelope with `data` as an array of rows.
- **File modified:** `application/config/routes.php`
  - **Route added:** `$route['api/v1/pangkat']['GET'] = 'master/pangkat_get';`
  - **Route added:** `$route['api/v1/jabatan']['GET'] = 'master/jabatan_get';`
- **No other controller or endpoint was touched.** The change is purely additive.

Note: neither table has an `is_active` soft-delete column (verified in `Seeder.php` CREATE TABLE definitions), so no `is_active` filter is applied — the full lookup list is served.

## 2. Code Added (diff proof)

In `application/controllers/Master.php` (appended after `kategori_senjata_delete()`):

```php
    public function pangkat_get()
    {
        $payload = get_jwt_payload($this);
        if ($payload === null) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->order_by('pangkat_id', 'ASC');
        $rows = $this->db->select('pangkat_id, nama_pangkat')->get('tbl_pangkat')->result_array();

        foreach ($rows as &$row) {
            $row['pangkat_id'] = (int) $row['pangkat_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Pangkat berhasil dimuat.',
            'data' => $rows
        ]);
    }

    public function jabatan_get()
    {
        $payload = get_jwt_payload($this);
        if ($payload === null) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->order_by('jabatan_id', 'ASC');
        $rows = $this->db->select('jabatan_id, nama_jabatan')->get('tbl_jabatan')->result_array();

        foreach ($rows as &$row) {
            $row['jabatan_id'] = (int) $row['jabatan_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Jabatan berhasil dimuat.',
            'data' => $rows
        ]);
    }
```

In `application/config/routes.php`:

```php
// Master / Pangkat + Jabatan (SDM dropdown master data)
$route['api/v1/pangkat']['GET']  = 'master/pangkat_get';
$route['api/v1/jabatan']['GET']  = 'master/jabatan_get';
```

## 3. Verification Status

### Syntax check — PASSED

```
$ php -l application/controllers/Master.php
No syntax errors detected in application/controllers/Master.php

$ php -l application/config/routes.php
No syntax errors detected in application/config/routes.php
```

### Live API check — PASSED

Server: `php -S localhost:8080 tests/router.php`, authenticated as `operator_test/operator123` (role_id=2, JWT Bearer header), then:

| Check | `GET /api/v1/pangkat` | `GET /api/v1/jabatan` |
|---|---|---|
| HTTP status | `200` | `200` |
| Response envelope | `status: 200`, `message: "Daftar Pangkat berhasil dimuat."` | `status: 200`, `message: "Daftar Jabatan berhasil dimuat."` |
| Rows returned | `13` | `8` |
| ID type in payload | `pangkat_id` integer (e.g. `1`, `13`) — strict cast | `jabatan_id` integer (e.g. `1`, `8`) — strict cast |
| Sample rows | `Bripda`, `Bripka`, `AKP`, `Irjen Pol` | `Dirsamapta`, `Kasat Sabhara`, `Anggota Dalmas`, `Paur Humas` |

Sample `data` payloads:

```
/api/v1/pangkat: {"status":200,"message":"Daftar Pangkat berhasil dimuat.","data":[
  {"pangkat_id":1,"nama_pangkat":"Bripda"}, ..., {"pangkat_id":13,"nama_pangkat":"Irjen Pol"}]}

/api/v1/jabatan: {"status":200,"message":"Daftar Jabatan berhasil dimuat.","data":[
  {"jabatan_id":1,"nama_jabatan":"Dirsamapta"}, ..., {"jabatan_id":8,"nama_jabatan":"Paur Humas"}]}
```

### Auth gating check — PASSED

Unauthenticated request to `GET /api/v1/pangkat` returns `{"status":401,"message":"Token tidak ditemukan atau tidak valid.","data":{}}` with HTTP `401`.

**Conclusion:** Both dropdown endpoints are live, JWT-gated, and return integer-typed IDs in the standard envelope. The Flutter app can now populate the Pangkat and Jabatan dropdowns from `/api/v1/pangkat` and `/api/v1/jabatan`. The change is purely additive, so existing consumers are unaffected.
