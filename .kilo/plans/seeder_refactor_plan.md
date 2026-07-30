# Seeder Refactor Plan

## 1. Missing Tables → `_ensure_tables()` Additions

Three tables are used by production controllers but missing from `Seeder::_ensure_tables()`:

### a) `tbl_satwa` (used by `Logistik::satwa_post()`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_satwa` (
    `satwa_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
    `polda_id` int(11) DEFAULT NULL,
    `nomor_registrasi` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `jenis_satwa` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `nama_satwa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `nama_handler` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `kualifikasi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `jadwal_vaksin` date DEFAULT NULL,
    `foto_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`satwa_id`),
    UNIQUE KEY `uq_nomor_registrasi` (`nomor_registrasi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### b) `tbl_sarpras` (planned for `Sarpras` controller — not yet implemented, but schema defined in refactor plan)
```sql
CREATE TABLE IF NOT EXISTS `tbl_sarpras` (
    `sarpras_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
    `polda_id` int(11) DEFAULT NULL,
    `kode_barang` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `nama_barang` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `kategori` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `kondisi` enum('Baik','Rusak Ringan','Rusak Berat') COLLATE utf8mb4_unicode_ci DEFAULT 'Baik',
    `tahun_pengadaan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `foto_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`sarpras_id`),
    UNIQUE KEY `uq_kode_barang` (`kode_barang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### c) `tbl_dms_surat` (used by `Dms::surat()`)
```sql
CREATE TABLE IF NOT EXISTS `tbl_dms_surat` (
    `surat_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
    `pengirim_polda_id` int(11) DEFAULT NULL,
    `penerima_polda_id` int(11) DEFAULT NULL,
    `judul_surat` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `nomor_surat` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `file_pdf_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status_tracking` enum('Terkirim','Dibaca') COLLATE utf8mb4_unicode_ci DEFAULT 'Terkirim',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`surat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### d) `tbl_hub_pengaduan` (exists in SQL v5 dump, NOT in `_ensure_tables()`)
Add to `_ensure_tables()` for idempotent bootstrap on shared hosting:
```sql
CREATE TABLE IF NOT EXISTS `tbl_hub_pengaduan` (
    `pengaduan_id` int(11) NOT NULL AUTO_INCREMENT,
    `polda_id` int(11) DEFAULT NULL,
    `sumber` enum('Email','Hotline') COLLATE utf8mb4_unicode_ci NOT NULL,
    `deskripsi` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status` enum('Open','In Progress','Resolved','Closed') COLLATE utf8mb4_unicode_ci DEFAULT 'Open',
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`pengaduan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 2. Truncate List Update

Add these tables to the `run()` truncate block:
- `tbl_satwa`
- `tbl_sarpras`
- `tbl_dms_surat`
- `tbl_hub_pengaduan`

**Order matters** — truncate child tables before parents:
```
tbl_sitkamtibmas → tbl_senjata → tbl_amunisi_batch → tbl_proses_hukum
→ tbl_personil → tbl_satwa → tbl_sarpras → tbl_dms_surat → tbl_hub_pengaduan
→ tbl_kategori_senjata → tbl_pangkat → tbl_jabatan → tbl_polres → tbl_polda
```

---

## 3. High-Volume Data Injection — Row Counts

| Table | Current Rows | Target Rows | Pagination Pages (limit=10) |
|-------|:-----------:|:-----------:|:---------------------------:|
| `tbl_personil` | 25 | **50** | 5 pages |
| `tbl_proses_hukum` | 2 | **8** | 1 page (scoped to personil FK subset) |
| `tbl_senjata` | 2 | **35** | 4 pages |
| `tbl_amunisi_batch` | 1 | **30** | 3 pages |
| `tbl_satwa` | 0 | **25** | 3 pages |
| `tbl_sarpras` | 0 | **30** | 3 pages |
| `tbl_sitkamtibmas` | 2 | **30** | 3 pages |
| `tbl_dms_surat` | 0 | **25** | 3 pages |
| `tbl_hub_pengaduan` | 0 | **25** | 3 pages |

**Sum**: ~258 transactional rows across 9 tables.

---

## 4. Relational Integrity Strategy

- `polda_id`: pick randomly from 38 polda IDs (1-38).
- `polres_id`: pick from `polres_by_polda[$polda_id]` array.
- `pangkat_id`: random 1-13.
- `jabatan_id`: distribute across all 5 jabatan (Dirsamapta through Anggota Dalmas), NOT just Anggota Dalmas + Komandan Peleton. Mix includes 15 Sabhara-style (Anggota Dalmas), 10 Pamobvit-style (Komandan Peleton + Dirsamapta), rest rotating.
- `kategori_id`: random 1-2 (Pendek/9mm or Panjang/5.56mm).

---

## 5. Indonesian Localized Dummy Data — Examples

### tbl_personil (50 rows — real Indonesian police names)
```
nrp: 81091234, nama: AKBP Budi Santoso, S.I.K., pangkat: AKBP, jabatan: Dirsamapta, status: Aktif
nrp: 85010456, nama: Iptu Rina Marlina, pangkat: Iptu, jabatan: Kasat Sabhara, status: Aktif
nrp: 95060789, nama: Bripka Hendra Gunawan, pangkat: Bripka, jabatan: Komandan Peleton, status: Aktif
nrp: 00110890, nama: Briptu Andi Prasetyo, pangkat: Briptu, jabatan: Anggota Dalmas, status: Mutasi
nrp: 88051234, nama: Kompol Ahmad Fauzi, S.H., pangkat: Kompol, jabatan: Wadirsamapta, status: Aktif
```

### tbl_satwa (25 rows — K9 + Turangga horses)
```
K9:
  nomor_registrasi: K9-ACEH-001, jenis: K9, nama: Helder, handler: Briptu Doni Kusuma, kualifikasi: Pelacak
  nomor_registrasi: K9-METRO-003, jenis: K9, nama: Bruno, handler: Bripka Rudi Hartono, kualifikasi: Narkotika
  nomor_registrasi: K9-JABAR-007, jenis: K9, nama: Rocky, handler: Brigpol Eko Prasetyo, kualifikasi: Patroli
Turangga:
  nomor_registrasi: TRG-METRO-001, jenis: Turangga, nama: Gagak Rimang, handler: Aiptu Suryadi, kualifikasi: Dalmas
  nomor_registrasi: TRG-JATIM-002, jenis: Turangga, nama: Bima Sakti, handler: Briptu Wahyu Nugroho, kualifikasi: Patroli
```

### tbl_sarpras (30 rows — tactical vehicles + equipment)
```
kode: SPR-APC-001, nama: APC Anoa 6x6, kategori: Kendaraan Taktis, kondisi: Baik, tahun: 2022
kode: SPR-WC-001, nama: Water Cannon Barracuda, kategori: Kendaraan Taktis, kondisi: Baik, tahun: 2023
kode: SPR-RTS-001, nama: Rantis Tambun, kategori: Kendaraan Taktis, kondisi: Rusak Ringan, tahun: 2021
kode: SPR-MTK-001, nama: Motor Trail Kawasaki KLX250, kategori: Kendaraan, kondisi: Baik, tahun: 2024
kode: SPR-HTG-001, nama: HT Motorola GP380, kategori: Alat Komunikasi, kondisi: Baik, tahun: 2023
kode: SPR-BRG-001, nama: Borgol Standar Polri, kategori: Perlengkapan Dalmas, kondisi: Baik, tahun: 2024
```

### tbl_sitkamtibmas (30 rows — mix of levels for UI alert testing)
```
Level Aman (15): "Situasi kondusif, patroli rutin berjalan normal"
Level Waspada (10): "Terdeteksi kerumunan massa di pusat kota, patroli ditingkatkan"
Level Darurat (5):  "Bentrokan antar kelompok di wilayah perbatasan, backup diperlukan"
```

### tbl_dms_surat (25 rows — inter-polda correspondence)
```
nomor: R/KUM.1/42/VI/2026, judul: Permintaan Bantuan Hukum Kasus Narkotika, pengirim: Polda Metro Jaya → penerima: Polda Jabar
nomor: B/INTEL.1/15/VII/2026, judul: Laporan Intelijen Mingguan Wilayah Perbatasan, pengirim: Polda Kalbar → penerima: Mabes (null)
nomor: R/OPS.1/88/VII/2026, judul: Koordinasi Pengamanan Pilkada Serentak, pengirim: Mabes (null) → penerima: Polda Jatim
```

### tbl_hub_pengaduan (25 rows — public complaints)
```
sumber: Hotline, deskripsi: Laporan pencurian kendaraan bermotor di Jalan Sudirman, status: Open
sumber: Email, deskripsi: Pengaduan pungutan liar oknum Polantas di Terminal Bus, status: In Progress
sumber: Hotline, deskripsi: Laporan balapan liar mengganggu ketertiban umum malam hari, status: Resolved
sumber: Email, deskripsi: Aduan pelayanan lambat pembuatan SKCK di Polres setempat, status: Closed
```

---

## 6. Implementation Tasks (ordered)

1. **Add `_ensure_tables()` entries** for `tbl_satwa`, `tbl_sarpras`, `tbl_dms_surat`, `tbl_hub_pengaduan`
2. **Update `run()` truncate list** — add 4 new tables in correct FK order
3. **Expand `_seed_sdm_master()`** — add 3 new jabatan for variety: `Kasi Propam`, `Anggota Samapta`, `Paur Humas`
4. **Expand `_seed_personil()`** — 50 rows with realistic names, distribute across all jabatan + all poldas, mix Aktif/Mutasi/Pensiun status
5. **Add `_seed_senjata_amunisi()`** — 35 senjata + 30 amunisi_batch with diverse kaliber, varied status_kelayakan (Laik/Rusak Ringan), realistic serial numbers, expiry ranging from +30d to +400d
6. **Add `_seed_satwa()`** — 25 rows, 15 K9 + 10 Turangga, realistic names (Helder, Bruno, Rocky, Gagak Rimang, Bima Sakti)
7. **Add `_seed_sarpras()`** — 30 rows, vehicles + comms + equipment, spread across all 38 poldas
8. **Expand `_seed_operasional()`** (sitkamtibmas) — 30 rows with 15 Aman / 10 Waspada / 5 Darurat
9. **Add `_seed_dms_surat()`** — 25 surat with realistic inter-polda correspondence, mix of Terkirim/Dibaca status
10. **Add `_seed_pengaduan()`** — 25 tiket across 4 statuses, mix Email/Hotline sources
11. **Verify** — `php index.php seeder run` succeeds, spot-check row counts with SQL

---

## 7. Risks & Notes

- **Shared hosting FK constraints**: Some shared hosts disable InnoDB FK support. All `CREATE TABLE` statements use `IF NOT EXISTS`. FK constraints defined inline are safe — if host rejects them, tables still create without them.
- **UUID collision**: Zero risk with 258 UUIDv4 rows.
- **Photo URLs**: Use `https://placehold.co/400x300?text=...` placeholders — no real file upload needed for seed data.
- **`tbl_sarpras` has no production controller yet** — schema is forward-compatible per the refactor plan. Seed data is inert until the controller is built.
- **Execution time**: ~300-500 INSERTs with batches of 25-50. < 2 seconds on typical shared hosting MySQL.
