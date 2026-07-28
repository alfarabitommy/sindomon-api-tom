# CLI Seeder + Master Wilayah Endpoint + Playwright Verification

## Context

Existing `tests/seed.php` is a raw-PDO script with no CLI guard, table migration, or proper truncation.  Master-data tables (`tbl_pangkat`, `tbl_jabatan`, `tbl_kategori_senjata`) don't exist in the DB.  The `/api/v1/master/wilayah` endpoint doesn't exist.  Playwright tests cover SDM and Polres CRUD but not master-data seeding.

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Table creation | Seeder runs `CREATE TABLE IF NOT EXISTS` for all 5 tables before seeding | Existing code silently catches missing-table errors; explicit DDL makes the seeder self-contained |
| `tbl_polres` FK column | Uses `polres_id` (v6 migration state) | `tests/seed.php` already applies the migration; seeder targets migrated schema |
| `tbl_polda` PK column | Uses `id` | Existing codebase (`Polda::get`, `Master::polres_*`) references it as `id` |
| `master/wilayah` endpoint | New `wilayah_get()` method in `Master.php` | User explicitly requests path `/api/v1/master/wilayah`; keeps Wilayah concerns in Master controller |
| Auth for wilayah | JWT required (any role) | User plan says "Login as Admin to retrieve JWT"; matches pattern of other endpoints |
| `tbl_polres` rows | 2 per Polda (76 total) | User spec: "insert 2 dummy rows for EACH of the 38 Poldas" |
| Coordinate precision | 6 decimal places | Consistent with real-world GPS precision |
| ENUM on `tipe_laras` | Done in `CREATE TABLE` DDL | MariaDB strict mode rejects invalid inserts |

## Files Changed/Created

1. **`application/controllers/Seeder.php`** — NEW, the CLI-only controller
2. **`application/controllers/Master.php`** — ADD `wilayah_get()` method + route
3. **`application/config/routes.php`** — ADD route for `master/wilayah`
4. **`tests/api/seeder_master.spec.ts`** — NEW, Playwright test file

## Step-by-step Tasks

### 1. Create `application/controllers/Seeder.php`

- Class `Seeder extends CI_Controller`
- `__construct()`: `if (!is_cli()) { echo "CLI access only."; exit; }`, load DB, URL helper
- **`run()`** method:
  1. `$this->db->query('SET FOREIGN_KEY_CHECKS = 0');`
  2. Call `_ensure_tables()` — DDL for missing tables
  3. TRUNCATE: `tbl_kategori_senjata`, `tbl_pangkat`, `tbl_jabatan`, `tbl_polres`, `tbl_polda`
  4. `$this->db->query('SET FOREIGN_KEY_CHECKS = 1');`
  5. `$this->_seed_wilayah();`
  6. `$this->_seed_sdm_master();`
  7. `$this->_seed_logistik_master();`
  8. `echo "Master Data Seeded Successfully!\n";`

- `_ensure_tables()`:
  ```sql
  CREATE TABLE IF NOT EXISTS `tbl_pangkat` (
    `pangkat_id` int(11) NOT NULL AUTO_INCREMENT,
    `nama_pangkat` varchar(100) NOT NULL,
    PRIMARY KEY (`pangkat_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS `tbl_jabatan` (
    `jabatan_id` int(11) NOT NULL AUTO_INCREMENT,
    `nama_jabatan` varchar(100) NOT NULL,
    `formasi_ideal` int(11) NOT NULL DEFAULT 0,
    `parent_id` int(11) DEFAULT NULL,
    PRIMARY KEY (`jabatan_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS `tbl_kategori_senjata` (
    `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
    `tipe_laras` enum('Panjang','Pendek') NOT NULL,
    `kaliber` varchar(20) NOT NULL,
    PRIMARY KEY (`kategori_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
  Also check `tbl_polres` for v6 migration (rename `id`→`polres_id`, `nama_polda`→`nama_polres`, drop `created_at`, add FK) — run the same migration as `tests/seed.php` if not already migrated.

- `_seed_wilayah()`:
  - Array of 38 Polda: `['nama_polda' => 'Polda Aceh', 'latitude' => '5.550000', 'longitude' => '95.316666', ...]` (all 38 provinces with realistic coords)
  - `$this->db->insert_batch('tbl_polda', $poldas)` — insert all 38
  - For each polda in result range (IDs 1–38): insert 2 polres rows:
    - `['polda_id' => $i, 'nama_polres' => "Polrestabes {$i}.1"]`
    - `['polda_id' => $i, 'nama_polres' => "Polres {$i}.2"]`
  - Build batch array and `$this->db->insert_batch('tbl_polres', $polres)`

- `_seed_sdm_master()`:
  - Pangkat: `['Bripda','Briptu','Brigpol','Bripka','Aipda','Aiptu','Ipda','Iptu','AKP','Kompol','AKBP','Kombes Pol','Irjen Pol']` — batch insert
  - Jabatan: positions with `formasi_ideal`:
    - Dirsamapta (1), Wadirsamapta (1), Kasat Sabhara (1), Komandan Peleton (4), Anggota Dalmas (50)
  - `$this->db->insert_batch('tbl_jabatan', $jabatans)`

- `_seed_logistik_master()`:
  - `['tipe_laras' => 'Pendek', 'kaliber' => '9mm']`
  - `['tipe_laras' => 'Panjang', 'kaliber' => '5.56mm']`
  - `$this->db->insert_batch('tbl_kategori_senjata', $senjatas)`

### 2. Add `wilayah_get()` to `application/controllers/Master.php`

- JWT auth (any role, reuse existing `get_jwt_payload`)
- `$this->db->get('tbl_polda')->result_array()`
- Return: `{"status": 200, "message": "Daftar wilayah berhasil dimuat.", "data": $rows}`

### 3. Add Route in `application/config/routes.php`

```php
$route['api/v1/master/wilayah']['GET'] = 'master/wilayah_get';
```

### 4. Create `tests/api/seeder_master.spec.ts`

- `test.beforeAll`: `execSync('php index.php seeder run', ...)`
- Test block: `test.describe.serial('Seeder Master Data')`
  1. **Auth**: `POST /api/v1/auth/login` with `admin`/`admin123` → extract JWT
  2. **Wilayah**: `GET /api/v1/master/wilayah` with Bearer token
  3. **Assertions**:
     - Status 200
     - Envelope: `body` has `status` (number), `message` (string), `data` (array)
     - `body.data` length === 38
     - Each item has `id`, `nama_polda`, `latitude`, `longitude`
     - Latency < 1000ms via `assertLatency()` helper (same pattern as existing tests)

## Risk / Edge Cases

- **Truncate with live FK references**: Disabled FK checks → no cascade issues. Re-enabled immediately after truncate.
- **Duplicate run**: Truncate before seed → idempotent. Each run produces fresh IDs starting from 1.
- **`tbl_polres` already migrated**: Check `INFORMATION_SCHEMA.COLUMNS` for `polres_id`; if exists, skip migration.
- **CLI guard**: `is_cli()` check in constructor prevents HTTP access.
- **Rate limiting**: N/A (CLI).
- **DB connection failure**: PHP built-in error handling surfaces during `__construct`.

## Validation

1. `php index.php seeder run` — prints "Master Data Seeded Successfully!"
2. `mysql -u root -proot sindomondb -e "SELECT COUNT(*) FROM tbl_polda"` → 38
3. `mysql -u root -proot sindomondb -e "SELECT COUNT(*) FROM tbl_polres"` → 76
4. `npx playwright test tests/api/seeder_master.spec.ts` — all pass
