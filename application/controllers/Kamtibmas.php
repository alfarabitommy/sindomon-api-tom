<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Kamtibmas extends CI_Controller {

    public function __construct() {
        parent::__construct();

        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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
        $this->load->helper('base64_file');
        $this->load->library('jwt');
    }

    /**
     * POST /api/v1/kamtibmas/laporan
     * Input Laporan SITKAMTIBMAS [JSON + Base64]
     *
     * Authorization: Operator Polda (role_id=2)
     * Content-Type: application/json
     *
     * JSON fields:
     *   - deskripsi_kejadian (string, required)
     *   - level_kritis (enum: Aman|Waspada|Darurat, required)
     *   - foto_tkp (string, required — base64-encoded jpg/png, max 500KB)
     */
    public function laporan()
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

        // ── 2. ROLE CHECK: Operator Polda only (role_id = 2) ──
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id !== 2) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak. Hanya Operator Polda yang dapat mengirim laporan",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. PARSE JSON BODY ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (empty($input)) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Request body harus berupa JSON",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. VALIDATE FORM FIELDS ──
        $deskripsi_kejadian = isset($input['deskripsi_kejadian']) ? $input['deskripsi_kejadian'] : null;
        $level_kritis = isset($input['level_kritis']) ? $input['level_kritis'] : null;

        if ($deskripsi_kejadian === null || $deskripsi_kejadian === '') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "deskripsi_kejadian wajib diisi",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $allowed_levels = array('Aman', 'Waspada', 'Darurat');
        if (!in_array($level_kritis, $allowed_levels, true)) {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "level_kritis harus salah satu dari: Aman, Waspada, Darurat",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. BASE64 FILE: foto_tkp (jpg/png only, max 500KB) ──
        $base64_file = isset($input['foto_tkp']) ? $input['foto_tkp'] : null;

        if ($base64_file === null || $base64_file === '') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "foto_tkp wajib diisi",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $upload_dir = './uploads/sitkamtibmas/';
        $allowed_mimes = ['image/jpeg', 'image/png'];
        $result = save_base64_file($base64_file, $upload_dir, $allowed_mimes, 512000);

        if (!$result['success']) {
            $status = isset($result['status']) ? $result['status'] : 400;
            $this->output->set_status_header($status);
            echo json_encode(array(
                "message" => "Gagal upload foto: " . $result['error'],
                "status" => $status,
                "data" => new stdClass()
            ));
            return;
        }

        $foto_tkp_url = 'uploads/sitkamtibmas/' . $result['file_name'];

        // ── 6. AUTO-INJECT polda_id FROM JWT ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : null;
        if ($polda_id === null) {
            // Rollback: delete saved file
            @unlink($result['file_path']);
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "polda_id tidak ditemukan dalam token",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 7. GENERATE UUID ──
        $sitkamtibmas_id = generate_uuid4();

        // ── 8. INSERT INTO tbl_sitkamtibmas ──
        $sql = "INSERT INTO tbl_sitkamtibmas (sitkamtibmas_id, polda_id, deskripsi_kejadian, level_kritis, foto_tkp_url, created_at) "
             . "VALUES ("
             . "'" . $this->db->escape_str($sitkamtibmas_id) . "', "
             . "'" . $this->db->escape_str($polda_id) . "', "
             . "'" . $this->db->escape_str($deskripsi_kejadian) . "', "
             . "'" . $this->db->escape_str($level_kritis) . "', "
             . "'" . $this->db->escape_str($foto_tkp_url) . "', "
             . "NOW()"
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            // Rollback: delete saved file
            @unlink($result['file_path']);
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan laporan",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 9. WEBSOCKET TRIGGER: Darurat → Command Center Alert ──
        if ($level_kritis === 'Darurat') {
            // TODO: Fire WebSocket payload to Message Broker
            // to trigger Red Blinking Node on Command Center Map
        }

        // ── 10. SUCCESS RESPONSE: HTTP 201 Created ──
        $this->output->set_status_header(201);
        echo json_encode(array(
            "message" => "Laporan SITKAMTIBMAS berhasil dikirim",
            "status" => 201,
            "data" => array(
                "sitkamtibmas_id" => $sitkamtibmas_id
            )
        ));
    }
}
