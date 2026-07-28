<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dms extends CI_Controller {

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
        $this->load->helper('base64_file');
        $this->load->library('jwt');
    }

    /**
     * POST /api/v1/dms/surat
     * Kirim Surat Dinas Baru [JSON + Base64]
     *
     * Authorization: Super Admin (role_id=1) or Operator Polda (role_id=2)
     * Content-Type: application/json
     *
     * JSON fields:
     *   - nomor_surat        (string, required)
     *   - judul_surat         (string, required)
     *   - penerima_polda_id   (integer, optional — null/0 = Mabes Polri)
     *   - file_dokumen        (string, required — base64-encoded pdf/docx)
     */
    public function surat()
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

        // ── 2. ROLE CHECK: Super Admin (1) or Operator Polda (2) ──
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 1 && $role_id != 2) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak. Hanya Super Admin atau Operator Polda yang dapat mengirim surat",
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
        $nomor_surat = isset($input['nomor_surat']) ? $input['nomor_surat'] : null;
        $judul_surat = isset($input['judul_surat']) ? $input['judul_surat'] : null;

        if ($nomor_surat === null || $nomor_surat === '') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "nomor_surat wajib diisi",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        if ($judul_surat === null || $judul_surat === '') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "judul_surat wajib diisi",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. HANDLE penerima_polda_id (Optional — null/0 = Mabes Polri) ──
        $raw_penerima = isset($input['penerima_polda_id']) ? $input['penerima_polda_id'] : null;
        $penerima_polda_id = null;

        if ($raw_penerima !== null && $raw_penerima !== '' && (int) $raw_penerima > 0) {
            $penerima_polda_id = (int) $raw_penerima;
        }

        // ── 6. BASE64 FILE: file_dokumen (pdf/docx only) ──
        $base64_file = isset($input['file_dokumen']) ? $input['file_dokumen'] : null;

        if ($base64_file === null || $base64_file === '') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "file_dokumen wajib diisi",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $upload_dir = dirname(FCPATH) . '/uploads/dms/';
        $allowed_mimes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $result = save_base64_file($base64_file, $upload_dir, $allowed_mimes, 2097152);

        if (!$result['success']) {
            $status = isset($result['status']) ? $result['status'] : 400;
            $this->output->set_status_header($status);
            echo json_encode(array(
                "message" => $result['error'],
                "status" => $status,
                "data" => new stdClass()
            ));
            return;
        }

        $file_pdf_url = 'uploads/dms/' . $result['file_name'];

        // ── 7. AUTO-INJECT pengirim_polda_id FROM JWT ──
        $pengirim_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : null;

        // ── 8. GENERATE UUID ──
        $surat_id = generate_uuid4();

        // ── 9. INSERT INTO tbl_dms_surat ──
        $sql = "INSERT INTO tbl_dms_surat (surat_id, pengirim_polda_id, penerima_polda_id, judul_surat, nomor_surat, file_pdf_url, status_tracking, created_at) "
             . "VALUES ("
             . "'" . $this->db->escape_str($surat_id) . "', "
             . ($pengirim_polda_id !== null ? "'" . $this->db->escape_str($pengirim_polda_id) . "'" : "NULL") . ", "
             . ($penerima_polda_id !== null ? "'" . $this->db->escape_str($penerima_polda_id) . "'" : "NULL") . ", "
             . "'" . $this->db->escape_str($judul_surat) . "', "
             . "'" . $this->db->escape_str($nomor_surat) . "', "
             . "'" . $this->db->escape_str($file_pdf_url) . "', "
             . "'Terkirim', "
             . "NOW()"
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            // Rollback: delete saved file
            @unlink($result['file_path']);
            $this->output->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan surat dinas",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 10. SUCCESS RESPONSE: HTTP 201 Created ──
        $this->output->set_status_header(201);
        echo json_encode(array(
            "message" => "Surat dinas berhasil dikirim",
            "status" => 201,
            "data" => array(
                "surat_id" => $surat_id
            )
        ));
    }

    /**
     * GET /api/v1/dms/surat?tipe=inbox|outbox
     * Tarik Kotak Masuk (Inbox) & Kotak Keluar (Outbox)
     *
     * Authorization: Operator Polda (role_id=2) or Eksekutif (role_id=3)
     * Query params:
     *   - tipe  (string, required) — "inbox" or "outbox"
     */
    public function inbox_outbox()
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

        // ── 2. ROLE CHECK: Operator Polda (2) or Eksekutif (3) ──
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        if ($role_id != 2 && $role_id != 3) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak. Hanya Eksekutif atau Operator Polda yang dapat mengakses surat",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. VALIDATE ?tipe= PARAM ──
        $tipe = $this->input->get('tipe');
        if ($tipe !== 'inbox' && $tipe !== 'outbox') {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Parameter tipe harus 'inbox' atau 'outbox'",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. EXTRACT polda_id FROM JWT (Mabes = null/0) ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $is_mabes = ($polda_id === 0);

        // ── 5. BUILD QUERY ──
        if ($tipe === 'inbox') {
            $select_cols = "s.surat_id, s.nomor_surat, s.judul_surat, "
                         . "COALESCE(p.nama_polda, 'Mabes Polri') AS pengirim, "
                         . "s.status_tracking, s.created_at";
            $join_table = "tbl_polda p ON s.pengirim_polda_id = p.id";

            if ($is_mabes) {
                $where = "s.penerima_polda_id IS NULL OR s.penerima_polda_id = 0";
            } else {
                $where = "s.penerima_polda_id = '" . $this->db->escape_str($polda_id) . "'";
            }
        } else {
            $select_cols = "s.surat_id, s.nomor_surat, s.judul_surat, "
                         . "COALESCE(p.nama_polda, 'Mabes Polri') AS penerima, "
                         . "s.status_tracking, s.created_at";
            $join_table = "tbl_polda p ON s.penerima_polda_id = p.id";

            if ($is_mabes) {
                $where = "s.pengirim_polda_id IS NULL OR s.pengirim_polda_id = 0";
            } else {
                $where = "s.pengirim_polda_id = '" . $this->db->escape_str($polda_id) . "'";
            }
        }

        $sql = "SELECT {$select_cols} "
             . "FROM tbl_dms_surat s "
             . "LEFT JOIN {$join_table} "
             . "WHERE ({$where}) "
             . "ORDER BY s.created_at DESC";

        $query = $this->db->query($sql);
        $results = $query->result_array();

        // ── 6. SUCCESS RESPONSE: HTTP 200 ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Data surat berhasil diambil",
            "status" => 200,
            "data" => $results
        ));
    }

    /**
     * GET /api/v1/dms/surat/{surat_id}/download
     * Tarik File Dokumen Terproteksi (Secure Fetch)
     *
     * Authorization: Valid JWT + Dual-Jurisdiction (polda_id == pengirim OR penerima)
     * Response: Binary stream (pdf/docx) — NOT JSON
     */
    public function download($surat_id)
    {
        // ── 1. AUTH: Smart JWT extraction ──
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

        // ── 2. EXTRACT polda_id FROM JWT (0 = Mabes) ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. FETCH DOCUMENT RECORD ──
        $sql = "SELECT * FROM tbl_dms_surat WHERE surat_id = '"
            . $this->db->escape_str($surat_id) . "'";
        $query = $this->db->query($sql);
        $doc = $query->row_array();

        if (!$doc) {
            $this->output->set_status_header(404);
            echo json_encode(array(
                "message" => "Surat tidak ditemukan",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. DUAL-JURISDICTION AUTHORIZATION ──
        $pengirim = ($doc['pengirim_polda_id'] !== null)
            ? (int) $doc['pengirim_polda_id'] : 0;
        $penerima = ($doc['penerima_polda_id'] !== null)
            ? (int) $doc['penerima_polda_id'] : 0;

        $is_authorized = ($polda_id === $pengirim) || ($polda_id === $penerima);

        if (!$is_authorized) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak. Anda tidak memiliki izin mengunduh surat ini",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. VERIFY PHYSICAL FILE ──
        $file_path = FCPATH . $doc['file_pdf_url'];

        if (!file_exists($file_path)) {
            $this->output->set_status_header(404);
            echo json_encode(array(
                "message" => "File tidak ditemukan di server",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 6. DETECT MIME & FILENAME ──
        $mime = mime_content_type($file_path);
        if (!$mime || strpos($mime, '/') === false) {
            $mime = 'application/octet-stream';
        }
        $filename = basename($doc['file_pdf_url']);

        // ── 7. CLEAN BUFFER + STREAM BINARY (Flutter-compatible) ──
        ob_clean();
        flush();

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file_path));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        readfile($file_path);
        exit;
    }

    /**
     * PATCH /api/v1/dms/surat/{surat_id}/read
     * Tandai Surat Dibaca (Read Receipt)
     *
     * Authorization: Penerima surat only (penerima_polda_id == JWT polda_id)
     */
    public function read($surat_id)
    {
        // ── 1. AUTH: Smart JWT extraction ──
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

        // ── 2. EXTRACT polda_id FROM JWT (0 = Mabes) ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. UPDATE status_tracking → 'Dibaca' (penerima-only gate) ──
        // Handle Mabes edge-case: penerima_polda_id IS NULL in DB → JWT polda_id=0
        $sql = "UPDATE tbl_dms_surat
                SET status_tracking = 'Dibaca'
                WHERE surat_id = '" . $this->db->escape_str($surat_id) . "'
                  AND (
                      penerima_polda_id = '" . $this->db->escape_str($polda_id) . "'
                      OR (penerima_polda_id IS NULL AND " . $polda_id . " = 0)
                  )";

        $this->db->query($sql);

        if ($this->db->affected_rows() === 0) {
            $this->output->set_status_header(403);
            echo json_encode(array(
                "message" => "Akses ditolak atau surat tidak ditemukan",
                "status" => 403,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. SUCCESS ──
        $this->output->set_status_header(200);
        echo json_encode(array(
            "message" => "Status pelacakan berhasil diubah menjadi Dibaca",
            "status" => 200,
            "data" => new stdClass()
        ));
    }
}
