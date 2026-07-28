<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pengaduan extends CI_Controller {

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
        $this->load->library('session');
        $this->load->helper('uuid');
        $this->load->helper('string');
        $this->load->helper('jwt');
        $this->load->library('jwt');
    }

    /**
     * PATCH /api/v1/pengaduan/tiket/{pengaduan_id}/status
     * Ubah status tiket pengaduan (Operator Polda only)
     */
    /**
     * GET /api/v1/pengaduan/tiket
     * Tarik daftar hub pengaduan terpadu (Operator Polda role_id=2 + Eksekutif role_id=3)
     * Query params (all optional): status, sumber, polda_id
     */
    public function tiket()
    {
        // ── 1. AUTH: Smart JWT extraction (Bearer or raw token) ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array("message" => "Token tidak valid", "status" => 401, "data" => array()));
            return;
        }

        // ── 2. ROLE CHECK: Operator Polda (2) or Eksekutif (3) ──
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2 && $role_id != 3) {
            $this->output->set_status_header(403);
            echo json_encode(array("message" => "Anda tidak memiliki akses", "status" => 403, "data" => array()));
            return;
        }

        // ── 3. QUERY PARAMS (all optional) ──
        $status  = $this->input->get('status');
        $sumber  = $this->input->get('sumber');
        $polda_id_param = $this->input->get('polda_id');

        // ── 4. BUILD WHERE CONDITIONS ──
        $where = array();
        $force_open = false;

        if ($role_id == 2) {
            // OPERATOR POLDA: Ignore polda_id from query, force JWT polda_id
            $jwt_polda_id = isset($payload['polda_id']) ? $payload['polda_id'] : null;
            if ($jwt_polda_id !== null) {
                $where[] = "polda_id = '" . $this->db->escape_str($jwt_polda_id) . "'";
            }
        } else {
            // EKSEKUTIF (role_id=3): Use polda_id from query if provided
            if ($polda_id_param !== null && $polda_id_param !== '') {
                $where[] = "polda_id = '" . $this->db->escape_str((int) $polda_id_param) . "'";
                $force_open = true;
            }
        }

        // Status filter
        if ($force_open) {
            // Executive drill-down: Force status = 'Open'
            $where[] = "status = 'Open'";
        } else if ($status !== null && $status !== '') {
            $valid_statuses = array('Open', 'In Progress', 'Resolved', 'Closed');
            if (in_array($status, $valid_statuses)) {
                $where[] = "status = '" . $this->db->escape_str($status) . "'";
            }
        }

        // Sumber filter
        if ($sumber !== null && $sumber !== '') {
            $valid_sumber = array('Email', 'Hotline');
            if (in_array($sumber, $valid_sumber)) {
                $where[] = "sumber = '" . $this->db->escape_str($sumber) . "'";
            }
        }

        // ── 5. EXECUTE QUERY ──
        $where_clause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        $sql = $this->db->query(
            "SELECT pengaduan_id, sumber, deskripsi, status, created_at FROM tbl_hub_pengaduan " . $where_clause . " ORDER BY created_at DESC"
        );
        $results = $sql->result_array();

        // Flutter: cast pengaduan_id to int
        foreach ($results as &$row) {
            $row['pengaduan_id'] = (int) $row['pengaduan_id'];
        }
        unset($row);

        // ── 6. RESPONSE ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Berhasil",
            "status" => 200,
            "data" => $results
        ));
    }

    public function ubah_status($pengaduan_id)
    {
        // ── 1. AUTH: Smart JWT extraction (Bearer or raw token) ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array("message" => "Token tidak valid", "status" => 401, "data" => array()));
            return;
        }

        // ── 2. ROLE CHECK: Operator Polda only (role_id = 2) ──
        if (!isset($payload['role_id']) || $payload['role_id'] != 2) {
            $this->output->set_status_header(403);
            echo json_encode(array("message" => "Anda tidak memiliki akses", "status" => 403, "data" => array()));
            return;
        }

        // ── 3. INPUT VALIDATION: Parse body + validate status ──
        $input = json_decode($this->input->raw_input_stream, true);

        if (empty($input) || !isset($input['status']) || trim($input['status']) === '') {
            $this->output->set_status_header(400);
            echo json_encode(array("message" => "Field 'status' wajib diisi", "status" => 400, "data" => array()));
            return;
        }

        $valid_statuses = array('Open', 'In Progress', 'Resolved', 'Closed');
        if (!in_array($input['status'], $valid_statuses)) {
            $this->output->set_status_header(400);
            echo json_encode(array("message" => "Status tidak valid. Status harus salah satu dari: Open, In Progress, Resolved, Closed", "status" => 400, "data" => array()));
            return;
        }

        // ── 4. SELECT: Get ticket data ──
        $sql = $this->db->query("SELECT pengaduan_id, polda_id, status FROM tbl_hub_pengaduan WHERE pengaduan_id = '" . $this->db->escape_str($pengaduan_id) . "'");

        if ($sql->num_rows() == 0) {
            $this->output->set_status_header(404);
            echo json_encode(array("message" => "Pengaduan tidak ditemukan", "status" => 404, "data" => array()));
            return;
        }

        $ticket = $sql->row_array();

        // ── 5. DOUBLE PROTECTION: Check if already Closed ──
        if ($ticket['status'] == 'Closed') {
            $this->output->set_status_header(403);
            echo json_encode(array("message" => "Tiket yang sudah ditutup tidak dapat diubah kembali untuk menjaga integritas data audit.", "status" => 403, "data" => array()));
            return;
        }

        // ── 6. DOUBLE PROTECTION: Verify polda_id ownership ──
        $jwt_polda_id = isset($payload['polda_id']) ? $payload['polda_id'] : null;
        if ($jwt_polda_id === null || $jwt_polda_id != $ticket['polda_id']) {
            $this->output->set_status_header(403);
            echo json_encode(array("message" => "Anda tidak memiliki akses", "status" => 403, "data" => array()));
            return;
        }

        // ── 7. UPDATE: Change ticket status ──
        $update = $this->db->query(
            "UPDATE tbl_hub_pengaduan SET status = '" . $this->db->escape_str($input['status']) . "' WHERE pengaduan_id = '" . $this->db->escape_str($pengaduan_id) . "'"
        );

        if ($update) {
            $this->output->set_status_header(200);
            $updated = $this->db->query("SELECT pengaduan_id, polda_id, status, created_at FROM tbl_hub_pengaduan WHERE pengaduan_id = '" . $this->db->escape_str($pengaduan_id) . "'")->row_array();

            // Flutter: cast INT columns
            $updated['pengaduan_id'] = (int) $updated['pengaduan_id'];
            $updated['polda_id'] = (int) $updated['polda_id'];

            echo json_encode(array(
                "message" => "Status tiket berhasil diubah",
                "status" => 200,
                "data" => $updated
            ));
        } else {
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal mengubah status tiket",
                "status" => 500,
                "data" => array()
            ));
        }
    }
}
