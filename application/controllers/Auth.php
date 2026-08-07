<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

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

    public function login()
    {
        $data = json_decode($this->input->raw_input_stream, true);

        if (empty($data) || empty($data['username']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 400,
                'message' => 'Username dan password wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        $sql = $this->db->query(
            "SELECT * FROM tbl_users WHERE username = '".$data['username']."' AND is_active = 1"
        );

        if ($sql->num_rows() > 0) {
            $check = $sql->result_array();
            $match = password_verify($data['password'], $check[0]['password']);

            if ($match) {
                $payload = [
                    'uid' => (int) $check[0]['id'],
                    'username' => $check[0]['username'],
                    'role_id' => (int) $check[0]['roles_id'],
                    'polda_id' => isset($check[0]['polda_id']) ? (int) $check[0]['polda_id'] : 0,
                    'iat' => time(),
                    'exp' => time() + 3600
                ];
                $token = jwt_encode($payload);

                // Remove password hash from response
                unset($check[0]['password']);

                http_response_code(200);
                echo json_encode([
                    'status' => 200,
                    'message' => 'Login berhasil.',
                    'data' => [
                        'jwt_token' => $token,
                        'user' => $check[0]
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    'status' => 401,
                    'message' => 'Username atau password salah.',
                    'data' => (object)[]
                ]);
            }
        } else {
            // Check if user exists but is inactive
            $inactive = $this->db->query(
                "SELECT id FROM tbl_users WHERE username = '".$data['username']."' AND is_active = 0"
            );

            if ($inactive->num_rows() > 0) {
                http_response_code(403);
                echo json_encode([
                    'status' => 403,
                    'message' => 'Akun telah dinonaktifkan. Hubungi administrator.',
                    'data' => (object)[]
                ]);
                return;
            }

            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Username atau password salah.',
                'data' => (object)[]
            ]);
        }
    }

    public function insert_user()
    {
        // ── Super Admin gate ──
        $payload = get_jwt_payload($this);
        if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 403,
                'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
                'data' => (object)[]
            ]);
            return;
        }

        $h_uuid = generate_uuid4();
        $r_string = randomString();
        $data = json_decode($this->input->raw_input_stream, true);

        if (empty($data) || empty($data['username']) || empty($data['password']) || empty($data['roles_id'])) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field username, password, dan roles_id wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Check duplicate username
        $dup = $this->db->query(
            "SELECT id FROM tbl_users WHERE username = '".$data['username']."'"
        )->num_rows();
        if ($dup > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Username sudah digunakan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Validate roles_id exists
        $role_exists = $this->db->get_where('tbl_role', ['id' => $data['roles_id']])->num_rows();
        if ($role_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Role tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $polda_id = isset($data['polda_id']) ? (int) $data['polda_id'] : null;
        $polda_val = $polda_id !== null ? "'".$polda_id."'" : "NULL";

        $rows = $this->db->query(
            "INSERT INTO tbl_users(username, password, roles_id, polda_id, uuid, token, expired, is_active, created_at)
             VALUES (
                 '".$data['username']."',
                 '".password_hash($data['password'], PASSWORD_DEFAULT)."',
                 '".$data['roles_id']."',
                 ".$polda_val.",
                 '".$h_uuid."',
                 '".$r_string."',
                 '30',
                 1,
                 '".date('Y-m-d H:i:s')."'
             )"
        );

        if ($rows) {
            http_response_code(201);
            echo json_encode([
                'status' => 201,
                'message' => 'User berhasil dibuat.',
                'data' => $data
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 500,
                'message' => 'Gagal membuat user.',
                'data' => (object)[]
            ]);
        }
    }

    public function all()
    {
        $payload = get_jwt_payload($this);
        if ($payload === null) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        // --- Pagination & real-time search query parameters ---
        $search = trim((string) $this->input->get('search'));
        $page   = max(1, (int) ($this->input->get('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

        // --- Build query BEFORE counting (JOINs + filters) ---
        $this->db->from('tbl_users u');
        $this->db->join('tbl_role r', 'u.roles_id = r.id', 'left');
        $this->db->join('tbl_polda p', 'u.polda_id = p.id', 'left');
        $this->db->where('u.is_active', 1);

        // Optional real-time search: partial (LIKE) match on username.
        if ($search !== '') {
            $this->db->like('u.username', $search);
        }

        // Total rows matching the current filter. The FALSE second argument
        // preserves the Query Builder state (FROM/JOIN/WHERE/LIKE) for get()
        // below. Empty string is passed because from() is already set.
        $total_data = $this->db->count_all_results('', false);

        // --- Apply SELECT, LIMIT, ORDER AFTER counting ---
        // REMOVED uuid, token, expired — critical security fix (token leak).
        $this->db->select('u.id, u.username, u.roles_id, u.polda_id, r.roles as role_name, p.nama_polda, u.is_active, u.created_at, u.updated_at');
        $this->db->order_by('u.id', 'ASC');
        $this->db->limit($limit, ($page - 1) * $limit);

        $data = $this->db->get()->result_array();

        // Cast integer columns for Flutter compatibility
        foreach ($data as &$row) {
            $row['id'] = (int) $row['id'];
            $row['roles_id'] = (int) $row['roles_id'];
            $row['polda_id'] = isset($row['polda_id']) ? (int) $row['polda_id'] : null;
            $row['is_active'] = (int) $row['is_active'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar user berhasil dimuat.',
            'data' => [
                'items' => $data,
                'pagination' => [
                    'total_data'   => (int) $total_data,
                    'total_pages'  => (int) ceil($total_data / $limit),
                    'current_page' => $page,
                    'per_page'     => $limit,
                ]
            ]
        ]);
    }

    public function user_put($id)
    {
        $payload = get_jwt_payload($this);

        // ── 1. Super Admin gate ──
        if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 403,
                'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 2. User existence check ──
        $user = $this->db->get_where('tbl_users', ['id' => $id, 'is_active' => 1])->row_array();
        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'User tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 3. Parse JSON input ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (empty($input)) {
            http_response_code(400);
            echo json_encode([
                'status' => 400,
                'message' => 'Request body harus berupa JSON.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 4. Build update data (partial update — only update provided fields) ──
        $update_data = [];
        $update_data['updated_at'] = date('Y-m-d H:i:s');

        // username
        if (isset($input['username']) && trim($input['username']) !== '') {
            $new_username = trim($input['username']);

            // Uniqueness check (exclude self)
            $dup = $this->db->query(
                "SELECT id FROM tbl_users WHERE username = '".$new_username."' AND id != ".(int)$id
            )->num_rows();
            if ($dup > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 409,
                    'message' => 'Username sudah digunakan oleh user lain.',
                    'data' => (object)[]
                ]);
                return;
            }

            $update_data['username'] = $new_username;
        }

        // password (only update if non-empty)
        if (isset($input['password']) && trim($input['password']) !== '') {
            $update_data['password'] = password_hash(trim($input['password']), PASSWORD_DEFAULT);
        }

        // roles_id
        if (isset($input['roles_id'])) {
            $roles_id = (int) $input['roles_id'];

            // Validate role exists
            $role_exists = $this->db->get_where('tbl_role', ['id' => $roles_id])->num_rows();
            if ($role_exists === 0) {
                http_response_code(422);
                echo json_encode([
                    'status' => 422,
                    'message' => 'Role tidak ditemukan. Gunakan roles_id 1 (Super Admin), 2 (Operator Polda), atau 3 (Eksekutif).',
                    'data' => (object)[]
                ]);
                return;
            }

            $update_data['roles_id'] = $roles_id;
        }

        // polda_id
        if (array_key_exists('polda_id', $input)) {
            $polda_id_val = $input['polda_id'] !== null && $input['polda_id'] !== '' ? (int) $input['polda_id'] : null;

            if ($polda_id_val !== null) {
                $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id_val])->num_rows();
                if ($polda_exists === 0) {
                    http_response_code(422);
                    echo json_encode([
                        'status' => 422,
                        'message' => 'Polda tidak ditemukan.',
                        'data' => (object)[]
                    ]);
                    return;
                }
            }

            $update_data['polda_id'] = $polda_id_val;
        }

        // ── 5. Reject if nothing to update ──
        if (count($update_data) <= 1) { // only updated_at was set
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Tidak ada field yang valid untuk diperbarui.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 6. Execute update ──
        $this->db->where('id', $id)->update('tbl_users', $update_data);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data user berhasil diperbarui.',
            'data' => (object)[]
        ]);
    }

    public function user_delete($id)
    {
        $payload = get_jwt_payload($this);

        // ── 1. Super Admin gate ──
        if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 403,
                'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 2. User existence check (only active users) ──
        $user = $this->db->get_where('tbl_users', ['id' => $id, 'is_active' => 1])->row_array();
        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'User tidak ditemukan atau sudah dinonaktifkan.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 3. Prevent self-deletion ──
        $requester_uid = (int) $payload['uid'];
        if ((int) $id === $requester_uid) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Anda tidak dapat menonaktifkan akun sendiri.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 4. Soft delete: set is_active = 0 ──
        $this->db->where('id', $id)->update('tbl_users', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'User berhasil dinonaktifkan.',
            'data' => (object)[]
        ]);
    }
}
