# Executive Command Center Dashboard — Backend Audit Report

**Date:** 2026-08-08  
**Auditor:** Senior CI3 Backend Auditor  
**Status:** DEBUG / PLAN MODE — No code written

---

## 1. Current Logic

### 1.1 Controller Inventory

| Controller | File | Methods | Notes |
|---|---|---|---|
| `Dashboard` | — | — | **Does not exist.** No file, no class, no route. |
| `CommandCenter` | — | — | **Does not exist.** |
| `Executive` | — | — | **Does not exist.** |
| `Polda` | `application/controllers/Polda.php` | `get()` | Legacy endpoint returning all active polda + nested polres |
| `Master` | `application/controllers/Master.php` | `polda_get()`, `wilayah_get()`, `polres_get()`, `pangkat_get()`, `jabatan_get()`, `kategori_senjata_get()` | CRUD for master data; wilayah/polda include lat/lng |
| `Sdm` | `application/controllers/Sdm.php` | `org_tree_get()`, `personil_get()`, `personil_post()`, `personil_put()`, `personil_delete()`, `hukum_post()` | **Only controller with any aggregation** (org_tree) |
| `Logistik` | `application/controllers/Logistik.php` | `senjata_get/post/delete`, `amunisi_get/post/put/delete`, `satwa_get/post/delete`, `sarpras_get/post/delete` | All paginated flat lists; no aggregation |
| `Kamtibmas` | `application/controllers/Kamtibmas.php` | `laporan()` (POST only) | **No GET endpoint.** Write-only. Has a TODO for WebSocket. |
| `Pengaduan` | `application/controllers/Pengaduan.php` | `tiket()`, `ubah_status()` | Complaint tickets, not dashboard-relevant |
| `Dms` | `application/controllers/Dms.php` | `surat()`, `inbox_outbox()`, `download()`, `read()` | Document management, not dashboard-relevant |
| `Knowledge` | `application/controllers/Knowledge.php` | `dokumen()` | Knowledge base, not dashboard-relevant |
| `Auth` | `application/controllers/Auth.php` | `login()`, `insert_user()`, `all()`, `user_put()`, `user_delete()` | Auth only |
| `Role` | `application/controllers/Role.php` | `get/post/put/delete` | Role CRUD |
| `Profile` | `application/controllers/Profile.php` | `get()` | JWT user profile |

### 1.2 Existing Routes (from `application/config/routes.php`)

No dashboard routes exist. The full route table (145 lines) has zero entries matching `dashboard`, `command`, `executive`, `nasional`, `map`, or `drilldown`.

### 1.3 Existing Aggregation Logic

**Only one aggregation query exists in the entire codebase:**

#### `Sdm::org_tree_get()` — `GET /api/v1/sdm/org-tree`

```sql
SELECT j.jabatan_id, j.nama_jabatan, j.formasi_ideal, j.parent_id,
       COUNT(p.personil_id) as jumlah_riil
FROM tbl_jabatan j
LEFT JOIN tbl_personil p ON j.jabatan_id = p.jabatan_id
  [AND p.polda_id = ?]   -- only when target_polda_id is set
GROUP BY j.jabatan_id
ORDER BY j.jabatan_id
```

**Issues with this query for dashboard use:**
- **No `status_aktif` filter** — counts ALL personnel including `Mutasi` and `Pensiun`, not just `Aktif`.
- The vacancy alert (`jumlah_riil < formasi_ideal`) is per-jabatan, not rolled up to polda level.
- The tree structure adds nesting overhead not needed for a dashboard summary card.

**All other `count_all_results()` calls** (9 occurrences across Logistik, Sdm, Master, Auth) are for **pagination totals only** — e.g., `$this->db->count_all_results('tbl_senjata')` to compute `total` for paged responses. None aggregate across dimensions.

### 1.4 Polda Coordinate Endpoints

Two existing endpoints serve `latitude`/`longitude`:

| Endpoint | Controller | Returns |
|---|---|---|
| `GET /api/v1/polda` | `Polda::get()` | All active polda + nested polres per polda. Includes `latitude`, `longitude` as VARCHAR. |
| `GET /api/v1/master/wilayah` | `Master::wilayah_get()` | All polda + nested `polres_jajaran`. Includes `latitude`, `longitude`. |

Both return **raw polda lists without any aggregated metrics** (no personil count, no logistik count, no K9 count, no sitkamtibmas status).

### 1.5 Sitkamtibmas (Write-Only)

`POST /api/v1/kamtibmas/laporan` — accepts `deskripsi_kejadian`, `level_kritis` (Aman|Waspada|Darurat), `foto_tkp` (base64). Auto-injects `polda_id` from JWT. **No GET/read endpoint exists.** There is a TODO comment for WebSocket alert on Darurat level (line 177).

---

## 2. Data Gap Analysis

Based on PRD requirements for the Executive Command Center Dashboard:

### 2.1 National Aggregates (Summary Cards)

| Metric | Required | Current State | Gap |
|---|---|---|---|
| **Total Personel (Active)** | Count of `tbl_personil` WHERE `status_aktif = 'Aktif'` | No query exists. `org_tree_get` counts all personnel without status filter. | **MISSING** — needs new aggregation query |
| **Total Vacant Positions** | `SUM(formasi_ideal) - COUNT(personil WHERE status_aktif='Aktif')` across all jabatan | `org_tree_get` returns per-jabatan vacancy but no rolled-up total. | **MISSING** — needs SUM aggregation across all jabatan |
| **Total Logistik (Senjata)** | `COUNT(*)` from `tbl_senjata` | Paginated list only; no aggregate count endpoint. | **MISSING** |
| **Total Logistik (Sarpras)** | `COUNT(*)` from `tbl_sarpras` | Paginated list only; no aggregate count endpoint. | **MISSING** |
| **Total Satwa K9** | `COUNT(*)` from `tbl_satwa WHERE jenis_satwa = 'K9'` | Paginated list only; no aggregate count endpoint. | **MISSING** |
| **Ammunition Alert** | Count of batches with `tanggal_kedaluwarsa <= NOW() + 90 DAYS` | H-90 alert exists in `amunisi_get()` per-row but not aggregated as a count. | **MISSING** |
| **10 Latest Sitkamtibmas Reports** | `SELECT ... FROM tbl_sitkamtibmas ORDER BY created_at DESC LIMIT 10` | **No GET endpoint at all** for sitkamtibmas. | **MISSING** — needs a read endpoint first |

### 2.2 Map Nodes (38 Polda Markers)

| Metric | Required | Current State | Gap |
|---|---|---|---|
| **Polda coordinates** | 38 polda with `latitude`, `longitude` | Exists in `tbl_polda`, served by `Polda::get()` and `Master::wilayah_get()`. | **PRESENT** but not enriched |
| **Per-polda active personil count** | `COUNT(*) GROUP BY polda_id WHERE status_aktif='Aktif'` | No query. | **MISSING** |
| **Per-polda senjata count** | `COUNT(*) GROUP BY polda_id` | No query. | **MISSING** |
| **Per-polda sarpras count** | `COUNT(*) GROUP BY polda_id` | No query. | **MISSING** |
| **Per-polda K9 count** | `COUNT(*) WHERE jenis_satwa='K9' GROUP BY polda_id` | No query. | **MISSING** |
| **Per-polda vacancy gap** | `SUM(formasi_ideal) - COUNT(personil_aktif)` per polda | No query. | **MISSING** |
| **Per-polda sitkamtibmas status** | Latest `level_kritis` or count by level per polda | No query. | **MISSING** |

### 2.3 Drill-Down (Click on Map Node → Polda Detail)

| Metric | Required | Current State | Gap |
|---|---|---|---|
| **Polda detail aggregates** | All of the above filtered to a specific `polda_id` | `org_tree_get` supports `?polda_id=` but only for org tree. No other domain supports polda-level aggregation. | **MISSING** — needs a single endpoint accepting `polda_id` |
| **Personil list for polda** | Paginated list of active personnel at the polda | `personil_get` supports `?polda_id=` filter but returns flat paginated list, not aggregate counts. | **PARTIAL** — list exists, aggregates don't |
| **Sitkamtibmas history for polda** | Latest reports for the selected polda | No GET endpoint exists at all. | **MISSING** |

### 2.4 Jurisdiction Enforcement Gaps

| Scenario | Requirement | Current State |
|---|---|---|
| Operator Polda (role_id=2) | Should see ONLY their polda's data | All existing GET endpoints already enforce this pattern. |
| Admin/Eksekutif (role_id=1/3) | Should see all polda with optional `?polda_id=` filter | Existing endpoints support this. |
| **National aggregate** (all roles) | Admin/Eksekutif see national totals; Operator sees only their polda | **No endpoint exists** — needs new auth gating. |

---

## 3. Refactor Blueprint

### 3.1 Recommended Architecture: Single Aggregation Controller

**New file:** `application/controllers/Dashboard.php`

**Design principle:** One request → one JSON payload. This prevents the frontend from making 6+ parallel API calls and assembling data client-side, which risks memory leaks on Flutter's `Future.wait()`.

### 3.2 Proposed Endpoints

#### 3.2.1 `GET /api/v1/dashboard/nasional`

**Purpose:** Return everything the Command Center landing page needs in ONE response.

**Auth:** All three roles (1, 2, 3). Operator Polda (role_id=2) sees only their polda's data; Admin/Eksekutif see national aggregates.

**Payload shape:**

```json
{
  "status": 200,
  "message": "Data dashboard berhasil diambil",
  "data": {
    "ringkasan": {
      "total_personil_aktif": 1234,
      "total_formasi_ideal": 1500,
      "total_jumlah_riil": 1180,
      "selisih_kekurangan": 320,
      "total_senjata": 450,
      "total_senjata_layak": 420,
      "total_sarpras": 890,
      "total_sarpras_baik": 800,
      "total_satwa_k9": 25,
      "total_amunisi_butir": 50000,
      "amunisi_h90_alert": 3
    },
    "peta": [
      {
        "polda_id": 1,
        "nama_polda": "Polda Aceh",
        "latitude": "5.5483",
        "longitude": "95.3238",
        "total_personil": 42,
        "total_senjata": 15,
        "total_sarpras": 28,
        "total_k9": 2,
        "level_kritis_terkini": "Aman",
        "vacancy_gap": 12
      }
    ],
    "sitkamtibmas_terkini": [
      {
        "sitkamtibmas_id": "uuid",
        "polda_id": 1,
        "nama_polda": "Polda Aceh",
        "deskripsi_kejadian": "...",
        "level_kritis": "Waspada",
        "created_at": "2026-08-08 10:00:00"
      }
    ]
  }
}
```

**SQL strategy — 3 queries, not 38×N:**

1. **Ringkasan (national or per-polda aggregates):**
   ```sql
   -- Personnel
   SELECT COUNT(*) as total_aktif
   FROM tbl_personil
   WHERE status_aktif = 'Aktif'
   [AND polda_id = ?]

   -- Vacancy
   SELECT SUM(j.formasi_ideal) as total_ideal,
          COUNT(p.personil_id) as total_riil
   FROM tbl_jabatan j
   LEFT JOIN tbl_personil p ON j.jabatan_id = p.jabatan_id
     AND p.status_aktif = 'Aktif'
     [AND p.polda_id = ?]

   -- Logistics (3 tables via UNION or separate count queries)
   SELECT 'senjata' as jenis, COUNT(*) as total FROM tbl_senjata [WHERE polda_id = ?]
   UNION ALL
   SELECT 'sarpras', COUNT(*) FROM tbl_sarpras [WHERE polda_id = ?]
   UNION ALL
   SELECT 'k9', COUNT(*) FROM tbl_satwa WHERE jenis_satwa = 'K9' [AND polda_id = ?]
   ```

2. **Peta (per-polda map nodes) — single GROUP BY query:**
   ```sql
   SELECT po.id AS polda_id, po.nama_polda, po.latitude, po.longitude,
          COUNT(DISTINCT pe.personil_id) as total_personil,
          COUNT(DISTINCT s.senjata_id) as total_senjata,
          COUNT(DISTINCT sp.sarpras_id) as total_sarpras,
          COUNT(DISTINCT sw.satwa_id) as total_k9
   FROM tbl_polda po
   LEFT JOIN tbl_personil pe ON po.id = pe.polda_id AND pe.status_aktif = 'Aktif'
   LEFT JOIN tbl_senjata s ON po.id = s.polda_id
   LEFT JOIN tbl_sarpras sp ON po.id = sp.polda_id
   LEFT JOIN tbl_satwa sw ON po.id = sw.polda_id AND sw.jenis_satwa = 'K9'
   WHERE po.is_active = 1
   GROUP BY po.id
   ```

   **⚠️ Warning:** Multi-table LEFT JOIN with DISTINCT counts can produce inflated numbers if one polda has 3 personil × 2 senjata = 6 rows pre-aggregation. **Safer approach:** use correlated subqueries or separate per-table GROUP BY queries assembled in PHP.

3. **Sitkamtibmas terkini — simple ORDER BY + LIMIT:**
   ```sql
   SELECT s.sitkamtibmas_id, s.polda_id, po.nama_polda,
          s.deskripsi_kejadian, s.level_kritis, s.created_at
   FROM tbl_sitkamtibmas s
   JOIN tbl_polda po ON s.polda_id = po.id
   [WHERE s.polda_id = ?]
   ORDER BY s.created_at DESC
   LIMIT 10
   ```

#### 3.2.2 `GET /api/v1/dashboard/drilldown?polda_id=X`

**Purpose:** Detailed view when user clicks a map node.

**Auth:** All three roles. Operator Polda locked to their JWT `polda_id` (ignores query param).

**Payload shape:**

```json
{
  "status": 200,
  "message": "Detail polda berhasil diambil",
  "data": {
    "polda": {
      "id": 1,
      "nama_polda": "Polda Aceh",
      "latitude": "5.5483",
      "longitude": "95.3238"
    },
    "personil": {
      "total_aktif": 42,
      "total_mutasi": 3,
      "total_pensiun": 1
    },
    "vakansi": {
      "total_formasi_ideal": 60,
      "total_jumlah_riil": 42,
      "selisih": 18,
      "detail_per_jabatan": [
        { "nama_jabatan": "Anggota Dalmas", "formasi_ideal": 20, "jumlah_riil": 12, "is_alert": true }
      ]
    },
    "logistik": {
      "senjata": { "total": 15, "layak": 14, "tidak_layak": 1 },
      "sarpras": { "total": 28, "baik": 25, "rusak_ringan": 2, "rusak_berat": 1 },
      "satwa_k9": { "total": 2 },
      "amunisi": { "total_butir": 5000, "total_batch": 8, "h90_alert": 1 }
    },
    "sitkamtibmas_terkini": [ /* same shape as nasional, limited to this polda */ ]
  }
}
```

### 3.3 Route Registration

Add to `application/config/routes.php`:

```php
// Dashboard / Executive Command Center
$route['api/v1/dashboard/nasional']['GET']      = 'dashboard/nasional_get';
$route['api/v1/dashboard/nasional']['OPTIONS']  = 'dashboard/nasional_get';
$route['api/v1/dashboard/drilldown']['GET']     = 'dashboard/drilldown_get';
$route['api/v1/dashboard/drilldown']['OPTIONS'] = 'dashboard/drilldown_get';
```

### 3.4 Implementation Order (Priority)

| Step | Task | Effort | Dependencies |
|---|---|---|---|
| **1** | Create `Dashboard.php` controller skeleton (CORS, JWT auth, role gating) | Small | None |
| **2** | Implement `nasional_get()` — ringkasan + peta + sitkamtibmas | Medium | Step 1 |
| **3** | Implement `drilldown_get()` — per-polda detail aggregates | Medium | Step 1 |
| **4** | Add sitkamtibmas GET endpoint (or inline query in dashboard) | Small | Step 2 |
| **5** | Register routes in `routes.php` | Trivial | Step 2 |
| **6** | Add Playwright E2E tests in `tests/api/` | Medium | Step 5 |
| **7** | Performance audit: add MySQL indexes on `(polda_id, status_aktif)` for `tbl_personil` and `(polda_id, jenis_satwa)` for `tbl_satwa` | Small | Step 3 |

### 3.5 Anti-Patterns to Avoid

1. **❌ One query per polda (38 queries for map nodes).** → Use `GROUP BY polda_id` in a single query.
2. **❌ Multi-table LEFT JOIN with COUNT(DISTINCT).** → Cartesian product risk. Use correlated subqueries or separate GROUP BY queries merged in PHP.
3. **❌ Returning `[]` or `null` for empty data.** → Always return `{}` (PHP `new stdClass()`) for Flutter compatibility.
4. **❌ String IDs in JSON.** → Cast all integer IDs with `(int)` for Flutter's `fromJson` parser.
5. **❌ Forgetting `status_aktif = 'Aktif'` filter.** → The existing `org_tree_get` already has this bug; do not replicate it.
6. **❌ Operator Polda leaking national data.** → When role_id=2, auto-inject `polda_id` from JWT and ignore query params.

### 3.6 Database Index Recommendations

```sql
-- For fast per-polda personil count
ALTER TABLE tbl_personil ADD INDEX idx_polda_status (polda_id, status_aktif);

-- For fast per-polda satwa K9 count
ALTER TABLE tbl_satwa ADD INDEX idx_polda_jenis (polda_id, jenis_satwa);

-- For fast sitkamtibmas ordering
ALTER TABLE tbl_sitkamtibmas ADD INDEX idx_polda_created (polda_id, created_at DESC);

-- For fast senjata/sarpras per-polda counts
ALTER TABLE tbl_senjata ADD INDEX idx_polda (polda_id);
ALTER TABLE tbl_sarpras ADD INDEX idx_polda (polda_id);
```

---

## Appendix A: Table Schema Reference

All tables share `polda_id` (INT), enabling straightforward per-polda aggregation:

| Table | PK | polda_id | Aggregatable Columns |
|---|---|---|---|
| `tbl_personil` | `personil_id` (UUID4) | Yes (INT) | `status_aktif` VARCHAR: 'Aktif', 'Mutasi', 'Pensiun' |
| `tbl_jabatan` | `jabatan_id` (INT) | No (master) | `formasi_ideal` INT, `parent_id` (self-referential tree) |
| `tbl_senjata` | `senjata_id` (UUID4) | Yes (INT) | `status_kelayakan` VARCHAR |
| `tbl_amunisi_batch` | `batch_id` (INT) | Yes (INT) | `jumlah_butir` INT, `tanggal_kedaluwarsa` DATE |
| `tbl_satwa` | `satwa_id` (UUID4) | Yes (INT) | `jenis_satwa` VARCHAR: 'K9' or 'Turangga' |
| `tbl_sarpras` | `sarpras_id` (UUID4) | Yes (INT) | `kondisi` ENUM: 'Baik', 'Rusak Ringan', 'Rusak Berat' |
| `tbl_sitkamtibmas` | `sitkamtibmas_id` (UUID4) | Yes (INT) | `level_kritis` ENUM: 'Aman', 'Waspada', 'Darurat'; `created_at` |
| `tbl_polda` | `id` (INT) | — (master) | `latitude` VARCHAR, `longitude` VARCHAR, `is_active` TINYINT |

---

## Appendix B: Key Files Referenced

| File | Purpose |
|---|---|
| `application/config/routes.php` | All 145 route definitions — no dashboard routes exist |
| `application/controllers/Sdm.php` | Only aggregation query (org_tree) — bug: no `status_aktif` filter |
| `application/controllers/Kamtibmas.php` | Write-only POST; no GET endpoint |
| `application/controllers/Logistik.php` | All paginated lists; no aggregation |
| `application/controllers/Polda.php` | Legacy polda list with lat/lng |
| `application/controllers/Master.php` | Master data CRUD + wilayah with lat/lng |
| `application/controllers/Seeder.php` | Table schemas and seed data (38 polda with coordinates) |
