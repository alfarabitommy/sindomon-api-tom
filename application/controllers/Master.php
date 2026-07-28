<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Master extends CI_Controller {

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
        $this->load->helper('jwt');
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->library('jwt');
    }

    public function polres_post()
    {
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

        $input = json_decode($this->input->raw_input_stream, true);

        if (empty($input['nama_polres']) || empty($input['polda_id'])) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field nama_polres dan polda_id wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        $nama_polres = trim($input['nama_polres']);
        $polda_id = (int) $input['polda_id'];

        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();

        if ($polda_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Induk Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->insert('tbl_polres', [
            'polda_id' => $polda_id,
            'nama_polres' => $nama_polres
        ]);

        $inserted_id = $this->db->insert_id();

        http_response_code(201);
        echo json_encode([
            'status' => 201,
            'message' => 'Data wilayah polres berhasil ditambahkan.',
            'data' => [
                'polres_id' => (int) $inserted_id
            ]
        ]);
    }

    public function polres_put($polres_id)
    {
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

        $input = json_decode($this->input->raw_input_stream, true);

        $nama_polres = trim($input['nama_polres'] ?? '');
        $polda_id = (int) ($input['polda_id'] ?? 0);

        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();

        if ($polda_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Induk Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->where('polres_id', $polres_id)->update('tbl_polres', [
            'nama_polres' => $nama_polres,
            'polda_id' => $polda_id
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data polres berhasil diperbarui.',
            'data' => (object)[]
        ]);
    }

    public function polres_delete($polres_id)
    {
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

        $this->db->db_debug = FALSE;

        $this->db->delete('tbl_polres', ['polres_id' => $polres_id]);

        $error = $this->db->error();

        $this->db->db_debug = TRUE;

        if ($error['code'] == 1451) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Polres tidak dapat dihapus karena masih menaungi personel aktif (Restricted by System).',
                'data' => (object)[]
            ]);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data polres berhasil dihapus.',
            'data' => (object)[]
        ]);
    }

    public function wilayah_get()
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

        $poldas = $this->db->get('tbl_polda')->result_array();
        $rows = array();
        foreach ($poldas as $p) {
            $polres = $this->db->get_where('tbl_polres', ['polda_id' => $p['id']])->result_array();
            $rows[] = array(
                'id'             => (int) $p['id'],
                'nama_polda'     => $p['nama_polda'],
                'latitude'       => $p['latitude'],
                'longitude'      => $p['longitude'],
                'created_at'     => $p['created_at'],
                'polres_jajaran' => $polres,
            );
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar wilayah berhasil dimuat.',
            'data' => $rows
        ]);
    }
}
