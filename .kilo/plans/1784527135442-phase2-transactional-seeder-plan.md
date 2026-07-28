# Phase 2: Transactional & Operational Data Seeding — Execution Plan

## Context

Existing `Seeder.php` seeds 38 Polda, 76 Polres, 13 Pangkat, 5 Jabatan, and 2 Kategori Senjata. This plan injects transactional data that triggers 3 specific UI alert scenarios: **Vacancy Alert** (SDM), **H-90 Ammo Alert** (Logistik), and **Darurat Map Alert** (Kamtibmas).

## Pre-flight: Table Schemas Required

Based on existing controller code (`Sdm.php`, `Logistik.php`, `Kamtibmas.php`):

| Table | PK | Key Info |
|-------|----|----------|
| `tbl_personil` | `personil_id VARCHAR(36)` | UUID PK, FK to polda/polres/pangkat/jabatan |
| `tbl_proses_hukum` | `hukum_id INT AUTO` | FK `personil_id` → `tbl_personil` |
| `tbl_amunisi_batch` | `batch_id INT AUTO` | Has `polda_id`, `kode_batch`, `tanggal_kedaluwarsa` |
| `tbl_senjata` | `senjata_id VARCHAR(36)` | UUID PK, `foto_url` mandatory |
| `tbl_sitkamtibmas` | `sitkamtibmas_id VARCHAR(36)` | UUID PK, `level_kritis` enum(AMan,Waspada,Darurat) |

---

## Task 1: Add `_generate_uuid_v4()` to `Seeder.php`

**File:** `application/controllers/Seeder.php`

- Add private method `_generate_uuid_v4()` (standalone, matches existing `uuid_helper.php` logic via `random_bytes(16)` + bit masking).
- No external class dependency — pure PHP. The existing seeder doesn't load `uuid_helper`, so self-contained method avoids a new `$this->load->helper('uuid')` call.

## Task 2: Register New Tables in `_ensure_tables()`

**File:** `application/controllers/Seeder.php`

Add `CREATE TABLE IF NOT EXISTS` blocks for:
1. `tbl_personil` — `personil_id VARCHAR(36) PK`, `nrp VARCHAR(20)`, `nama_lengkap VARCHAR(255)`, `pangkat_id INT`, `jabatan_id INT`, `status_aktif VARCHAR(50)`, `polda_id INT`, `polres_id INT`, `created_at DATETIME`
2. `tbl_proses_hukum` — `hukum_id INT AUTO PK`, `personil_id VARCHAR(36) FK`, `klasifikasi ENUM(...)`, `status_hukum VARCHAR(100)`, `tanggal_mulai DATE`, `deskripsi_kasus TEXT`, `created_at DATETIME`
3. `tbl_amunisi_batch` — `batch_id INT AUTO PK`, `polda_id INT`, `kode_batch VARCHAR`, `kategori_id INT`, `jumlah_butir INT`, `tanggal_masuk DATE`, `tanggal_kedaluwarsa DATE`, `created_at DATETIME`, `updated_at DATETIME`
4. `tbl_senjata` — `senjata_id VARCHAR(36) PK`, `nomor_seri VARCHAR`, `kategori_id INT`, `polda_id INT`, `tahun_pengadaan VARCHAR`, `status_kelayakan VARCHAR`, `foto_url VARCHAR`, `created_at DATETIME`
5. `tbl_sitkamtibmas` — `sitkamtibmas_id VARCHAR(36) PK`, `polda_id INT`, `deskripsi_kejadian TEXT`, `level_kritis ENUM('Aman','Waspada','Darurat')`, `foto_tkp_url VARCHAR`, `created_at DATETIME`

All with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

## Task 3: Update `run()` — Add Truncation + New Seed Methods

**File:** `application/controllers/Seeder.php`

Extend the truncation block in `run()`:
```php
$this->db->truncate('tbl_personil');
$this->db->truncate('tbl_proses_hukum');
$this->db->truncate('tbl_amunisi_batch');
$this->db->truncate('tbl_senjata');
$this->db->truncate('tbl_sitkamtibmas');
```

Extend execution order (after existing lines):
```php
$this->_seed_personil();
$this->_seed_operasional();
echo "Transactional Data Seeded Successfully!\n";
```

## Task 4: `_seed_personil()` — Vacancy Alert Trap

**File:** `application/controllers/Seeder.php`

### Step 4a — Fetch Key Jabatan IDs
```php
$jabatan = $this->db->query("SELECT jabatan_id, nama_jabatan FROM tbl_jabatan")->result_array();
```
Index by `nama_jabatan` to get IDs for:
- `'Dirsamapta'` (formasi_ideal=1)
- `'Komandan Peleton'` (formasi_ideal=4)
- `'Anggota Dalmas'` (formasi_ideal=50)

### Step 4b — Generate 25 Personnel
- Fetch all 38 `polda_id` from `tbl_polda`.
- Fetch all `polres_id` from `tbl_polres`.
- Loop 25 iterations:
  - Generate UUID via `_generate_uuid_v4()`.
  - Random `polda_id` and `polres_id`.
  - Random `pangkat_id` (1–13).
  - **Jabatan assignment**: Split between `'Anggota Dalmas'` (~20 persons) and `'Komandan Peleton'` (~5 persons).
  - **Key**: NEITHER is assigned to `'Dirsamapta'`. This leaves `jumlah_riil = 0` for Dirsamapta while `formasi_ideal = 1`, so the `_build_tree()` API logic (`$jumlah_riil < $formasi_ideal`) evaluates `is_vacancy_alert = true`.
  - NRP format: `NRP2024` + zero-padded index (e.g., `NRP2024001`).
  - `status_aktif = 'Aktif'`.

### Step 4c — Insert 1–2 `tbl_proses_hukum`
- Pick 1–2 `personil_id` from the generated set.
- Insert into `tbl_proses_hukum`:
  - `klasifikasi`: 'Pemeriksaan Propam' or 'Sidang Kode Etik'
  - `status_hukum`: 'Dalam Penyelidikan'
  - `tanggal_mulai`: yesterday's date
  - `deskripsi_kasus`: dummy text

## Task 5: `_seed_operasional()` — H-90 + Weapon + Emergency Map

**File:** `application/controllers/Seeder.php`

### Step 5a — H-90 Ammo Alert
- Insert 1 row into `tbl_amunisi_batch`:
  - `tanggal_masuk`: `date('Y-m-d', strtotime('-100 days'))`
  - `tanggal_kedaluwarsa`: `date('Y-m-d', strtotime('+45 days'))`
  - `kode_batch`: 'BATCH-H90-TRIGGER'
  - `kategori_id`: 1 (9mm)
  - `jumlah_butir`: 5000
  - `polda_id`: 1
- The `amunisi_get()` API calculates `hari_tersisa = floor((strtotime(expiry) - today) / 86400)`. With expiry = +45 days, `hari_tersisa ≈ 45`, which is `< 90`, so `is_h90_alert = true` ✓

### Step 5b — Weapon Insertion
- Insert 2 rows into `tbl_senjata`:
  - UUID generated via `_generate_uuid_v4()`.
  - `nomor_seri`: 'SNJ-00-2024-001', 'SNJ-00-2024-002'
  - `kategori_id`: 1, 2
  - `polda_id`: 1
  - `tahun_pengadaan`: '2024'
  - `status_kelayakan`: 'Laik'
  - `foto_url`: dummy URL like `'https://placehold.co/400x300?text=Senjata+1'`

### Step 5c — Emergency Map Alert
- Insert 2 rows into `tbl_sitkamtibmas`:
  - Row 1 (Aman):
    - `level_kritis = 'Aman'`
    - `deskripsi_kejadian`: dummy
    - `foto_tkp_url`: dummy
  - Row 2 (Darurat — **this triggers the red blinking**):
    - `level_kritis = 'Darurat'`
    - `deskripsi_kejadian`: 'Laporan Darurat — Tes Trigger Command Center'
    - `foto_tkp_url`: dummy
  - Both use same `polda_id` (e.g., 1).
  - Both use generated UUIDs.

## Task 6: Extend Playwright Tests

**File:** `tests/api/seeder_master.spec.ts` (append new describe block)

### Step 6a — Login as Admin (reuse existing JWT or re-login)
- Use existing `adminJwt` from `test.describe.serial('Seeder Master Data')`.

### Step 6b — Test Endpoint 3.1: `GET /api/v1/sdm/org-tree`
- Request with `Authorization: Bearer ${adminJwt}`.
- Assert HTTP 200.
- Assert response envelope: `status`, `message`, `data`.
- Assert `data.struktur` is a non-empty array.

### Step 6c — Assert Vacancy Alert on "Dirsamapta"
- Search the `struktur` array for the node where `nama_jabatan === 'Dirsamapta'`.
- Assert that `is_vacancy_alert === true`.
- Assert that `formasi_ideal === 1` and `jumlah_riil === 0`.

Implementation approach for node traversal:
```typescript
function findNode(nodes: any[], nama: string): any | null {
  for (const n of nodes) {
    if (n.nama_jabatan === nama) return n;
    const found = findNode(n.bawahan, nama);
    if (found) return found;
  }
  return null;
}
```

---

## Summary of Changes

| File | Change |
|------|--------|
| `application/controllers/Seeder.php` | Add `_generate_uuid_v4()`, extend `_ensure_tables()` with 5 new tables, update `run()` with truncation + new seed calls, add `_seed_personil()` (~70 lines), add `_seed_operasional()` (~60 lines) |
| `tests/api/seeder_master.spec.ts` | Append new test.describe block for org-tree vacancy alert assertion (~50 lines) |

## Validation Steps

1. **CLI**: Run `php index.php seeder run` — should complete without errors.
2. **DB**: Verify count queries — `tbl_personil` has 25 rows, `tbl_proses_hukum` has 1–2 rows, `tbl_amunisi_batch` has 1 row with expiry +45 days, `tbl_senjata` has 2 rows, `tbl_sitkamtibmas` has 2 rows (1 'Aman', 1 'Darurat').
3. **Playwright**: Run `npx playwright test tests/api/seeder_master.spec.ts` — vacancy alert assertion passes.
4. **Manual curl**: `GET /api/v1/sdm/org-tree` returns `"is_vacancy_alert": true` for Dirsamapta node.

## Risks & Caveats

- `tbl_proses_hukum` FK requires `tbl_personil` to exist before insert — handled by sequential `_seed_personil()` → insert hukum after personil.
- UUID generation in Seeder.php is self-contained (no helper dependency).
- `tbl_amunisi_batch` has no FK on `kategori_id` in existing code — safe to insert `kategori_id = 1`.
- All `polda_id`/`polres_id` references assume Phase 1 data (38 Polda, 76 Polres) exists before these seed methods run.
- The `_build_tree()` function returns `is_vacancy_alert` inline at each node — no need to calculate it separately.
