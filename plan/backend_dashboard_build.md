# Executive Command Center Dashboard — Backend Build Report

**Date:** 2026-08-08  
**Author:** Senior CI3 Backend Developer  
**Status:** CODE COMPLETE + SMOKE-TESTED (E2E suite not yet extended)

---

## 1. Deliverables

| Artifact | Path | Status |
|---|---|---|
| New controller | `application/controllers/Dashboard.php` | ✅ Created (519 lines) |
| Route registration | `application/config/routes.php` | ✅ 4 routes added |
| Audit (prior phase) | `plan/backend_dashboard_audit.md` | ✅ (from DEBUG phase) |

---

## 2. Complete Code — `application/controllers/Dashboard.php`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard — Executive Command Center
 *
 * Aggregates data across 6 tables (personil, jabatan, senjata, sarpras,
 * satwa, sitkamtibmas) into dashboard-ready payloads for the Flutter
 * Command Center screen. No ORM — direct query builder calls only.
 *
 * Endpoints:
 *   GET /api/v1/dashboard/nasional  — National aggregates + map nodes + 10 latest sitkamtibmas
 *   GET /api/v1/dashboard/drilldown — Per-polda detail popup (?polda_id= required for role 1/3)
 *
 * Role logic:
 *   role_id=2 (Operator Polda) — locked to JWT polda_id, cannot cross jurisdictions
 *   role_id=1 (Admin) / role_id=3 (Eksekutif) — national by default, optional ?polda_id= filter
 */
class Dashboard extends CI_Controller {

    public function __construct() {
        parent::__construct();

        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
        header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: false");

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $this->load->helper('url');
        $this->load->helper('jwt');
        $this->load->library('jwt');
    }

    /**
     * GET /api/v1/dashboard/nasional
     *
     * Executive Command Center landing payload in ONE response:
     *   data.ringkasan            — national (or polda-scoped) aggregate counts
     *   data.peta                 — 38 active polda map nodes w/ per-polda counts
     *   data.sitkamtibmas_terkini — 10 latest SITKAMTIBMAS reports
     *
     * Single payload = no client-side assembly, no memory leaks from
     * parallel Future.wait() calls on Flutter.
     */
    public function nasional_get()
    {
        // ── 1. AUTH: Smart JWT extraction (Bearer or raw token) ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 2. ROLE & JURISDICTION ──
        // role_id=2: locked to JWT polda_id (ignore query param — no cross-jurisdiction)
        // role_id=1/3: 0 = national; optional ?polda_id= override
        $role_id      = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        if ($role_id == 2) {
            $polda_id = $jwt_polda_id;
        } else if ($role_id == 1 || $role_id == 3) {
            $query_polda = $this->input->get('polda_id');
            $polda_id = ($query_polda !== null && $query_polda !== '')
                ? (int) $query_polda
                : 0; // 0 = national
        } else {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. QUERY 1: RINGKASAN (national or polda-scoped) ──
        $ringkasan = $this->_get_ringkasan($polda_id);

        // ── 4. QUERY 2: PETA NODES ──
        // PHP loop with per-polda COUNT queries (38 iterations max) —
        // deliberately avoids multi-table JOIN cartesian inflation.
        $peta = $this->_get_peta_nodes($polda_id);

        // ── 5. QUERY 3: SITKAMTIBMAS TERKINI (10 latest) ──
        $sitkamtibmas_terkini = $this->_get_sitkamtibmas_terkini($polda_id, 10);

        // ── 6. SUCCESS RESPONSE ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Data dashboard berhasil diambil",
            "status" => 200,
            "data" => array(
                "ringkasan"            => $ringkasan,
                "peta"                 => $peta,
                "sitkamtibmas_terkini" => $sitkamtibmas_terkini
            )
        ));
    }

    /**
     * GET /api/v1/dashboard/drilldown?polda_id=X
     *
     * Detail aggregates for one map node (click popup).
     *   - role_id=2: polda_id forced from JWT (query param ignored)
     *   - role_id=1/3: ?polda_id= REQUIRED
     */
    public function drilldown_get()
    {
        // ── 1. AUTH ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 2. ROLE & TARGET POLDATA ──
        $role_id      = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        if ($role_id == 2) {
            // Operator Polda: forced to own jurisdiction
            $target_polda = $jwt_polda_id;
        } else if ($role_id == 1 || $role_id == 3) {
            // Admin / Eksekutif: polda_id query param REQUIRED
            $query_polda = $this->input->get('polda_id');
            if ($query_polda === null || $query_polda === '') {
                $this->output->set_status_header(400);
                echo json_encode(array(
                    "message" => "Parameter polda_id wajib diisi",
                    "status" => 400,
                    "data" => new stdClass()
                ));
                return;
            }
            $target_polda = (int) $query_polda;
        } else {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. POLDATA MASTER (404 if not found / inactive) ──
        $this->db->select('id, nama_polda, latitude, longitude');
        $this->db->from('tbl_polda');
        $this->db->where('id', $target_polda);
        $this->db->where('is_active', 1);
        $polda = $this->db->get()->row_array();

        if (!$polda) {
            $this->output->set_status_header(404);
            echo json_encode(array(
                "message" => "Polda tidak ditemukan",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. AGGREGATES (all strictly filtered to $target_polda) ──
        $personil = array(
            "total_aktif"   => $this->_count_personil_by_status($target_polda, 'Aktif'),
            "total_mutasi"  => $this->_count_personil_by_status($target_polda, 'Mutasi'),
            "total_pensiun" => $this->_count_personil_by_status($target_polda, 'Pensiun')
        );

        $vakansi = $this->_get_vakansi($target_polda);

        $logistik = array(
            "senjata" => array(
                "total"         => $this->_count_table('tbl_senjata', $target_polda),
                "layak"         => $this->_count_senjata_layak($target_polda),
                "tidak_layak"   => $this->_count_senjata_tidak_layak($target_polda)
            ),
            "sarpras" => array(
                "total"         => $this->_count_table('tbl_sarpras', $target_polda),
                "baik"          => $this->_count_by_kondisi('tbl_sarpras', 'kondisi', 'Baik', $target_polda),
                "rusak_ringan"  => $this->_count_by_kondisi('tbl_sarpras', 'kondisi', 'Rusak Ringan', $target_polda),
                "rusak_berat"   => $this->_count_by_kondisi('tbl_sarpras', 'kondisi', 'Rusak Berat', $target_polda)
            ),
            "satwa_k9" => array(
                "total" => $this->_count_k9($target_polda)
            ),
            "amunisi" => array(
                "total_butir" => $this->_sum_amunisi_butir($target_polda),
                "total_batch" => $this->_count_table('tbl_amunisi_batch', $target_polda),
                "h90_alert"   => $this->_count_amunisi_h90($target_polda)
            )
        );

        $sitkamtibmas_terkini = $this->_get_sitkamtibmas_terkini($target_polda, 10);

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Detail polda berhasil diambil",
            "status" => 200,
            "data" => array(
                "polda" => array(
                    "id"         => (int) $polda['id'],
                    "nama_polda" => $polda['nama_polda'],
                    "latitude"   => $polda['latitude'],
                    "longitude"  => $polda['longitude']
                ),
                "personil"            => $personil,
                "vakansi"             => $vakansi,
                "logistik"            => $logistik,
                "sitkamtibmas_terkini" => $sitkamtibmas_terkini
            )
        ));
    }

    /* ══════════════════════════════════════════════════════════════
     *  PRIVATE HELPERS — one aggregation per table, NO JOIN inflation
     * ══════════════════════════════════════════════════════════════ */

    /**
     * National (or polda-scoped) summary card aggregates.
     * $polda_id = 0 → national. $polda_id > 0 → scoped.
     * formasi_ideal is national master data and is NEVER polda-filtered.
     */
    private function _get_ringkasan($polda_id)
    {
        // Personil aktif (Aktif only — Mutasi/Pensiun excluded)
        $total_personil_aktif = $this->_count_personil_by_status($polda_id, 'Aktif');

        // Formasi ideal — SUM from national master data (no polda filter)
        $this->db->select('COALESCE(SUM(formasi_ideal), 0) as total');
        $this->db->from('tbl_jabatan');
        $row = $this->db->get()->row();
        $total_formasi_ideal = $row ? (int) $row->total : 0;

        // Vacancy gap: Formasi Ideal vs Riil (Aktif)
        $selisih_kekurangan = $total_formasi_ideal - $total_personil_aktif;

        // Senjata
        $total_senjata = $this->_count_table('tbl_senjata', $polda_id);
        $total_senjata_layak = $this->_count_senjata_layak($polda_id);

        // Sarpras
        $total_sarpras = $this->_count_table('tbl_sarpras', $polda_id);
        $total_sarpras_baik = $this->_count_by_kondisi('tbl_sarpras', 'kondisi', 'Baik', $polda_id);

        // Satwa K9
        $total_satwa_k9 = $this->_count_k9($polda_id);

        // Amunisi (stockpile + H-90 expiry alert)
        $total_amunisi_butir = $this->_sum_amunisi_butir($polda_id);
        $amunisi_h90_alert   = $this->_count_amunisi_h90($polda_id);

        return array(
            "total_personil_aktif"  => $total_personil_aktif,
            "total_formasi_ideal"   => $total_formasi_ideal,
            "total_jumlah_riil"     => $total_personil_aktif,
            "selisih_kekurangan"    => $selisih_kekurangan,
            "total_senjata"         => $total_senjata,
            "total_senjata_layak"   => $total_senjata_layak,
            "total_sarpras"         => $total_sarpras,
            "total_sarpras_baik"    => $total_sarpras_baik,
            "total_satwa_k9"        => $total_satwa_k9,
            "total_amunisi_butir"   => $total_amunisi_butir,
            "amunisi_h90_alert"     => $amunisi_h90_alert
        );
    }

    /**
     * Map nodes: all active polda (or one) + per-polda COUNTs.
     * PHP loop of small COUNT queries — 38 iterations max, zero JOIN
     * cartesian risk. Each node is one row in a flat array.
     */
    private function _get_peta_nodes($polda_id)
    {
        $this->db->select('id, nama_polda, latitude, longitude');
        $this->db->from('tbl_polda');
        $this->db->where('is_active', 1);
        if ($polda_id > 0) {
            $this->db->where('id', $polda_id);
        }
        $this->db->order_by('id', 'ASC');
        $polda_rows = $this->db->get()->result_array();

        $nodes = array();
        foreach ($polda_rows as $po) {
            $pid = (int) $po['id'];
            $nodes[] = array(
                "polda_id"      => $pid,
                "nama_polda"    => $po['nama_polda'],
                "latitude"      => $po['latitude'],
                "longitude"     => $po['longitude'],
                "total_personil" => $this->_count_personil_by_status($pid, 'Aktif'),
                "total_senjata" => $this->_count_table('tbl_senjata', $pid),
                "total_sarpras" => $this->_count_table('tbl_sarpras', $pid),
                "total_k9"      => $this->_count_k9($pid)
            );
        }

        return $nodes;
    }

    /**
     * Vacancy detail per jabatan for a polda (drill-down popup).
     * Same shape as sdm/org-tree but flat + only active personnel.
     */
    private function _get_vakansi($target_polda)
    {
        $this->db->select('j.jabatan_id, j.nama_jabatan, j.formasi_ideal, COUNT(p.personil_id) as jumlah_riil');
        $this->db->from('tbl_jabatan j');
        // polda + status filter lives in the JOIN ON clause so jabatan rows
        // always appear (LEFT JOIN) even with zero matching personnel
        $this->db->join(
            'tbl_personil p',
            "j.jabatan_id = p.jabatan_id AND p.polda_id = " . (int) $target_polda
                . " AND p.status_aktif = 'Aktif'",
            'left'
        );
        $this->db->group_by('j.jabatan_id');
        $this->db->order_by('j.jabatan_id', 'ASC');
        $rows = $this->db->get()->result_array();

        $total_formasi_ideal = 0;
        $total_jumlah_riil   = 0;
        $detail = array();

        foreach ($rows as $row) {
            $formasi_ideal = (int) $row['formasi_ideal'];
            $jumlah_riil   = (int) $row['jumlah_riil'];
            $total_formasi_ideal += $formasi_ideal;
            $total_jumlah_riil   += $jumlah_riil;

            $detail[] = array(
                "jabatan_id"     => (int) $row['jabatan_id'],
                "nama_jabatan"   => $row['nama_jabatan'],
                "formasi_ideal"  => $formasi_ideal,
                "jumlah_riil"    => $jumlah_riil,
                "is_alert"       => ($jumlah_riil < $formasi_ideal) ? true : false
            );
        }

        return array(
            "total_formasi_ideal" => $total_formasi_ideal,
            "total_jumlah_riil"   => $total_jumlah_riil,
            "selisih"             => $total_formasi_ideal - $total_jumlah_riil,
            "detail_per_jabatan"  => $detail
        );
    }

    /**
     * 10 latest SITKAMTIBMAS reports (optionally polda-scoped).
     */
    private function _get_sitkamtibmas_terkini($polda_id, $limit)
    {
        $this->db->select('s.sitkamtibmas_id, s.polda_id, s.deskripsi_kejadian, s.level_kritis, s.foto_tkp_url, s.created_at, po.nama_polda');
        $this->db->from('tbl_sitkamtibmas s');
        $this->db->join('tbl_polda po', 's.polda_id = po.id', 'left');
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }
        $this->db->order_by('s.created_at', 'DESC');
        $this->db->limit($limit);
        $rows = $this->db->get()->result_array();

        $mapped = array();
        foreach ($rows as $row) {
            $mapped[] = array(
                "sitkamtibmas_id"   => $row['sitkamtibmas_id'],
                "polda_id"          => (int) $row['polda_id'],
                "nama_polda"        => isset($row['nama_polda']) ? $row['nama_polda'] : null,
                "deskripsi_kejadian" => $row['deskripsi_kejadian'],
                "level_kritis"      => $row['level_kritis'],
                "foto_tkp_url"      => $row['foto_tkp_url'],
                "created_at"        => $row['created_at']
            );
        }

        return $mapped;
    }

    /* ── Single-purpose count/sum primitives ── */

    /**
     * COUNT(*) with optional polda_id scope. $polda_id = 0 → national.
     */
    private function _count_table($table, $polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from($table);
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) personil by status_aktif value, optional polda scope.
     */
    private function _count_personil_by_status($polda_id, $status)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from('tbl_personil');
        $this->db->where('status_aktif', $status);
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) by a status/kondisi column value, optional polda scope.
     */
    private function _count_by_kondisi($table, $column, $value, $polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from($table);
        $this->db->where($column, $value);
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) senjata in a usable condition.
     * Seeder stores 'Laik' (KBBI spelling) while clients may send 'Layak' —
     * count everything NOT damaged instead of matching one spelling.
     */
    private function _count_senjata_layak($polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from('tbl_senjata');
        $this->db->where_not_in('status_kelayakan', array('Rusak Ringan', 'Rusak Berat'));
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) senjata with a damaged condition (Rusak Ringan / Rusak Berat).
     */
    private function _count_senjata_tidak_layak($polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from('tbl_senjata');
        $this->db->where_in('status_kelayakan', array('Rusak Ringan', 'Rusak Berat'));
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) satwa of type K9, optional polda scope.
     */
    private function _count_k9($polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from('tbl_satwa');
        $this->db->where('jenis_satwa', 'K9');
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * SUM(jumlah_butir) ammunitions, optional polda scope.
     */
    private function _sum_amunisi_butir($polda_id)
    {
        $this->db->select('COALESCE(SUM(jumlah_butir), 0) as total');
        $this->db->from('tbl_amunisi_batch');
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }

    /**
     * COUNT(*) ammunitions batches expiring within 90 days (H-90 alert).
     * Mirrors the H-90 engine in Logistik::amunisi_get().
     */
    private function _count_amunisi_h90($polda_id)
    {
        $this->db->select('COUNT(*) as total');
        $this->db->from('tbl_amunisi_batch');
        $this->db->where('tanggal_kedaluwarsa <=', date('Y-m-d', strtotime('+90 days')));
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }
        $row = $this->db->get()->row();
        return $row ? (int) $row->total : 0;
    }
}
```

---

## 3. Route Registration — `application/config/routes.php`

Injected before the `404_override` line (end of API routes, after the `/pangkat` + `/jabatan` OPTIONS lines):

```php
// Dashboard / Executive Command Center
$route['api/v1/dashboard/nasional']['GET']      = 'dashboard/nasional_get';
$route['api/v1/dashboard/nasional']['OPTIONS']  = 'dashboard/nasional_get';
$route['api/v1/dashboard/drilldown']['GET']     = 'dashboard/drilldown_get';
$route['api/v1/dashboard/drilldown']['OPTIONS'] = 'dashboard/drilldown_get';
```

---

## 4. Verification (Smoke Test — PHP built-in server + live MySQL seed)

Syntax: `php -l` clean on both files. All 8 scenarios passed against the seeded DB (38 polda, 50 personil, 35 senjata, 30 sarpras, 25 satwa, 30 sitkamtibmas):

| # | Scenario | Result |
|---|---|---|
| 1 | `GET /dashboard/nasional` (admin, national) | ✅ 200 — ringkasan (11 metrics), 38 peta nodes, 10 sitkamtibmas |
| 2 | `GET /dashboard/nasional?polda_id=2` (admin) | ✅ 200 — ringkasan scoped, 1 node, sitkamtibmas filtered to polda 2 |
| 3 | `GET /dashboard/drilldown?polda_id=2` (admin) | ✅ 200 — full popup payload (polda, personil, vakansi w/ per-jabatan `is_alert`, logistik, sitkamtibmas) |
| 4 | `GET /dashboard/drilldown` (admin, no param) | ✅ 400 — "Parameter polda_id wajib diisi" |
| 5 | `GET /dashboard/nasional?polda_id=1` (operator, JWT polda 12) | ✅ locked — 1 node, polda_id=12 (query param ignored) |
| 6 | `GET /dashboard/drilldown?polda_id=1` (operator) | ✅ locked — returns polda 12 "Polda Banten" |
| 7 | No token | ✅ 401 — "Token tidak ditemukan" |
| 8 | `GET /dashboard/drilldown?polda_id=999` (admin) | ✅ 404 — "Polda tidak ditemukan" |
| — | OPTIONS preflight on both routes | ✅ 200 |

### Sample national ringkasan (live seed data)

```json
{
  "total_personil_aktif": 44,
  "total_formasi_ideal": 45,
  "total_jumlah_riil": 44,
  "selisih_kekurangan": 1,
  "total_senjata": 35,
  "total_senjata_layak": 27,
  "total_sarpras": 30,
  "total_sarpras_baik": 23,
  "total_satwa_k9": 15,
  "total_amunisi_butir": 185899,
  "amunisi_h90_alert": 6
}
```

---

## 5. Design Decisions & Deviations (documented)

1. **Peta nodes via PHP loop** (per mission): 38 iterations × 4 small COUNT queries. Zero Cartesian risk; verified ~all nodes in one response. Each node: `polda_id, nama_polda, latitude, longitude, total_personil, total_senjata, total_sarpras, total_k9`.
2. **`status_kelayakan` spelling bug found & handled**: the seeder stores `'Laik'` (27 rows), not `'Layak'`. A literal `= 'Layak'` filter returned 0. Fixed with `NOT IN ('Rusak Ringan','Rusak Berat')` → counts 27, robust to both spellings.
3. **`formasi_ideal` never polda-filtered** (per mission): it is national master data (`tbl_jabatan` has no `polda_id`). In scoped views it stays the national total; the vacancy gap (`selisih_kekurangan`) is then `formasi_ideal - personil_aktif_at_polda` — semantically "national demand vs local supply". The drilldown adds `detail_per_jabatan` with per-jabatan `is_alert` for the local view.
4. **`status_aktif = 'Aktif'` filter enforced everywhere** — fixes the pre-existing `org_tree_get()` bug that counted Mutasi/Pensiun as riil.
5. **Vacancy JOIN**: polda + status filters live in the JOIN ON clause (LEFT JOIN), so all 8 jabatan rows always appear even with zero personnel — matches `org_tree_get()` pattern.
6. **H-90 alert** mirrors `Logistik::amunisi_get()` logic: `tanggal_kedaluwarsa <= NOW()+90 days`.
7. **INT casting** on all numeric fields (`(int)`) for Flutter JSON parsing; empty nested collections return `[]` (consistent with `org_tree_get`), top-level `data` never `null`.

## 6. Recommended Follow-ups (not in scope)

- Extend Playwright E2E (`tests/api/dashboard.spec.ts`) with the 8 scenarios above.
- Add MySQL indexes: `idx_polda_status (polda_id, status_aktif)` on `tbl_personil`, `idx_polda_jenis (polda_id, jenis_satwa)` on `tbl_satwa`, `idx_polda (polda_id)` on `tbl_senjata`/`tbl_sarpras`, `idx_polda_created (polda_id, created_at)` on `tbl_sitkamtibmas`.
- Optional optimization: replace the per-polda PHP loop with 4 `GROUP BY polda_id` queries merged in PHP (4 queries instead of ~152) — same result, lower latency at scale.
