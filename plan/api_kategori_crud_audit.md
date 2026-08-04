# API Kategori Senjata CRUD — Audit & Refactor Plan

> **Date:** 2026-08-03
> **Status:** Audit complete — awaiting approval before implementation

---

## 1. Controller Audit

### 1.1 Current State: Methods Do Not Exist

`Master.php` (533 lines) contains 9 methods: `polda_get`, `polres_post`, `polres_put`, `polres_delete`, `polres_get`, `wilayah_get`, `polda_post`, `polda_put`, `polda_delete`. **No `kategori_senjata_*` methods exist.** These four CRUD endpoints must be built from scratch, following the `polres_*` pattern as the canonical template.

### 1.2 Database Schema Gap

Verified live via `SHOW CREATE TABLE tbl_kategori_senjata`:

```sql
CREATE TABLE `tbl_kategori_senjata` (
  `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
  `tipe_laras` enum('Panjang','Pendek') NOT NULL,
  `kaliber` varchar(20) NOT NULL,
  PRIMARY KEY (`kategori_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
```

| Column | Present? |
|---|---|
| `is_active` | ❌ **MISSING** — soft delete requires this |
| `updated_at` | ❌ **MISSING** — PUT/DELETE timestamps need this |
| UNIQUE on `(tipe_laras, kaliber)` | ❌ **MISSING** — duplicate prevention has no DB-level guard |

**The user stated they added `is_active` and `updated_at`, but the live database does not have them.** The Seeder's `_ensure_tables()` also defines the table without these columns (lines 125–130). Both the live DB and the migration code need updating.

### 1.3 Compliance Checklist

| Requirement | Current Status |
|---|---|
| `tipe_laras` validated against 'Panjang'/'Pendek' | ❌ No endpoint exists. Only DB ENUM constraint catches invalid values (MySQL error, not a clean 422). |
| Duplicate prevention (tipe_laras + kaliber) | ❌ No endpoint exists. No UNIQUE index on the table. Seeder does not truncate before re-seeding, so re-runs create duplicates. |
| Delete checks FK refs in `tbl_senjata` / `tbl_amunisi_batch` | ❌ No endpoint exists. No FK constraints defined on either child table — `kategori_id` is a plain `int(11) DEFAULT NULL` with no index. Manual COUNT pre-check required. |
| Soft delete (`is_active = 0`) | ❌ Column doesn't exist. |
| GET filters out `is_active = 0` | ❌ No endpoint exists. |

### 1.4 Side-Effect: `Logistik::amunisi_get()` Join

`Logistik.php:285`:
```php
$this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id', 'left');
```

This join does **not** filter `k.is_active = 1`. Once soft delete is added to `tbl_kategori_senjata`, soft-deleted kategori names will leak into amunisi responses. The `polres_get` method (Master.php:283) uses the correct pattern:
```php
$this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');
```

### 1.5 Routes

No routes exist for `kategori-senjata`. Current master routes at `routes.php:69-73,95-98`:

```php
$route['api/v1/master/polda']['GET']           = 'master/polda_get';
$route['api/v1/master/polda']['POST']          = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']    = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE'] = 'master/polda_delete/$1';
```

### 1.6 Test Coverage

Zero test coverage. The file `tests/api/master_polres.spec.ts` is the closest template — it uses Playwright with DB fixture injection for the 409 conflict trap.

---

## 2. Refactor Plan

### Files to Modify

| File | Change |
|---|---|
| `application/controllers/Seeder.php` | Add `is_active` + `updated_at` columns to CREATE TABLE and ALTER migration guard |
| `application/config/routes.php` | Add 4 kategori-senjata routes |
| `application/controllers/Master.php` | Add 4 CRUD methods: `kategori_senjata_get`, `kategori_senjata_post`, `kategori_senjata_put`, `kategori_senjata_delete` |
| `application/controllers/Logistik.php` | Add `AND k.is_active = 1` to amunisi_get() join |

### Step 1: Database Migration (Seeder.php)

Modify `_ensure_tables()` — update the CREATE TABLE (line 125) and add a migration guard after the existing `tipe_laras` check (after line 137).

**1a. Update CREATE TABLE statement (line 125–130):**

```php
$this->db->query("CREATE TABLE IF NOT EXISTS `tbl_kategori_senjata` (
    `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
    `tipe_laras` enum('Panjang','Pendek') NOT NULL,
    `kaliber` varchar(20) NOT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`kategori_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
```

**1b. Add migration guard (after line 137, before `tbl_personil` CREATE):**

```php
$has_kat_active = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_kategori_senjata'
    AND COLUMN_NAME = 'is_active'")->num_rows();
if (!$has_kat_active) {
    $this->db->query("ALTER TABLE `tbl_kategori_senjata` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `kaliber`");
    $this->db->query("ALTER TABLE `tbl_kategori_senjata` ADD COLUMN `updated_at` datetime DEFAULT NULL AFTER `is_active`");
}
```

**1c. Run the migration immediately** (since the columns are missing from the live DB):

```bash
mysql -h 127.0.0.1 -u root -proot sindomondb -e "
  ALTER TABLE tbl_kategori_senjata ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1 AFTER kaliber;
  ALTER TABLE tbl_kategori_senjata ADD COLUMN updated_at datetime DEFAULT NULL AFTER is_active;
"
```

Also update the seeder seed data in `_seed_logistik_master()` (line 349–354) to include `is_active`:

```php
$senjata = array(
    array('tipe_laras' => 'Pendek', 'kaliber' => '9mm',   'is_active' => 1),
    array('tipe_laras' => 'Panjang', 'kaliber' => '5.56mm', 'is_active' => 1),
);
```

### Step 2: Routes (routes.php)

Add after the existing `master/polres` routes (after line 98):

```php
$route['api/v1/master/kategori-senjata']['GET']           = 'master/kategori_senjata_get';
$route['api/v1/master/kategori-senjata']['POST']          = 'master/kategori_senjata_post';
$route['api/v1/master/kategori-senjata/(:num)']['PUT']    = 'master/kategori_senjata_put/$1';
$route['api/v1/master/kategori-senjata/(:num)']['DELETE'] = 'master/kategori_senjata_delete/$1';
```

### Step 3: CRUD Methods (Master.php)

Add four new methods to `Master.php` after `polda_delete` (after line 532), before the closing `}`.

#### 3a. `kategori_senjata_get()` — List Active Categories

```php
public function kategori_senjata_get()
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

    $rows = $this->db->get_where('tbl_kategori_senjata', ['is_active' => 1])->result_array();

    foreach ($rows as &$row) {
        $row['kategori_id'] = (int) $row['kategori_id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Kategori Senjata berhasil dimuat.',
        'data' => $rows
    ]);
}
```

#### 3b. `kategori_senjata_post()` — Create Category

```php
public function kategori_senjata_post()
{
    $payload = get_jwt_payload($this);

    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    $input = json_decode($this->input->raw_input_stream, true);

    $tipe_laras = trim($input['tipe_laras'] ?? '');
    $kaliber    = trim($input['kaliber'] ?? '');

    if ($tipe_laras === '' || $kaliber === '') {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Validasi gagal. Field tipe_laras dan kaliber wajib diisi.',
            'data' => (object)[]
        ]);
        return;
    }

    // Strict enum validation: only 'Panjang' or 'Pendek'
    $allowed_tipe = array('Panjang', 'Pendek');
    if (!in_array($tipe_laras, $allowed_tipe, true)) {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Validasi gagal. tipe_laras harus salah satu dari: Panjang, Pendek.',
            'data' => (object)[]
        ]);
        return;
    }

    // Duplicate check: same tipe_laras AND kaliber (active-only scope)
    $duplicate = $this->db->get_where('tbl_kategori_senjata', [
        'tipe_laras' => $tipe_laras,
        'kaliber'    => $kaliber,
        'is_active'  => 1
    ])->num_rows();
    if ($duplicate > 0) {
        http_response_code(409);
        echo json_encode([
            'status' => 409,
            'message' => 'Validasi gagal. Kombinasi Tipe Laras dan Kaliber sudah digunakan.',
            'data' => (object)[]
        ]);
        return;
    }

    $this->db->insert('tbl_kategori_senjata', [
        'tipe_laras' => $tipe_laras,
        'kaliber'    => $kaliber,
        'is_active'  => 1
    ]);

    $inserted_id = $this->db->insert_id();

    http_response_code(201);
    echo json_encode([
        'status' => 201,
        'message' => 'Data Kategori Senjata berhasil ditambahkan.',
        'data' => [
            'kategori_id' => (int) $inserted_id
        ]
    ]);
}
```

#### 3c. `kategori_senjata_put($kategori_id)` — Update Category

```php
public function kategori_senjata_put($kategori_id)
{
    $payload = get_jwt_payload($this);

    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // Only active (not soft-deleted) records can be edited
    $exists = $this->db->get_where('tbl_kategori_senjata', [
        'kategori_id' => $kategori_id,
        'is_active'   => 1
    ])->num_rows();
    if ($exists === 0) {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'message' => 'Kategori Senjata tidak ditemukan.',
            'data' => (object)[]
        ]);
        return;
    }

    $input = json_decode($this->input->raw_input_stream, true);

    $tipe_laras = trim($input['tipe_laras'] ?? '');
    $kaliber    = trim($input['kaliber'] ?? '');

    if ($tipe_laras === '' || $kaliber === '') {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Validasi gagal. Field tipe_laras dan kaliber wajib diisi.',
            'data' => (object)[]
        ]);
        return;
    }

    // Strict enum validation
    $allowed_tipe = array('Panjang', 'Pendek');
    if (!in_array($tipe_laras, $allowed_tipe, true)) {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Validasi gagal. tipe_laras harus salah satu dari: Panjang, Pendek.',
            'data' => (object)[]
        ]);
        return;
    }

    // Duplicate check (exclude self, active-only)
    $duplicate = $this->db->where('tipe_laras', $tipe_laras)
        ->where('kaliber', $kaliber)
        ->where('kategori_id !=', $kategori_id)
        ->where('is_active', 1)
        ->get('tbl_kategori_senjata')->num_rows();
    if ($duplicate > 0) {
        http_response_code(409);
        echo json_encode([
            'status' => 409,
            'message' => 'Validasi gagal. Kombinasi Tipe Laras dan Kaliber sudah digunakan.',
            'data' => (object)[]
        ]);
        return;
    }

    $this->db->where('kategori_id', $kategori_id)->update('tbl_kategori_senjata', [
        'tipe_laras' => $tipe_laras,
        'kaliber'    => $kaliber,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Data Kategori Senjata berhasil diperbarui.',
        'data' => (object)[]
    ]);
}
```

#### 3d. `kategori_senjata_delete($kategori_id)` — Soft Delete

```php
public function kategori_senjata_delete($kategori_id)
{
    $payload = get_jwt_payload($this);

    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // Only active (not already soft-deleted) records can be deleted
    $exists = $this->db->get_where('tbl_kategori_senjata', [
        'kategori_id' => $kategori_id,
        'is_active'   => 1
    ])->num_rows();
    if ($exists === 0) {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'message' => 'Kategori Senjata tidak ditemukan.',
            'data' => (object)[]
        ]);
        return;
    }

    // Pre-check: block if kategori_id is referenced in tbl_senjata or tbl_amunisi_batch
    // (No FK constraints exist — manual COUNT guard required per soft-delete convention)
    $senjata_count = $this->db->get_where('tbl_senjata', [
        'kategori_id' => $kategori_id
    ])->num_rows();
    $amunisi_count = $this->db->get_where('tbl_amunisi_batch', [
        'kategori_id' => $kategori_id
    ])->num_rows();

    if ($senjata_count > 0 || $amunisi_count > 0) {
        http_response_code(409);
        echo json_encode([
            'status' => 409,
            'message' => 'Kategori tidak dapat dihapus karena masih digunakan oleh data Senjata atau Amunisi (Restricted by System).',
            'data' => (object)[]
        ]);
        return;
    }

    // Soft delete
    $this->db->where('kategori_id', $kategori_id)->update('tbl_kategori_senjata', [
        'is_active' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Data Kategori Senjata berhasil dihapus.',
        'data' => (object)[]
    ]);
}
```

### Step 4: Fix `Logistik::amunisi_get()` Join (Logistik.php)

Change line 285 from:
```php
$this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id', 'left');
```
To:
```php
$this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');
```

This prevents soft-deleted kategori names from leaking into amunisi responses. Follows the pattern from `polres_get` (Master.php:283).

---

## 3. Verification Plan

### 3.1 Manual Testing (curl)

After implementation, verify each endpoint:

```bash
# Auth
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}' | jq -r '.data.jwt_token')

# GET — list active categories
curl -s http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" | jq .

# POST — create new category (201)
curl -s -X POST http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Panjang","kaliber":"7.62mm"}' | jq .

# POST — duplicate (409)
curl -s -X POST http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Panjang","kaliber":"7.62mm"}' | jq .

# POST — invalid enum (422)
curl -s -X POST http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Medium","kaliber":"9mm"}' | jq .

# POST — missing field (422)
curl -s -X POST http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Panjang"}' | jq .

# PUT — update (200)
curl -s -X PUT http://localhost:8080/api/v1/master/kategori-senjata/3 \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Pendek","kaliber":"7.62mm"}' | jq .

# PUT — non-existent (404)
curl -s -X PUT http://localhost:8080/api/v1/master/kategori-senjata/9999 \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"tipe_laras":"Panjang","kaliber":"9mm"}' | jq .

# DELETE — with references (409) — uses kategori_id=1 which is seeded
curl -s -X DELETE http://localhost:8080/api/v1/master/kategori-senjata/1 \
  -H "Authorization: Bearer $TOKEN" | jq .

# DELETE — success (200) — uses newly created kategori_id=3
curl -s -X DELETE http://localhost:8080/api/v1/master/kategori-senjata/3 \
  -H "Authorization: Bearer $TOKEN" | jq .

# GET — verify soft-deleted row is gone
curl -s http://localhost:8080/api/v1/master/kategori-senjata \
  -H "Authorization: Bearer $TOKEN" | jq .
```

### 3.2 Seeder Verification

```bash
# Reset and re-seed — verify no errors
php index.php seeder run
```

### 3.3 Amunisi GET Verification

Verify the `amunisi_get` join still works after the `is_active` filter is added:

```bash
curl -s http://localhost:8080/api/v1/logistik/amunisi \
  -H "Authorization: Bearer $TOKEN" | jq '.data[0].kategori'
```

### 3.4 Existing Test Regression

```bash
npm test
```

All existing Playwright tests must still pass — the Polres CRUD tests and seeder E2E tests should be unaffected by these changes.

---

## 4. Risk Assessment

| Risk | Mitigation |
|---|---|
| `is_active` columns don't exist in live DB | Run ALTER TABLE before deploying code (Step 1c) |
| `Logistik::amunisi_get` join change breaks responses | `LEFT JOIN` ensures rows still appear; `kaliber` becomes `null` for soft-deleted kategori — same behavior as `polres_get` with soft-deleted Polda |
| No UNIQUE index on `(tipe_laras, kaliber)` | Application-level duplicate check is sufficient (matches Polres name pattern). DB-level UNIQUE index can be added later if needed. |
| No FK constraints on child tables | Manual COUNT pre-check in delete method; documented in `polda-soft-delete-precheck.md` memory — this is the project convention |
| Seeder re-runs create duplicates | Added `is_active => 1` to seed data. Since `truncate` runs before seed, no risk of duplicate `is_active` rows. Updating `_ensure_tables` CREATE TABLE and ALTER guard ensures new environments get the columns automatically. |
