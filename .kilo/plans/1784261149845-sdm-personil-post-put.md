# ENDPOINT 3.3: Input Personel Baru / Mutasi (POST + PUT)

## Files to Modify (2)
- `application/config/routes.php` — Add 2 routes
- `application/controllers/Sdm.php` — Add `personil_post()`, `personil_put($personil_id)`, load `uuid` helper

## DB Schema (tbl_personil — inferred from existing `personil_get()`)
| Column | Type | Notes |
|---|---|---|
| personil_id | CHAR(36) | UUID v4, PK |
| nrp | VARCHAR | Unique per polda scope |
| nama_lengkap | VARCHAR | |
| pangkat_id | INT | FK → tbl_pangkat |
| jabatan_id | INT | FK → tbl_jabatan |
| status_aktif | ENUM('Aktif','Mutasi','Pensiun') | Default 'Aktif' |
| polda_id | INT | FK → tbl_polda |
| polres_id | INT NULL | FK → tbl_polres, NULL for Mako |
| created_at | DATETIME | |

## Task 1: Routes (`application/config/routes.php`)
Insert after line 82 (`$route['api/v1/sdm/personil']['GET'] = 'sdm/personil_get';`):
```php
$route['api/v1/sdm/personil']['POST'] = 'sdm/personil_post';
$route['api/v1/sdm/personil/(:any)']['PUT'] = 'sdm/personil_put/$1';
```

## Task 2: Controller — Load uuid helper
In `Sdm::__construct()`, add after `$this->load->library('jwt');`:
```php
$this->load->helper('uuid');
```

## Task 3: `personil_post()` — Tambah Personel Baru
Follow existing pattern from `Logistik::senjata_post()`.

### 3a. Auth
- `$payload = get_jwt_payload($this);`
- 401 if null
- Extract `$role_id = (int) $payload['role_id']`
- `if ($role_id != 2)` → 403 `{"status":403, "message":"Akses ditolak", "data":{}}`
- `$jwt_polda_id = (int) $payload['polda_id']`

### 3b. Parse JSON
- `$input = json_decode($this->input->raw_input_stream, true);`
- 400 if invalid JSON

### 3c. Extract + Validate fields
- `$nrp = trim($input['nrp'] ?? '')`
- `$nama_lengkap = trim($input['nama_lengkap'] ?? '')`
- `$pangkat_id = (int) ($input['pangkat_id'] ?? 0)`
- `$jabatan_id = (int) ($input['jabatan_id'] ?? 0)`
- `$status_aktif = trim($input['status_aktif'] ?? 'Aktif') ?: 'Aktif'`
- `$polres_id = ($input['polres_id'] ?? null)` — cast to NULL if empty string, `"0"`, or `0`
- Required fields check: `nrp && nama_lengkap && pangkat_id && jabatan_id` → 422 if missing

### 3d. NRP uniqueness check
```php
$existing = $this->db->query(
    "SELECT personil_id FROM tbl_personil WHERE nrp = " . $this->db->escape($nrp)
);
if ($existing->num_rows() > 0) → 422
{"status":422, "message":"Pendaftaran gagal. NRP sudah terdaftar di sistem.", "data":{}}
```

### 3e. polres_id NULL casting
```php
if ($polres_id === '' || $polres_id === '0' || $polres_id === 0 || $polres_id === null) {
    $polres_id = null;
} else {
    $polres_id = (int) $polres_id;
}
```

### 3f. Generate UUID + Insert
```php
$personil_id = generate_uuid4();
$sql = "INSERT INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id, created_at)
        VALUES (
            '{$this->db->escape_str($personil_id)}',
            '{$this->db->escape_str($nrp)}',
            '{$this->db->escape_str($nama_lengkap)}',
            '{$this->db->escape_str($pangkat_id)}',
            '{$this->db->escape_str($jabatan_id)}',
            '{$this->db->escape_str($status_aktif)}',
            '{$this->db->escape_str($jwt_polda_id)}',
            " . ($polres_id === null ? "NULL" : "'{$this->db->escape_str($polres_id)}'") . ",
            NOW()
        )";
```

### 3g. Return 201
```json
{"status":201, "message":"Personel berhasil didaftarkan.", "data":{"personil_id":"<uuid>"}}
```

## Task 4: `personil_put($personil_id)` — Edit/Mutasi Personel

### 4a. Auth — Same as 3a
- 401 if no token, 403 if `role_id != 2`

### 4b. Parse JSON — Same as 3b

### 4c. Extract fields — Same as 3c

### 4d. NRP uniqueness check (exclude self)
```php
$existing = $this->db->query(
    "SELECT personil_id FROM tbl_personil WHERE nrp = " . $this->db->escape($nrp)
    . " AND personil_id != " . $this->db->escape($personil_id)
);
```

### 4e. polres_id NULL casting — Same as 3e

### 4f. Jurisdiction-protected UPDATE
```php
$sql = "UPDATE tbl_personil SET
    nrp = '{$this->db->escape_str($nrp)}',
    nama_lengkap = '{$this->db->escape_str($nama_lengkap)}',
    pangkat_id = '{$this->db->escape_str($pangkat_id)}',
    jabatan_id = '{$this->db->escape_str($jabatan_id)}',
    status_aktif = '{$this->db->escape_str($status_aktif)}',
    polres_id = " . ($polres_id === null ? "NULL" : "'{$this->db->escape_str($polres_id)}'") . "
WHERE personil_id = '{$this->db->escape_str($personil_id)}'
  AND polda_id = '{$this->db->escape_str($jwt_polda_id)}'";

$result = $this->db->query($sql);
if ($this->db->affected_rows() === 0) → 404
{"status":404, "message":"Personel tidak ditemukan.", "data":{}}
```

### 4g. Return 200
```json
{"status":200, "message":"Data personel berhasil diperbarui.", "data":{}}
```

## Task 5: Validation — curl tests

### Test A: POST success → 201
```bash
curl -si -X POST http://localhost:8080/api/v1/sdm/personil \
  -H "Content-Type: application/json" \
  -H "Authorization: <JWT_role_id=2>" \
  -d '{"nrp":"99000001","nama_lengkap":"Test Personel","pangkat_id":1,"jabatan_id":1,"polres_id":""}'
```

### Test B: POST duplicate NRP → 422
```bash
curl -si -X POST http://localhost:8080/api/v1/sdm/personil \
  -H "Content-Type: application/json" \
  -H "Authorization: <JWT_role_id=2>" \
  -d '{"nrp":"99000001","nama_lengkap":"Duplicate NR","pangkat_id":1,"jabatan_id":1}'
```

### Test C: PUT update name → 200
```bash
curl -si -X PUT http://localhost:8080/api/v1/sdm/personil/<personil_id_from_A> \
  -H "Content-Type: application/json" \
  -H "Authorization: <JWT_role_id=2>" \
  -d '{"nrp":"99000001","nama_lengkap":"Updated Name","pangkat_id":1,"jabatan_id":1}'
```

## Security Summary
- `polda_id` never accepted from client JSON — auto-injected from JWT
- role_id=2 (Operator Polda) only for write — others get 403
- PUT jurisdiction-locked via `WHERE polda_id = jwt_polda_id`
- NRP uniqueness enforced at DB level via application check (422 on duplicate)
- All user input escaped via `$this->db->escape_str()`
- No external dependencies added — uses existing `generate_uuid4()` and `get_jwt_payload()`
