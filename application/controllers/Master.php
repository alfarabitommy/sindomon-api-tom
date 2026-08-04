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

    public function polda_get()
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

        // Only active (not soft-deleted) Polda are shown to the frontend.
        $rows = $this->db->get_where('tbl_polda', ['is_active' => 1])->result_array();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Polda berhasil dimuat.',
            'data' => $rows
        ]);
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

        $nama_polres = trim($input['nama_polres'] ?? '');
        $polda_id = isset($input['polda_id']) ? (int) $input['polda_id'] : 0;

        if ($nama_polres === '' || $polda_id === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field nama_polres dan polda_id wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Polda must exist AND be active (not soft-deleted).
        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id, 'is_active' => 1])->num_rows();
        if ($polda_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Induk Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Unique name check: an ACTIVE Polres with the same name blocks creation.
        // (Soft-deleted Polres do not squat on their name — it is reusable.)
        $duplicate = $this->db->get_where('tbl_polres', ['nama_polres' => $nama_polres, 'is_active' => 1])->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Nama Polres sudah digunakan oleh Polres lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->insert('tbl_polres', [
            'polda_id' => $polda_id,
            'nama_polres' => $nama_polres,
            'is_active' => 1
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

        // Only active (not soft-deleted) Polres can be edited.
        $polres_exists = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id, 'is_active' => 1])->num_rows();
        if ($polres_exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Polres tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $input = json_decode($this->input->raw_input_stream, true);

        $nama_polres = trim($input['nama_polres'] ?? '');
        $polda_id = isset($input['polda_id']) ? (int) $input['polda_id'] : 0;

        if ($nama_polres === '' || $polda_id === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field nama_polres dan polda_id wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Polda must exist AND be active (not soft-deleted).
        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id, 'is_active' => 1])->num_rows();
        if ($polda_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Induk Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Unique name check (exclude self): an ACTIVE Polres must not share the name.
        // (Soft-deleted Polres do not squat on their name — it is reusable.)
        $duplicate = $this->db->where('nama_polres', $nama_polres)
            ->where('polres_id !=', $polres_id)
            ->where('is_active', 1)
            ->get('tbl_polres')->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Nama Polres sudah digunakan oleh Polres lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->where('polres_id', $polres_id)->update('tbl_polres', [
            'nama_polres' => $nama_polres,
            'polda_id' => $polda_id,
            'updated_at' => date('Y-m-d H:i:s')
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

        // Only active (not already soft-deleted) Polres can be deleted.
        $polres_exists = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id, 'is_active' => 1])->num_rows();
        if ($polres_exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Polres tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete pre-check: no ACTIVE personel (status_aktif = 'Aktif') may still be
        // assigned to this Polres. Pensiun/Mutasi personnel do not block deletion.
        // (The FK 1451 guard no longer fires because we do not physically delete.)
        $personel = $this->db->get_where('tbl_personil', ['polres_id' => $polres_id, 'status_aktif' => 'Aktif'])->num_rows();
        if ($personel > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Polres tidak dapat dihapus karena masih menaungi personel aktif (Restricted by System).',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete: deactivate instead of physically removing the row.
        $this->db->where('polres_id', $polres_id)->update('tbl_polres', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data polres berhasil dihapus.',
            'data' => (object)[]
        ]);
    }

    public function polres_get()
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

        $polda_id_filter = $this->input->get('polda_id');

        $this->db->select('r.polres_id, r.polda_id, r.nama_polres, p.nama_polda');
        $this->db->from('tbl_polres r');
        // LEFT JOIN so Polres still appear even if parent Polda was soft-deleted,
        // but the (deleted) Polda name must not leak into the response.
        $this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');
        $this->db->where('r.is_active', 1);

        if ($polda_id_filter !== null && $polda_id_filter !== '') {
            $this->db->where('r.polda_id', (int) $polda_id_filter);
        }

        $this->db->order_by('r.polres_id', 'ASC');
        $query = $this->db->get();
        $rows = $query->result_array();

        foreach ($rows as &$row) {
            $row['polres_id'] = (int) $row['polres_id'];
            $row['polda_id'] = (int) $row['polda_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Polres berhasil dimuat.',
            'data' => $rows
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

        // Only active (not soft-deleted) Polda and Polres are shown to the frontend.
        $poldas = $this->db->get_where('tbl_polda', ['is_active' => 1])->result_array();
        $rows = array();
        foreach ($poldas as $p) {
            $polres = $this->db->get_where('tbl_polres', ['polda_id' => $p['id'], 'is_active' => 1])->result_array();
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

    public function polda_post()
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

        if (empty($input['nama_polda'])) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field nama_polda wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        $nama_polda = trim($input['nama_polda']);

        // Unique name check: nama_polda must not already exist (API spec: harus UNIQUE).
        $duplicate = $this->db->get_where('tbl_polda', ['nama_polda' => $nama_polda])->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Nama Polda sudah digunakan oleh Polda lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $latitude = isset($input['latitude']) ? trim($input['latitude']) : null;
        $longitude = isset($input['longitude']) ? trim($input['longitude']) : null;

        $this->db->insert('tbl_polda', [
            'nama_polda' => $nama_polda,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_active' => 1
        ]);

        $inserted_id = $this->db->insert_id();

        http_response_code(201);
        echo json_encode([
            'status' => 201,
            'message' => 'Data Polda berhasil ditambahkan.',
            'data' => [
                'polda_id' => (int) $inserted_id
            ]
        ]);
    }

    public function polda_put($polda_id)
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

        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
        if ($polda_exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $input = json_decode($this->input->raw_input_stream, true);

        $nama_polda = trim($input['nama_polda'] ?? '');

        if ($nama_polda === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field nama_polda wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Unique name check (exclude self): nama_polda must not belong to another Polda.
        $duplicate = $this->db->where('nama_polda', $nama_polda)
            ->where('id !=', $polda_id)
            ->get('tbl_polda')->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Nama Polda sudah digunakan oleh Polda lain.',
                'data' => (object)[]
            ]);
            return;
        }

        // Partial update: only touch latitude/longitude when explicitly provided,
        // otherwise retain the existing stored values (prevents accidental erasure).
        $update = [
            'nama_polda' => $nama_polda,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if (array_key_exists('latitude', $input)) {
            $update['latitude'] = trim($input['latitude']);
        }
        if (array_key_exists('longitude', $input)) {
            $update['longitude'] = trim($input['longitude']);
        }

        $this->db->where('id', $polda_id)->update('tbl_polda', $update);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data Polda berhasil diperbarui.',
            'data' => (object)[]
        ]);
    }

    public function polda_delete($polda_id)
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

        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
        if ($polda_exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Polda tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete pre-check: this Polda must not have any active child Polres.
        // (The FK 1451 guard no longer fires because we do not physically delete.)
        $active_polres = $this->db->get_where('tbl_polres', ['polda_id' => $polda_id, 'is_active' => 1])->num_rows();
        if ($active_polres > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Polda tidak dapat dihapus karena masih menaungi Polres aktif (Restricted by System).',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete: deactivate instead of physically removing the row.
        $this->db->where('id', $polda_id)->update('tbl_polda', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data Polda berhasil dihapus.',
            'data' => (object)[]
        ]);
    }

    public function kategori_senjata_get()
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

        // Only active (not soft-deleted) Kategori Senjata are shown to the frontend.
        $this->db->order_by('kategori_id', 'ASC');
        $rows = $this->db->get_where('tbl_kategori_senjata', ['is_active' => 1])->result_array();

        foreach ($rows as &$row) {
            $row['kategori_id'] = (int) $row['kategori_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Kategori Senjata berhasil dimuat.',
            'data' => $rows
        ]);
    }

    public function kategori_senjata_post()
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

        $tipe_laras = trim($input['tipe_laras'] ?? '');
        $kaliber    = trim($input['kaliber'] ?? '');

        if ($tipe_laras === '' || $kaliber === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field tipe_laras dan kaliber wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Strict ENUM validation: tipe_laras must be 'Panjang' or 'Pendek' (exactly).
        $allowed_tipe = array('Panjang', 'Pendek');
        if (!in_array($tipe_laras, $allowed_tipe, true)) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. tipe_laras harus salah satu dari: Panjang, Pendek.',
                'data' => (object)[]
            ]);
            return;
        }

        // Unique combination check: same tipe_laras AND kaliber among ACTIVE rows.
        // (Soft-deleted rows do not squat on the combination — it is reusable.)
        $duplicate = $this->db->get_where('tbl_kategori_senjata', [
            'tipe_laras' => $tipe_laras,
            'kaliber'    => $kaliber,
            'is_active'  => 1
        ])->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Kombinasi tipe_laras dan kaliber sudah digunakan oleh Kategori Senjata lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->insert('tbl_kategori_senjata', [
            'tipe_laras' => $tipe_laras,
            'kaliber'    => $kaliber,
            'is_active'  => 1
        ]);

        $inserted_id = $this->db->insert_id();

        http_response_code(201);
        echo json_encode([
            'status' => 201,
            'message' => 'Data kategori senjata berhasil ditambahkan.',
            'data' => [
                'kategori_id' => (int) $inserted_id
            ]
        ]);
    }

    public function kategori_senjata_put($kategori_id)
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

        // Only active (not soft-deleted) Kategori Senjata can be edited.
        $exists = $this->db->get_where('tbl_kategori_senjata', [
            'kategori_id' => $kategori_id,
            'is_active'   => 1
        ])->num_rows();
        if ($exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Kategori Senjata tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        $input = json_decode($this->input->raw_input_stream, true);

        $tipe_laras = trim($input['tipe_laras'] ?? '');
        $kaliber    = trim($input['kaliber'] ?? '');

        if ($tipe_laras === '' || $kaliber === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. Field tipe_laras dan kaliber wajib diisi.',
                'data' => (object)[]
            ]);
            return;
        }

        // Strict ENUM validation: tipe_laras must be 'Panjang' or 'Pendek' (exactly).
        $allowed_tipe = array('Panjang', 'Pendek');
        if (!in_array($tipe_laras, $allowed_tipe, true)) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Validasi gagal. tipe_laras harus salah satu dari: Panjang, Pendek.',
                'data' => (object)[]
            ]);
            return;
        }

        // Unique combination check (exclude self): an ACTIVE Kategori must not share
        // the same tipe_laras + kaliber. (Soft-deleted rows do not squat.)
        $duplicate = $this->db->where('tipe_laras', $tipe_laras)
            ->where('kaliber', $kaliber)
            ->where('kategori_id !=', $kategori_id)
            ->where('is_active', 1)
            ->get('tbl_kategori_senjata')->num_rows();
        if ($duplicate > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Validasi gagal. Kombinasi tipe_laras dan kaliber sudah digunakan oleh Kategori Senjata lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $this->db->where('kategori_id', $kategori_id)->update('tbl_kategori_senjata', [
            'tipe_laras' => $tipe_laras,
            'kaliber'    => $kaliber,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data kategori senjata berhasil diperbarui.',
            'data' => (object)[]
        ]);
    }

    public function kategori_senjata_delete($kategori_id)
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

        // Only active (not already soft-deleted) Kategori Senjata can be deleted.
        $exists = $this->db->get_where('tbl_kategori_senjata', [
            'kategori_id' => $kategori_id,
            'is_active'   => 1
        ])->num_rows();
        if ($exists === 0) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'Kategori Senjata tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete pre-check: this Kategori must not be referenced by any Senjata
        // or Amunisi Batch (no FK constraints exist, so the guard is a manual count —
        // the FK 1451 guard no longer fires because we do not physically delete).
        $senjata_count = $this->db->get_where('tbl_senjata', [
            'kategori_id' => $kategori_id
        ])->num_rows();
        $amunisi_count = $this->db->get_where('tbl_amunisi_batch', [
            'kategori_id' => $kategori_id
        ])->num_rows();
        if ($senjata_count > 0 || $amunisi_count > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Kategori tidak dapat dihapus karena masih digunakan oleh data Senjata atau Amunisi (Restricted by System).',
                'data' => (object)[]
            ]);
            return;
        }

        // Soft delete: deactivate instead of physically removing the row.
        $this->db->where('kategori_id', $kategori_id)->update('tbl_kategori_senjata', [
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Data kategori senjata berhasil dihapus.',
            'data' => (object)[]
        ]);
    }

    public function pangkat_get()
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

        $this->db->order_by('pangkat_id', 'ASC');
        $rows = $this->db->select('pangkat_id, nama_pangkat')->get('tbl_pangkat')->result_array();

        foreach ($rows as &$row) {
            $row['pangkat_id'] = (int) $row['pangkat_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Pangkat berhasil dimuat.',
            'data' => $rows
        ]);
    }

    public function jabatan_get()
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

        $this->db->order_by('jabatan_id', 'ASC');
        $rows = $this->db->select('jabatan_id, nama_jabatan')->get('tbl_jabatan')->result_array();

        foreach ($rows as &$row) {
            $row['jabatan_id'] = (int) $row['jabatan_id'];
        }
        unset($row);

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Daftar Jabatan berhasil dimuat.',
            'data' => $rows
        ]);
    }
}
