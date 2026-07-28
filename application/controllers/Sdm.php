<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sdm extends CI_Controller {

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
        $this->load->helper('uuid');
        $this->load->library('jwt');
    }

    /**
     * GET /api/v1/sdm/org-tree
     * Ambil Struktur Organisasi (Org-Tree) & Vacancy Alert
     *
     * Authorization:
     *   - role_id=1 (Administrator) / role_id=3 (Eksekutif): optional ?polda_id= query
     *   - role_id=2 (Operator Polda): locked to JWT polda_id
     */
    public function org_tree_get()
    {
        // ── 1. AUTH ──
        $payload = $this->_extract_jwt_payload();
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
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        if ($role_id == 2) {
            // Operator Polda: locked to JWT polda_id
            $target_polda_id = $jwt_polda_id;
        } else if ($role_id == 1 || $role_id == 3) {
            // Admin / Eksekutif: optional ?polda_id= query param
            $query_polda = $this->input->get('polda_id');
            $target_polda_id = ($query_polda !== null && $query_polda !== '')
                ? (int) $query_polda
                : null; // null = all polda
        } else {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. SQL: LEFT JOIN with polda filter in ON clause ──
        if ($target_polda_id !== null) {
            $sql = "SELECT j.jabatan_id, j.nama_jabatan, j.formasi_ideal, j.parent_id, "
                 . "COUNT(p.personil_id) as jumlah_riil "
                 . "FROM tbl_jabatan j "
                 . "LEFT JOIN tbl_personil p ON j.jabatan_id = p.jabatan_id "
                 . "  AND p.polda_id = " . (int) $target_polda_id . " "
                 . "GROUP BY j.jabatan_id "
                 . "ORDER BY j.jabatan_id";
        } else {
            $sql = "SELECT j.jabatan_id, j.nama_jabatan, j.formasi_ideal, j.parent_id, "
                 . "COUNT(p.personil_id) as jumlah_riil "
                 . "FROM tbl_jabatan j "
                 . "LEFT JOIN tbl_personil p ON j.jabatan_id = p.jabatan_id "
                 . "GROUP BY j.jabatan_id "
                 . "ORDER BY j.jabatan_id";
        }

        $query = $this->db->query($sql);
        $rows = $query->result_array();

        if (empty($rows)) {
            $this->output->set_status_header(200);
            echo json_encode(array(
                "message" => "Data organisasi berhasil diambil",
                "status" => 200,
                "data" => array(
                    "struktur" => array()
                )
            ));
            return;
        }

        // ── 4. BUILD TREE ──
        $tree = $this->_build_tree($rows, null);

        // ── 5. SUCCESS ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Data organisasi berhasil diambil",
            "status" => 200,
            "data" => array(
                "struktur" => $tree
            )
        ));
    }

    /**
     * GET /api/v1/sdm/personil
     * Tarik Daftar Personel (Desentralisasi)
     *
     * Authorization:
     *   - role_id=1 (Administrator) / role_id=3 (Eksekutif): optional ?polda_id=, ?polres_id=, ?search=, ?status=
     *   - role_id=2 (Operator Polda): locked to JWT polda_id
     */
    public function personil_get()
    {
        // ── 1. AUTH ──
        $payload = $this->_extract_jwt_payload();
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
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        if ($role_id == 2) {
            // Operator Polda: locked to JWT polda_id
            $this->db->where('p.polda_id', $jwt_polda_id);
        } else if ($role_id == 1 || $role_id == 3) {
            // Admin / Eksekutif: optional ?polda_id= query param
            $query_polda = $this->input->get('polda_id');
            if ($query_polda !== null && $query_polda !== '') {
                $this->db->where('p.polda_id', (int) $query_polda);
            }
        } else {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. QUERY: SELECT + 4 LEFT JOINs ──
        $this->db->select("
            p.personil_id,
            p.nrp,
            p.nama_lengkap,
            p.status_aktif,
            p.polda_id,
            p.polres_id,
            pkt.nama_pangkat,
            jbt.nama_jabatan,
            prs.nama_polres
        ")
        ->from('tbl_personil p')
        ->join('tbl_pangkat pkt', 'p.pangkat_id = pkt.pangkat_id', 'left')
        ->join('tbl_jabatan jbt', 'p.jabatan_id = jbt.jabatan_id', 'left')
        ->join('tbl_polres prs', 'p.polres_id = prs.polres_id', 'left');

        // ── 4. DYNAMIC FILTERS (GET params) ──

        // ?search= (nama_lengkap OR nrp)
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->group_start()
                ->like('p.nama_lengkap', $search)
                ->or_like('p.nrp', $search)
                ->group_end();
        }

        // ?polres_id= (int)
        $polres_id = $this->input->get('polres_id');
        if ($polres_id !== null && $polres_id !== '') {
            $this->db->where('p.polres_id', (int) $polres_id);
        }

        // ?status= (enum: Aktif, Mutasi, Pensiun)
        $status = $this->input->get('status');
        if ($status !== null && $status !== '') {
            $valid = array('Aktif', 'Mutasi', 'Pensiun');
            if (in_array($status, $valid)) {
                $this->db->where('p.status_aktif', $status);
            } else {
                $this->output->set_status_header(400);
                echo json_encode(array(
                    "message" => "Parameter status tidak valid. Gunakan: Aktif, Mutasi, atau Pensiun.",
                    "status" => 400,
                    "data" => new stdClass()
                ));
                return;
            }
        }

        // ── 5. ORDER & EXECUTE ──
        $this->db->order_by('p.nrp', 'ASC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 6. TYPE CAST relational IDs (Flutter compatibility) ──
        foreach ($rows as &$row) {
            $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
            $row['polda_id'] = (int) $row['polda_id'];
        }
        unset($row);

        // ── 7. SUCCESS ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Daftar personel berhasil dimuat.",
            "status" => 200,
            "data" => $rows
        ));
    }

    /**
     * POST /api/v1/sdm/personil
     * Tambah Personel Baru
     *
     * Auth: role_id=2 (Operator Polda) only. polda_id auto-injected from JWT.
     */
    public function personil_post()
    {
        $payload = $this->_extract_jwt_payload();
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $nrp          = trim($input['nrp'] ?? '');
        $nama_lengkap = trim($input['nama_lengkap'] ?? '');
        $pangkat_id   = (int) ($input['pangkat_id'] ?? 0);
        $jabatan_id   = (int) ($input['jabatan_id'] ?? 0);
        $status_aktif = trim($input['status_aktif'] ?? 'Aktif') ?: 'Aktif';
        $polres_id    = $input['polres_id'] ?? null;

        if ($nrp === '' || $nama_lengkap === '' || $pangkat_id === 0 || $jabatan_id === 0) {
            $this->output->set_status_header(422);
            echo json_encode(array(
                "message" => "Data tidak lengkap. nrp, nama_lengkap, pangkat_id, jabatan_id wajib diisi.",
                "status" => 422,
                "data" => new stdClass()
            ));
            return;
        }

        $existing = $this->db->query(
            "SELECT personil_id FROM tbl_personil WHERE nrp = " . $this->db->escape($nrp)
        );
        if ($existing->num_rows() > 0) {
            $this->output->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Pendaftaran gagal. NRP sudah terdaftar di sistem.",
                "data" => new stdClass()
            ));
            return;
        }

        if ($polres_id === '' || $polres_id === '0' || $polres_id === 0 || $polres_id === null) {
            $polres_id = null;
        } else {
            $polres_id = (int) $polres_id;
        }

        $personil_id = generate_uuid4();

        $sql = "INSERT INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id) "
             . "VALUES ("
             . "'" . $this->db->escape_str($personil_id) . "', "
             . "'" . $this->db->escape_str($nrp) . "', "
             . "'" . $this->db->escape_str($nama_lengkap) . "', "
             . "'" . $this->db->escape_str($pangkat_id) . "', "
             . "'" . $this->db->escape_str($jabatan_id) . "', "
             . "'" . $this->db->escape_str($status_aktif) . "', "
             . "'" . $this->db->escape_str($jwt_polda_id) . "', "
             . ($polres_id === null ? "NULL" : "'" . $this->db->escape_str($polres_id) . "'")
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan data personel",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        $this->output->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Personel berhasil didaftarkan.",
            "data" => array(
                "personil_id" => $personil_id
            )
        ));
    }

    /**
     * PUT /api/v1/sdm/personil/(:any)
     * Edit / Mutasi Personel
     *
     * Auth: role_id=2 (Operator Polda) only.
     * Jurisdiction: UPDATE locked to JWT polda_id.
     */
    public function personil_put($personil_id)
    {
        $payload = $this->_extract_jwt_payload();
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $nrp          = trim($input['nrp'] ?? '');
        $nama_lengkap = trim($input['nama_lengkap'] ?? '');
        $pangkat_id   = (int) ($input['pangkat_id'] ?? 0);
        $jabatan_id   = (int) ($input['jabatan_id'] ?? 0);
        $status_aktif = trim($input['status_aktif'] ?? 'Aktif') ?: 'Aktif';
        $polres_id    = $input['polres_id'] ?? null;

        if ($nrp === '' || $nama_lengkap === '' || $pangkat_id === 0 || $jabatan_id === 0) {
            $this->output->set_status_header(422);
            echo json_encode(array(
                "message" => "Data tidak lengkap. nrp, nama_lengkap, pangkat_id, jabatan_id wajib diisi.",
                "status" => 422,
                "data" => new stdClass()
            ));
            return;
        }

        $existing = $this->db->query(
            "SELECT personil_id FROM tbl_personil WHERE nrp = " . $this->db->escape($nrp)
            . " AND personil_id != " . $this->db->escape($personil_id)
        );
        if ($existing->num_rows() > 0) {
            $this->output->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Pendaftaran gagal. NRP sudah terdaftar di sistem.",
                "data" => new stdClass()
            ));
            return;
        }

        if ($polres_id === '' || $polres_id === '0' || $polres_id === 0 || $polres_id === null) {
            $polres_id = null;
        } else {
            $polres_id = (int) $polres_id;
        }

        $sql = "UPDATE tbl_personil SET "
             . "nrp = '" . $this->db->escape_str($nrp) . "', "
             . "nama_lengkap = '" . $this->db->escape_str($nama_lengkap) . "', "
             . "pangkat_id = '" . $this->db->escape_str($pangkat_id) . "', "
             . "jabatan_id = '" . $this->db->escape_str($jabatan_id) . "', "
             . "status_aktif = '" . $this->db->escape_str($status_aktif) . "', "
             . "polres_id = " . ($polres_id === null ? "NULL" : "'" . $this->db->escape_str($polres_id) . "'") . " "
             . "WHERE personil_id = '" . $this->db->escape_str($personil_id) . "' "
             . "AND polda_id = '" . $this->db->escape_str($jwt_polda_id) . "'";

        $this->db->query($sql);

        if ($this->db->affected_rows() === 0) {
            $this->output->set_status_header(404);
            echo json_encode(array(
                "message" => "Personel tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        $this->output->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data personel berhasil diperbarui.",
            "data" => new stdClass()
        ));
    }

    /**
     * POST /api/v1/sdm/hukum
     * Catat Riwayat Proses Hukum Personel
     *
     * Auth: Operator Polda (role_id=2) only.
     * Jurisdiction: personil_id must belong to JWT polda_id.
     */
    public function hukum_post()
    {
        $payload = $this->_extract_jwt_payload();
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $personil_id    = trim($input['personil_id'] ?? '');
        $klasifikasi    = trim($input['klasifikasi'] ?? '');
        $status_hukum   = trim($input['status_hukum'] ?? '');
        $tanggal_mulai  = trim($input['tanggal_mulai'] ?? '');
        $deskripsi_kasus = trim($input['deskripsi_kasus'] ?? '');

        if ($personil_id === '' || $klasifikasi === '' || $status_hukum === '' || $tanggal_mulai === '') {
            $this->output->set_status_header(422);
            echo json_encode(array(
                "message" => "Data tidak lengkap. personil_id, klasifikasi, status_hukum, tanggal_mulai wajib diisi.",
                "status" => 422,
                "data" => new stdClass()
            ));
            return;
        }

        $valid_klasifikasi = array('Pemeriksaan Propam', 'Sidang Kode Etik', 'Sidang Disiplin', 'Pidana Umum');
        if (!in_array($klasifikasi, $valid_klasifikasi, true)) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Klasifikasi tidak valid. Gunakan: Pemeriksaan Propam, Sidang Kode Etik, Sidang Disiplin, atau Pidana Umum.",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $personil = $this->db->query(
            "SELECT polda_id FROM tbl_personil WHERE personil_id = " . $this->db->escape($personil_id)
        )->row_array();

        if (!$personil || (int) $personil['polda_id'] !== $jwt_polda_id) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak. Personel tidak ditemukan atau berada di luar yurisdiksi Anda.",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        $sql = "INSERT INTO tbl_proses_hukum (personil_id, klasifikasi, status_hukum, tanggal_mulai, deskripsi_kasus) "
             . "VALUES ("
             . "'" . $this->db->escape_str($personil_id) . "', "
             . "'" . $this->db->escape_str($klasifikasi) . "', "
             . "'" . $this->db->escape_str($status_hukum) . "', "
             . "'" . $this->db->escape_str($tanggal_mulai) . "', "
             . ($deskripsi_kasus !== '' ? "'" . $this->db->escape_str($deskripsi_kasus) . "'" : "NULL")
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan catatan hukum",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        $this->output->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Catatan hukum berhasil ditambahkan.",
            "data" => new stdClass()
        ));
    }

    /**
     * Recursive tree builder from flat query result
     *
     * @param array $items  Flat result array from SQL
     * @param int|null $parent_id
     * @return array
     */
    /**
     * Fallback JWT extraction — used if jwt_helper::get_jwt_payload()
     * is unavailable (cascade from helper loading order).
     */
    private function _extract_jwt_payload()
    {
        $auth = null;
        $all_headers = function_exists('getallheaders') ? getallheaders() : [];
        if (isset($all_headers['Authorization'])) {
            $auth = $all_headers['Authorization'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($auth === null) {
            return null;
        }
        if (substr($auth, 0, 7) === 'Bearer ') {
            $auth = substr($auth, 7);
        }
        return $this->jwt->decode($auth) ?: null;
    }

    private function _build_tree($items, $parent_id)
    {
        $branch = array();
        foreach ($items as $item) {
            $item_parent = ($item['parent_id'] !== null) ? (int) $item['parent_id'] : null;
            if ($item_parent !== $parent_id) {
                continue;
            }

            $jumlah_riil = (int) $item['jumlah_riil'];
            $formasi_ideal = (int) $item['formasi_ideal'];

            $node = array(
                "jabatan_id"       => (int) $item['jabatan_id'],
                "nama_jabatan"     => $item['nama_jabatan'],
                "formasi_ideal"    => $formasi_ideal,
                "jumlah_riil"      => $jumlah_riil,
                "is_vacancy_alert" => ($jumlah_riil < $formasi_ideal),
                "bawahan"          => $this->_build_tree($items, (int) $item['jabatan_id'])
            );

            $branch[] = $node;
        }
        return $branch;
    }
}
