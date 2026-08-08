# Work Summary — Executive Command Center Dashboard (Backend)

**Date:** 2026-08-08
**Scope:** CI3 backend — audit phase + build phase for the Executive Command Center dashboard endpoints.

---

## 1. What Was Done

### Phase 1 — DEBUG / Audit (no code written)
Investigated the existing backend to see what dashboard infrastructure existed.

**Findings:**
- No `Dashboard.php` / `CommandCenter.php` / `Executive.php` controller existed; no dashboard routes in `routes.php`.
- Only one aggregation query in the entire codebase (`Sdm::org_tree_get()`), and it had a bug: it counted all personnel regardless of `status_aktif` (included `Mutasi`/`Pensiun`).
- `Kamtibmas` was write-only (`POST /laporan`); **no GET endpoint** for SITKAMTIBMAS reports existed.
- All 6 PRD dashboard metrics (personnel, vacancy, senjata/sarpras, K9, sitkamtibmas, drill-down) were **missing**.
- Polda coordinates (lat/lng) existed in `tbl_polda` (38 rows) but were never enriched with aggregated metrics.

**Artifact:** `plan/backend_dashboard_audit.md` — current logic inventory, data gap analysis, refactor blueprint.

### Phase 2 — CODE / Execute
Built the dashboard controller from scratch and registered its routes.

**Deliverables:**
| File | Change |
|---|---|
| `application/controllers/Dashboard.php` | **New** (519 lines) — 2 public endpoints + 12 private helpers |
| `application/config/routes.php` | **+5 lines** — 4 dashboard routes before `404_override` |
| `plan/backend_dashboard_build.md` | Full code + routes block + verification matrix |

---

## 2. Endpoints Added

### `GET /api/v1/dashboard/nasional`
Single JSON payload (no client-side assembly / memory leaks):
- `ringkasan` — 11 metrics: `total_personil_aktif`, `total_formasi_ideal`, `total_jumlah_riil`, `selisih_kekurangan`, `total_senjata`, `total_senjata_layak`, `total_sarpras`, `total_sarpras_baik`, `total_satwa_k9`, `total_amunisi_butir`, `amunisi_h90_alert`
- `peta` — 38 active polda nodes with `latitude`, `longitude`, per-polda personil/senjata/sarpras/K9 counts
- `sitkamtibmas_terkini` — 10 latest reports (JOIN `tbl_polda` for `nama_polda`)

### `GET /api/v1/dashboard/drilldown?polda_id=X`
Per-polda detail popup:
- `polda` (id, nama, lat/lng) · `personil` (aktif/mutasi/pensiun) · `vakansi` (total ideal vs riil + per-jabatan `detail_per_jabatan` with `is_alert`) · `logistik` (senjata layak/tidak, sarpras kondisi, K9, amunisi + H-90) · `sitkamtibmas_terkini` (scoped)

### Role / jurisdiction enforcement
- **role_id=2 (Operator Polda):** locked to JWT `polda_id` — query params ignored, cannot cross jurisdictions
- **role_id=1/3 (Admin/Eksekutif):** national by default, optional `?polda_id=` filter; drilldown requires the param (400 if missing)

---

## 3. Verification (smoke-tested against live seeded MySQL)

`php -l` clean on both files. All 8 scenarios passed:

| # | Scenario | Result |
|---|---|---|
| 1 | `nasional` (admin, national) | ✅ 200 — 38 nodes, 10 reports, full ringkasan |
| 2 | `nasional?polda_id=2` (admin) | ✅ 200 — scoped, 1 node |
| 3 | `drilldown?polda_id=2` (admin) | ✅ 200 — full popup payload |
| 4 | `drilldown` without param (admin) | ✅ 400 |
| 5 | `nasional?polda_id=1` (operator, JWT polda 12) | ✅ locked to polda 12 |
| 6 | `drilldown?polda_id=1` (operator) | ✅ forced to polda 12 |
| 7 | no token | ✅ 401 |
| 8 | `drilldown?polda_id=999` | ✅ 404 |

### Sample national ringkasan (live seed data)
```json
{"total_personil_aktif":44, "total_formasi_ideal":45, "total_jumlah_riil":44,
 "selisih_kekurangan":1, "total_senjata":35, "total_senjata_layak":27,
 "total_sarpras":30, "total_sarpras_baik":23, "total_satwa_k9":15,
 "total_amunisi_butir":185899, "amunisi_h90_alert":6}
```

---

## 4. Bugs Found & Fixed During the Work

1. **`status_kelayakan` spelling mismatch** — seeder stores `'Laik'` (KBBI spelling), not `'Layak'`. A literal `= 'Layak'` filter returned 0/35. Fixed with `NOT IN ('Rusak Ringan','Rusak Berat')` → correctly counts 27 layak; robust to both spellings.
2. **`org_tree_get()` pre-existing bug** — counts non-active personnel as riil. New dashboard code enforces `status_aktif = 'Aktif'` everywhere; vacancy JOIN keeps the filter in the ON clause so all 8 jabatan rows always render.

## 5. Key Design Decisions

- **Peta nodes via PHP loop** (38 × 4 small COUNT queries) — zero Cartesian-product risk from multi-table JOINs, per mission instruction.
- **`formasi_ideal` never polda-filtered** — it's national master data (`tbl_jabatan` has no `polda_id`).
- **H-90 ammo alert** mirrors existing `Logistik::amunisi_get()` logic (`tanggal_kedaluwarsa <= NOW()+90 days`).
- **INT casting** on all numeric fields for Flutter JSON parsing; empty collections `[]`, top-level `data` never `null`.

## 6. Recommended Follow-ups (not yet done)

- Playwright E2E tests: `tests/api/dashboard.spec.ts` covering the 8 scenarios
- MySQL composite indexes: `(polda_id, status_aktif)` on `tbl_personil`, `(polda_id, jenis_satwa)` on `tbl_satwa`, `(polda_id)` on `tbl_senjata`/`tbl_sarpras`, `(polda_id, created_at)` on `tbl_sitkamtibmas`
- Optional optimization: 4× `GROUP BY polda_id` queries merged in PHP instead of the 152-query loop (same result, fewer round-trips)

---

## Artifacts

| File | Purpose |
|---|---|
| `plan/backend_dashboard_audit.md` | Phase 1 audit — current logic, gaps, blueprint |
| `plan/backend_dashboard_build.md` | Phase 2 build — complete code + routes + verification |
| `plan/work_summary.md` | This file — overall summary |
| `application/controllers/Dashboard.php` | New controller (the code) |
| `application/config/routes.php` | Routes registered (4 lines added) |
