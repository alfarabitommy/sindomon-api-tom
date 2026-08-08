<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Profile extends CI_Controller {

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

        $this->config->load('jwt');
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->library('jwt');
        $this->load->helper('uuid');
        $this->load->helper('string');
        $this->load->helper('jwt');
    }

    public function get()
    {
        // ── 1. JWT authentication (replaces legacy raw-token DB lookup) ──
        $payload = get_jwt_payload($this);
        if ($payload === null || !isset($payload['uid'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        $user_id = (int) $payload['uid'];

        // ── 2. Safe is_2fa_enabled handling ──
        // Column does not exist yet in the schema; fall back to 0 (false)
        // so the query never fails on environments without the migration.
        $has_2fa = $this->db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tbl_users'
               AND COLUMN_NAME = 'is_2fa_enabled'"
        )->num_rows() > 0;
        $select_2fa = $has_2fa ? 'u.is_2fa_enabled' : '0 AS is_2fa_enabled';

        // ── 3. Explicit column SELECT with JOINs — NEVER SELECT * ──
        // Explicit columns prevent leaking the password hash, token, uuid, etc.
        $this->db->select('u.id, u.username, r.roles AS role_name, p.nama_polda, ' . $select_2fa);
        $this->db->from('tbl_users u');
        $this->db->join('tbl_role r', 'u.roles_id = r.id', 'left');
        $this->db->join('tbl_polda p', 'u.polda_id = p.id', 'left');
        $this->db->where('u.id', $user_id);
        $user = $this->db->get()->row_array();

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'User tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 4. Type-cast for Flutter JSON compatibility ──
        $profile = [
            'id'              => (int) $user['id'],
            'username'        => $user['username'],
            'role_name'       => $user['role_name'] !== null ? (string) $user['role_name'] : '',
            'nama_polda'      => $user['nama_polda'] !== null ? (string) $user['nama_polda'] : '',
            'is_2fa_enabled'  => (bool) $user['is_2fa_enabled']
        ];

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'success',
            'data' => $profile
        ]);
    }
}
