<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Knowledge extends CI_Controller {

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
     * GET /api/v1/knowledge/dokumen
     * Tarik daftar dokumen hukum (Perpustakaan Digital)
     * Query params (all optional): kategori, search
     * Global access — all users see same documents (no polda_id filter)
     */
    public function dokumen()
    {
        // ── 1. AUTH: Smart JWT extraction (Bearer or raw token) ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array("message" => "Token tidak valid", "status" => 401, "data" => array()));
            return;
        }

        // ── 2. ROLE CHECK: All roles allowed — no restriction ──

        // ── 3. QUERY PARAMS (all optional) ──
        $kategori = $this->input->get('kategori');
        $search   = $this->input->get('search');

        // ── 4. BUILD WHERE CONDITIONS ──
        $where = array();

        // Kategori filter: strict ENUM match
        if ($kategori !== null && $kategori !== '') {
            $valid_kategori = array('Perkap', 'Perpol', 'SOP', 'Juknis');
            if (in_array($kategori, $valid_kategori)) {
                $where[] = "kategori = '" . $this->db->escape_str($kategori) . "'";
            }
        }

        // Search filter: LIKE on judul_dokumen
        if ($search !== null && $search !== '') {
            $where[] = "judul_dokumen LIKE '%" . $this->db->escape_str($search) . "%'";
        }

        // ── 5. EXECUTE QUERY ──
        // GLOBAL DATA ACCESS — NO polda_id filter
        $where_clause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        $sql = $this->db->query(
            "SELECT dokumen_id, kategori, judul_dokumen, file_url, created_at FROM tbl_dokumen_hukum " . $where_clause . " ORDER BY created_at DESC"
        );
        $results = $sql->result_array();

        // Cast dokumen_id to integer for Flutter/Dart JSON parsing
        foreach ($results as &$row) {
            $row['dokumen_id'] = (int) $row['dokumen_id'];
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
}
