<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Logistik extends CI_Controller {

    public function __construct() {
        parent::__construct();
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
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
        $this->load->helper('base64_file');
    }

    /**
     * POST /api/v1/logistik/senjata
     *
     * Registrasi senjata api baru.
     * Payload (JSON): nomor_seri, kategori_id, tahun_pengadaan, status_kelayakan, foto_fisik
     * Auth: JWT (auto-inject polda_id)
     */
    public function senjata_post()
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. CONTENT-TYPE CHECK: JSON only ──
        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($content_type, 'application/json') === false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array(
                "message" => "Content-Type harus application/json",
                "status" => 415,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. PARSE JSON PAYLOAD ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $nomor_seri       = isset($input['nomor_seri']) ? trim($input['nomor_seri']) : '';
        $kategori_id      = isset($input['kategori_id']) ? (int) $input['kategori_id'] : 0;
        $tahun_pengadaan  = isset($input['tahun_pengadaan']) ? trim($input['tahun_pengadaan']) : '';
        $status_kelayakan = isset($input['status_kelayakan']) ? trim($input['status_kelayakan']) : '';
        $foto_fisik       = isset($input['foto_fisik']) ? $input['foto_fisik'] : '';

        // ── 4. MANDATORY PHOTO RULE ──
        if ($foto_fisik === null || $foto_fisik === '') {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Validasi gagal. Foto bukti fisik senjata wajib dilampirkan.",
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. UNIQUE SERIAL RULE ──
        $check = $this->db->query(
            "SELECT senjata_id FROM tbl_senjata WHERE nomor_seri = " . $this->db->escape($nomor_seri)
        );
        if ($check->num_rows() > 0) {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array(
                "status" => 422,
                "message" => "Nomor Seri ini sudah terdaftar di pangkalan data.",
                "data" => new stdClass()
            ));
            return;
        }

        // ── 6. BASE64 FILE: foto_fisik (image only) ──
        $upload_dir = FCPATH . 'uploads/senjata/';
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg'];
        $result = save_base64_file($foto_fisik, $upload_dir, $allowed_mimes, 512000);

        if (!$result['success']) {
            $status = isset($result['status']) ? $result['status'] : 400;
            $this->output->set_content_type('application/json')->set_status_header($status);
            echo json_encode(array(
                "message" => $result['error'],
                "status" => $status,
                "data" => new stdClass()
            ));
            return;
        }

        $foto_url = 'uploads/senjata/' . $result['file_name'];

        // ── 7. AUTO-INJECT polda_id FROM JWT ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 8. GENERATE UUID ──
        $senjata_id = generate_uuid4();

        // ── 9. INSERT INTO tbl_senjata ──
        $sql = "INSERT INTO tbl_senjata (senjata_id, nomor_seri, kategori_id, polda_id, tahun_pengadaan, status_kelayakan, foto_url, created_at) "
             . "VALUES ("
             . "'" . $this->db->escape_str($senjata_id) . "', "
             . "'" . $this->db->escape_str($nomor_seri) . "', "
             . "'" . $this->db->escape_str($kategori_id) . "', "
             . "'" . $this->db->escape_str($polda_id) . "', "
             . "'" . $this->db->escape_str($tahun_pengadaan) . "', "
             . "'" . $this->db->escape_str($status_kelayakan) . "', "
             . "'" . $this->db->escape_str($foto_url) . "', "
             . "NOW()"
             . ")";

        $insert = $this->db->query($sql);

        if (!$insert) {
            // Rollback: delete saved file
            @unlink($result['file_path']);
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan data senjata",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 10. SUCCESS: HTTP 201 Created ──
        $this->output->set_content_type('application/json')->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Data senjata berhasil diregistrasi.",
            "data" => array(
                "senjata_id" => $senjata_id
            )
        ));
    }

    /**
     * GET /api/v1/logistik/senjata
     *
     * Inventarisasi senjata api — joined with kategori for readable labels.
     * Auth: JWT (polda_id for jurisdiction), ?search= for nomor_seri filter.
     */
    public function senjata_get()
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. JURISDICTION ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. BUILD QUERY ──
        $this->db->select('s.*, k.tipe_laras, k.kaliber');
        $this->db->from('tbl_senjata s');
        // LEFT JOIN so senjata still appear even if kategori was soft-deleted,
        // but the deleted kategori labels must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 's.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }

        // Search filter — nomor_seri
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->like('s.nomor_seri', $search);
        }

        $this->db->order_by('s.created_at', 'DESC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 4. INTEGER CASTING & MAP ──
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[] = array(
                'senjata_id'       => $row['senjata_id'],
                'nomor_seri'       => $row['nomor_seri'],
                'kategori_id'      => (int) $row['kategori_id'],
                'polda_id'         => (int) $row['polda_id'],
                'tahun_pengadaan'  => $row['tahun_pengadaan'],
                'status_kelayakan' => $row['status_kelayakan'],
                'kategori'         => array(
                    'tipe_laras' => isset($row['tipe_laras']) ? $row['tipe_laras'] : null,
                    'kaliber'    => isset($row['kaliber']) ? $row['kaliber'] : null,
                ),
                'foto_url'         => $row['foto_url'],
                'created_at'       => $row['created_at'],
            );
        }

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar senjata termuat.",
            "data" => $mapped
        ));
    }

    /**
     * PUT /api/v1/logistik/senjata/(:any)
     *
     * Perbarui data senjata api. Semua field opsional — hanya yang dikirim yang diupdate.
     * Payload (JSON): nomor_seri, kategori_id, tahun_pengadaan, status_kelayakan, foto_fisik (base64, opsional)
     * Auth: JWT (polda_id untuk jurisdiksi)
     */
    public function senjata_put($senjata_id)
    {
        $this->load->helper('base64_file');

        // ── 1. AUTH: JWT ──
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

        // ── 2. CONTENT-TYPE CHECK: JSON only ──
        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($content_type, 'application/json') === false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array(
                "message" => "Content-Type harus application/json",
                "status" => 415,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. PARSE JSON PAYLOAD ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. EXISTENCE & JURISDICTION CHECK ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $senjata = $this->db->query(
            "SELECT senjata_id FROM tbl_senjata "
            . "WHERE senjata_id = " . $this->db->escape($senjata_id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$senjata) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Data senjata tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. BUILD UPDATE SET (hanya field yang dikirim) ──
        $set = array();

        if (array_key_exists('nomor_seri', $input) && trim($input['nomor_seri']) !== '') {
            $nomor_seri = trim($input['nomor_seri']);

            // Uniqueness check — exclude current record.
            $check = $this->db->query(
                "SELECT senjata_id FROM tbl_senjata WHERE nomor_seri = " . $this->db->escape($nomor_seri)
                . " AND senjata_id != " . $this->db->escape($senjata_id)
            );
            if ($check->num_rows() > 0) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Nomor Seri ini sudah terdaftar di pangkalan data.",
                    "data" => new stdClass()
                ));
                return;
            }

            $set[] = "nomor_seri = '" . $this->db->escape_str($nomor_seri) . "'";
        }

        if (array_key_exists('kategori_id', $input) && (int) $input['kategori_id'] > 0) {
            $kategori_id = (int) $input['kategori_id'];
            $set[] = "kategori_id = '" . $this->db->escape_str($kategori_id) . "'";
        }

        if (array_key_exists('tahun_pengadaan', $input) && trim($input['tahun_pengadaan']) !== '') {
            $set[] = "tahun_pengadaan = '" . $this->db->escape_str(trim($input['tahun_pengadaan'])) . "'";
        }

        if (array_key_exists('status_kelayakan', $input) && trim($input['status_kelayakan']) !== '') {
            $set[] = "status_kelayakan = '" . $this->db->escape_str(trim($input['status_kelayakan'])) . "'";
        }

        // ── 6. FOTO (opsional): hanya update bila base64 baru dikirim ──
        if (array_key_exists('foto_fisik', $input) && $input['foto_fisik'] !== null && $input['foto_fisik'] !== '') {
            $upload_dir = FCPATH . 'uploads/senjata/';
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg'];
            $result = save_base64_file($input['foto_fisik'], $upload_dir, $allowed_mimes, 512000);

            if (!$result['success']) {
                $status = isset($result['status']) ? $result['status'] : 400;
                $this->output->set_content_type('application/json')->set_status_header($status);
                echo json_encode(array(
                    "message" => $result['error'],
                    "status" => $status,
                    "data" => new stdClass()
                ));
                return;
            }

            $foto_url = 'uploads/senjata/' . $result['file_name'];
            $set[] = "foto_url = '" . $this->db->escape_str($foto_url) . "'";
        }

        // ── 7. NOTHING TO UPDATE? ──
        if (empty($set)) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Tidak ada field yang dapat diperbarui.",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 8. EXECUTE UPDATE ──
        $sql = "UPDATE tbl_senjata SET " . implode(', ', $set)
             . " WHERE senjata_id = '" . $this->db->escape_str($senjata_id) . "'"
             . " AND polda_id = '" . $this->db->escape_str($polda_id) . "'";

        $update = $this->db->query($sql);

        if (!$update) {
            // Rollback: hapus file foto baru yang sudah tersimpan bila ada
            if (isset($result['file_path'])) {
                @unlink($result['file_path']);
            }
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal memperbarui data senjata",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 9. SUCCESS ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data senjata berhasil diperbarui.",
            "data" => new stdClass()
        ));
    }

    /**
     * POST /api/v1/logistik/amunisi
     *
     * Input batch amunisi baru dengan validasi tanggal.
     * Payload (JSON): kode_batch, kategori_id, jumlah_butir, tanggal_masuk, tanggal_kedaluwarsa
     * Auth: JWT (auto-inject polda_id)
     */
    public function amunisi_post()
    {
        // ── 1. AUTH: JWT ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_content_type('application/json')->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 2. CONTENT-TYPE CHECK: JSON only ──
        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($content_type, 'application/json') === false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array(
                "message" => "Content-Type harus application/json",
                "status" => 415,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. PARSE JSON PAYLOAD ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $kode_batch           = isset($input['kode_batch']) ? trim($input['kode_batch']) : '';
        $kategori_id          = isset($input['kategori_id']) ? (int) $input['kategori_id'] : 0;
        $jumlah_butir         = isset($input['jumlah_butir']) ? intval($input['jumlah_butir']) : 0;
        $tanggal_masuk        = isset($input['tanggal_masuk']) ? trim($input['tanggal_masuk']) : '';
        $tanggal_kedaluwarsa  = isset($input['tanggal_kedaluwarsa']) ? trim($input['tanggal_kedaluwarsa']) : '';

        // ── 4. DATE VALIDATION: kedaluwarsa > masuk ──
        if (strtotime($tanggal_kedaluwarsa) <= strtotime($tanggal_masuk)) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "status" => 400,
                "message" => "Validasi gagal. Tanggal kedaluwarsa harus lebih besar dari tanggal masuk.",
                "data" => (object)[]
            ));
            return;
        }

        // ── 5. AUTO-INJECT polda_id FROM JWT ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 6. INSERT INTO tbl_amunisi_batch (batch_id auto-increment by DB) ──
        $data = array(
            'polda_id'              => $polda_id,
            'kode_batch'            => $kode_batch,
            'kategori_id'           => $kategori_id,
            'jumlah_butir'          => $jumlah_butir,
            'tanggal_masuk'         => $tanggal_masuk,
            'tanggal_kedaluwarsa'   => $tanggal_kedaluwarsa
        );

        $insert = $this->db->insert('tbl_amunisi_batch', $data);

        if (!$insert) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan data batch amunisi",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 8. SUCCESS: HTTP 201 Created ──
        $this->output->set_content_type('application/json')->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Batch amunisi sukses terdaftar.",
            "data" => (object)[]
        ));
    }

    /**
     * PUT /api/v1/logistik/amunisi/(:any)
     *
     * Update batch amunisi (field-by-field, only fields present in payload).
     * Payload (JSON): kode_batch, kategori_id, jumlah_butir, tanggal_masuk, tanggal_kedaluwarsa
     * Auth: JWT (jurisdiction check on polda_id)
     */
    public function amunisi_put($batch_id)
    {
        // ── 1. AUTH: JWT ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_content_type('application/json')->set_status_header(401);
            echo json_encode(array(
                "message" => "Token tidak ditemukan",
                "status" => 401,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 2. CONTENT-TYPE CHECK: JSON only ──
        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($content_type, 'application/json') === false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array(
                "message" => "Content-Type harus application/json",
                "status" => 415,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. PARSE JSON PAYLOAD ──
        $input = json_decode($this->input->raw_input_stream, true);
        if (!$input) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Format JSON tidak valid",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. EXISTENCE & JURISDICTION CHECK ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $batch = $this->db->query(
            "SELECT batch_id FROM tbl_amunisi_batch "
            . "WHERE batch_id = " . $this->db->escape($batch_id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$batch) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Batch amunisi tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 5. DATE VALIDATION: kedaluwarsa > masuk (hanya jika KEDUANYA dikirim) ──
        $tanggal_masuk       = isset($input['tanggal_masuk']) ? trim($input['tanggal_masuk']) : '';
        $tanggal_kedaluwarsa = isset($input['tanggal_kedaluwarsa']) ? trim($input['tanggal_kedaluwarsa']) : '';

        if ($tanggal_masuk !== '' && $tanggal_kedaluwarsa !== '') {
            if (strtotime($tanggal_kedaluwarsa) <= strtotime($tanggal_masuk)) {
                $this->output->set_content_type('application/json')->set_status_header(400);
                echo json_encode(array(
                    "status" => 400,
                    "message" => "Validasi gagal. Tanggal kedaluwarsa harus lebih besar dari tanggal masuk.",
                    "data" => (object)[]
                ));
                return;
            }
        }

        // ── 6. BUILD DYNAMIC UPDATE DATA (hanya field yang dikirim) ──
        $update_data = array();

        if (array_key_exists('kode_batch', $input) && trim($input['kode_batch']) !== '') {
            $update_data['kode_batch'] = trim($input['kode_batch']);
        }

        if (array_key_exists('kategori_id', $input) && (int) $input['kategori_id'] > 0) {
            $update_data['kategori_id'] = (int) $input['kategori_id'];
        }

        if (array_key_exists('jumlah_butir', $input) && trim($input['jumlah_butir']) !== '') {
            $update_data['jumlah_butir'] = intval($input['jumlah_butir']);
        }

        if (array_key_exists('tanggal_masuk', $input) && $tanggal_masuk !== '') {
            $update_data['tanggal_masuk'] = $tanggal_masuk;
        }

        if (array_key_exists('tanggal_kedaluwarsa', $input) && $tanggal_kedaluwarsa !== '') {
            $update_data['tanggal_kedaluwarsa'] = $tanggal_kedaluwarsa;
        }

        // ── 7. NOTHING TO UPDATE? ──
        if (empty($update_data)) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Tidak ada field yang dapat diperbarui.",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 8. EXECUTE UPDATE (jurisdiction re-enforced in WHERE) ──
        $update = $this->db->update('tbl_amunisi_batch', $update_data, array(
            'batch_id' => $batch_id,
            'polda_id' => $polda_id
        ));

        if (!$update) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal memperbarui data amunisi",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 9. SUCCESS ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data amunisi berhasil diperbarui",
            "data" => new stdClass()
        ));
    }

    /**
     * GET /api/v1/logistik/amunisi
     *
     * Monitoring batch amunisi + H-90 alert engine.
     * Auth: JWT (polda_id for jurisdiction)
     */
    public function amunisi_get()
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. JURISDICTION ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. BUILD QUERY ──
        $this->db->select('a.*, k.kaliber');
        $this->db->from('tbl_amunisi_batch a');
        // LEFT JOIN so batches still appear even if the Kategori was soft-deleted,
        // but the (deleted) Kategori name must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('a.polda_id', $polda_id);
        }

        // Search filter
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->like('a.kode_batch', $search);
        }

        $this->db->order_by('a.created_at', 'DESC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 4. H-90 ALERT ENGINE & DATA MAPPING ──
        $today = time();
        $mapped = array();
        foreach ($rows as $row) {
            $expiry = strtotime($row['tanggal_kedaluwarsa']);
            $hari_tersisa = (int) floor(($expiry - $today) / 86400);

            $mapped[] = array(
                'batch_id'            => (int) $row['batch_id'],
                'polda_id'            => (int) $row['polda_id'],
                'kode_batch'          => $row['kode_batch'],
                'kategori'            => array(
                    'kaliber' => isset($row['kaliber']) ? $row['kaliber'] : null
                ),
                'jumlah_butir'        => (int) $row['jumlah_butir'],
                'tanggal_masuk'       => $row['tanggal_masuk'],
                'tanggal_kedaluwarsa' => $row['tanggal_kedaluwarsa'],
                'is_h90_alert'        => ($hari_tersisa <= 90) ? true : false,
                'hari_tersisa'        => $hari_tersisa,
                'created_at'          => $row['created_at'],
                'updated_at'          => $row['updated_at']
            );
        }

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar amunisi termuat.",
            "data" => $mapped
        ));
    }

    /**
     * DELETE /api/v1/logistik/amunisi/(:any)
     *
     * Hapus batch amunisi. ID dibaca dari URL segment.
     * Auth: JWT (polda_id untuk jurisdiksi)
     */
    public function amunisi_delete($batch_id)
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. EXISTENCE & JURISDICTION CHECK ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $batch = $this->db->query(
            "SELECT batch_id FROM tbl_amunisi_batch "
            . "WHERE batch_id = " . $this->db->escape($batch_id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$batch) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Batch amunisi tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. DELETE ──
        $sql = "DELETE FROM tbl_amunisi_batch WHERE batch_id = " . $this->db->escape($batch_id);
        $delete = $this->db->query($sql);

        if (!$delete) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menghapus batch amunisi",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. SUCCESS ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data berhasil dihapus",
            "data" => new stdClass()
        ));
    }

    /**
     * POST /api/v1/logistik/satwa
     *
     * Registrasi aset satwa (K9 & Turangga).
     * Auth: JWT (polda_id extracted from token)
     */
    public function satwa_post()
    {
        // ── 1. AUTH: JWT ──
        $payload = get_jwt_payload($this);
        if (!$payload) {
            $this->output->set_status_header(401);
            echo json_encode(array("message" => "Token tidak ditemukan", "status" => 401, "data" => new stdClass()));
            return;
        }
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 2. CONTENT TYPE GATE ──
        if (strpos($this->input->server('CONTENT_TYPE'), 'application/json') === false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array("message" => "Content-Type must be application/json", "status" => 415, "data" => (object)[]));
            return;
        }

        // ── 3. PARSE JSON ──
        $input = json_decode($this->input->raw_input_stream);
        if (!$input) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array("message" => "Invalid JSON payload", "status" => 400, "data" => (object)[]));
            return;
        }

        // ── 4. EXTRACT FIELDS ──
        $nomor_registrasi = isset($input->nomor_registrasi) ? trim($input->nomor_registrasi) : '';
        $jenis_satwa      = isset($input->jenis_satwa) ? trim($input->jenis_satwa) : '';
        $nama_satwa       = isset($input->nama_satwa) ? trim($input->nama_satwa) : '';
        $nama_handler     = isset($input->nama_handler) ? trim($input->nama_handler) : '';
        $kualifikasi      = isset($input->kualifikasi) ? trim($input->kualifikasi) : '';
        $jadwal_vaksin    = isset($input->jadwal_vaksin) ? trim($input->jadwal_vaksin) : null;
        $foto_fisik       = isset($input->foto_fisik) ? trim($input->foto_fisik) : '';

        // ── 5. MANDATORY PHOTO ──
        if (empty($foto_fisik)) {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array("status" => 422, "message" => "Validasi gagal. Foto bukti fisik satwa wajib dilampirkan.", "data" => (object)[]));
            return;
        }

        // ── 6. UNIQUE NOMOR REGISTRASI ──
        $dupe = $this->db->get_where('tbl_satwa', array('nomor_registrasi' => $nomor_registrasi))->row();
        if ($dupe) {
            $this->output->set_content_type('application/json')->set_status_header(422);
            echo json_encode(array("status" => 422, "message" => "Nomor registrasi sudah ada di pangkalan data.", "data" => (object)[]));
            return;
        }

        // ── 7. BEGIN TRANSACTION ──
        $this->db->trans_begin();

        // ── 8. SAVE BASE64 FILE ──
        $upload_dir = FCPATH . 'uploads/satwa/';
        $result = save_base64_file($foto_fisik, $upload_dir, array('image/jpeg', 'image/png', 'image/jpg'), 512000);

        if (!$result['success']) {
            $this->db->trans_rollback();
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menyimpan foto: " . $result['error'],
                "status" => 500,
                "data" => (object)[]
            ));
            return;
        }
        $foto_url = $upload_dir . $result['file_name'];

        // ── 9. INSERT ──
        $insert_data = array(
            'polda_id'          => $polda_id,
            'nomor_registrasi'  => $nomor_registrasi,
            'jenis_satwa'       => $jenis_satwa,
            'nama_satwa'        => $nama_satwa,
            'nama_handler'      => $nama_handler,
            'kualifikasi'       => $kualifikasi,
            'jadwal_vaksin'     => $jadwal_vaksin,
            'foto_url'          => $foto_url
        );

        $this->db->insert('tbl_satwa', $insert_data);

        if ($this->db->affected_rows() === 0) {
            $this->db->trans_rollback();
            @unlink($foto_url);
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array("message" => "Gagal menyimpan data satwa.", "status" => 500, "data" => (object)[]));
            return;
        }

        // ── 10. COMMIT ──
        $this->db->trans_commit();

        // ── 11. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(201);
        echo json_encode(array(
            "status" => 201,
            "message" => "Data satwa berhasil didaftarkan.",
            "data" => (object)[]
        ));
    }

    /**
     * DELETE /api/v1/logistik/senjata/(:any)
     *
     * Hapus data senjata api. ID dibaca dari URL segment.
     * Auth: JWT (polda_id untuk jurisdiksi)
     */
    public function senjata_delete($senjata_id)
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. EXISTENCE & JURISDICTION CHECK ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $senjata = $this->db->query(
            "SELECT senjata_id FROM tbl_senjata "
            . "WHERE senjata_id = " . $this->db->escape($senjata_id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$senjata) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Data senjata tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. DELETE ──
        $sql = "DELETE FROM tbl_senjata WHERE senjata_id = " . $this->db->escape($senjata_id);
        $delete = $this->db->query($sql);

        if (!$delete) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menghapus data senjata",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. SUCCESS ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data berhasil dihapus",
            "data" => new stdClass()
        ));
    }

    /**
     * OPTIONS /api/v1/logistik/senjata, /api/v1/logistik/senjata/(:any)
     *
     * CORS preflight. Route exists so CI3 passes the pre-dispatch
     * method_exists() gate (CodeIgniter.php:423) and instantiates the
     * controller, letting __construct() emit CORS headers.
     * $id = null satisfies both the exact and (:any) OPTIONS routes.
     */
    public function senjata_options($id = null) {
        http_response_code(200);
        exit;
    }

    /**
     * OPTIONS /api/v1/logistik/amunisi, /api/v1/logistik/amunisi/(:any)
     *
     * CORS preflight. Routes exist so CI3 passes the pre-dispatch
     * method_exists() gate and instantiates the controller, letting
     * __construct() emit CORS headers.
     * $id = null satisfies both the exact and (:any) OPTIONS routes.
     */
    public function amunisi_options($id = null) {
        http_response_code(200);
        exit;
    }

    /**
     * OPTIONS /api/v1/logistik/sarpras, /api/v1/logistik/sarpras/(:any)
     *
     * CORS preflight. Route exists so CI3 passes the pre-dispatch
     * method_exists() gate (CodeIgniter.php:423) and instantiates the
     * controller, letting __construct() emit CORS headers.
     * $id = null satisfies both the exact and (:any) OPTIONS routes.
     */
    public function sarpras_options($id = null) {
        http_response_code(200);
        exit;
    }

    /**
     * GET /api/v1/logistik/sarpras
     *
     * Inventarisasi Sarpras & Altmatsus.
     * Auth: JWT (polda_id for jurisdiction), ?search= filters nama_barang OR kode_barang.
     */
    public function sarpras_get()
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. JURISDICTION ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. BUILD QUERY ──
        $this->db->from('tbl_sarpras');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }

        // Search filter — nama_barang OR kode_barang
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('nama_barang', $search);
            $this->db->or_like('kode_barang', $search);
            $this->db->group_end();
        }

        $this->db->order_by('created_at', 'DESC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 4. INTEGER CASTING & MAP ──
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[] = array(
                'sarpras_id'      => $row['sarpras_id'],
                'polda_id'        => (int) $row['polda_id'],
                'kode_barang'     => $row['kode_barang'],
                'nama_barang'     => $row['nama_barang'],
                'kategori'        => $row['kategori'],
                'kondisi'         => $row['kondisi'],
                'tahun_pengadaan' => $row['tahun_pengadaan'],
                'foto_url'        => $row['foto_url'],
                'created_at'      => $row['created_at'],
                'updated_at'      => $row['updated_at'],
            );
        }

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar sarpras termuat.",
            "data" => $mapped
        ));
    }

    /**
     * POST /api/v1/logistik/sarpras        (create, $id = null)
     * POST /api/v1/logistik/sarpras/(:any)  (update, $id set)
     *
     * Registrasi / perbarui Sarpras & Altmatsus.
     * Upload via multipart/form-data — CI3 Upload library handles real
     * image files (jpg|jpeg|png|webp, max 2MB, encrypted filename).
     * Deliberately does NOT enforce the application/json gate that
     * senjata_post uses, because PHP only populates $_FILES on multipart.
     *
     * Form fields: kode_barang, nama_barang, kategori, kondisi, tahun_pengadaan
     * File field:  foto_url (optional; only updated when a new file is sent)
     * Auth: JWT (polda_id auto-inject / jurisdiction)
     */
    public function sarpras_post($id = null)
    {
        // ── 1. AUTH: JWT ──
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
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 2. CONTENT-TYPE: JSON payloads rejected (multipart is the only
        //    way PHP populates $_FILES; do NOT block multipart like senjata_post) ──
        $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        if (strpos($content_type, 'application/json') !== false) {
            $this->output->set_content_type('application/json')->set_status_header(415);
            echo json_encode(array(
                "message" => "Content-Type harus multipart/form-data (upload file tidak mendukung JSON).",
                "status" => 415,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. EXTRACT FORM FIELDS ──
        $kode_barang     = $this->input->post('kode_barang') !== null ? trim($this->input->post('kode_barang')) : '';
        $nama_barang     = $this->input->post('nama_barang') !== null ? trim($this->input->post('nama_barang')) : '';
        $kategori        = $this->input->post('kategori') !== null ? trim($this->input->post('kategori')) : '';
        $kondisi         = $this->input->post('kondisi') !== null ? trim($this->input->post('kondisi')) : '';
        $tahun_pengadaan = $this->input->post('tahun_pengadaan') !== null ? trim($this->input->post('tahun_pengadaan')) : '';

        $is_update = ($id !== null && $id !== '');
        $allowed_kondisi = array('Baik', 'Rusak Ringan', 'Rusak Berat');

        // ── 4. CREATE PATH ──
        if (!$is_update) {
            // Required fields (NOT NULL columns in tbl_sarpras)
            if ($kode_barang === '' || $nama_barang === '') {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Validasi gagal. kode_barang dan nama_barang wajib diisi.",
                    "data" => new stdClass()
                ));
                return;
            }

            // ENUM validation
            if ($kondisi !== '' && !in_array($kondisi, $allowed_kondisi, true)) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Validasi gagal. kondisi harus salah satu dari: Baik, Rusak Ringan, Rusak Berat.",
                    "data" => new stdClass()
                ));
                return;
            }

            // Unique kode_barang rule
            $check = $this->db->query(
                "SELECT sarpras_id FROM tbl_sarpras WHERE kode_barang = " . $this->db->escape($kode_barang)
            );
            if ($check->num_rows() > 0) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Kode Barang ini sudah terdaftar di pangkalan data.",
                    "data" => new stdClass()
                ));
                return;
            }
        }

        // ── 5. UPDATE PATH: EXISTENCE & JURISDICTION CHECK ──
        if ($is_update) {
            $sarpras = $this->db->query(
                "SELECT sarpras_id FROM tbl_sarpras "
                . "WHERE sarpras_id = " . $this->db->escape($id)
                . " AND polda_id = " . $this->db->escape($polda_id)
            )->row_array();

            if (!$sarpras) {
                $this->output->set_content_type('application/json')->set_status_header(404);
                echo json_encode(array(
                    "message" => "Data sarpras tidak ditemukan.",
                    "status" => 404,
                    "data" => new stdClass()
                ));
                return;
            }

            $set = array();

            if ($kode_barang !== '') {
                // Uniqueness check — exclude current record
                $check = $this->db->query(
                    "SELECT sarpras_id FROM tbl_sarpras WHERE kode_barang = " . $this->db->escape($kode_barang)
                    . " AND sarpras_id != " . $this->db->escape($id)
                );
                if ($check->num_rows() > 0) {
                    $this->output->set_content_type('application/json')->set_status_header(422);
                    echo json_encode(array(
                        "status" => 422,
                        "message" => "Kode Barang ini sudah terdaftar di pangkalan data.",
                        "data" => new stdClass()
                    ));
                    return;
                }
                $set[] = "kode_barang = '" . $this->db->escape_str($kode_barang) . "'";
            }

            if ($nama_barang !== '') {
                $set[] = "nama_barang = '" . $this->db->escape_str($nama_barang) . "'";
            }

            if ($kategori !== '') {
                $set[] = "kategori = '" . $this->db->escape_str($kategori) . "'";
            }

            if ($kondisi !== '') {
                if (!in_array($kondisi, $allowed_kondisi, true)) {
                    $this->output->set_content_type('application/json')->set_status_header(422);
                    echo json_encode(array(
                        "status" => 422,
                        "message" => "Validasi gagal. kondisi harus salah satu dari: Baik, Rusak Ringan, Rusak Berat.",
                        "data" => new stdClass()
                    ));
                    return;
                }
                $set[] = "kondisi = '" . $this->db->escape_str($kondisi) . "'";
            }

            if ($tahun_pengadaan !== '') {
                $set[] = "tahun_pengadaan = '" . $this->db->escape_str($tahun_pengadaan) . "'";
            }
        }

        // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
        //    Only when a real file was submitted; foto is optional and
        //    on UPDATE it is only replaced when a new file is sent.
        //    Field name is `foto` (matches Flutter MultipartRequest).
        $foto_url = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_path = FCPATH . 'uploads/sarpras/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }

            $config = array(
                'upload_path'   => $upload_path,          // ./uploads/sarpras/ (app root)
                'allowed_types' => 'jpg|jpeg|png|webp',   // WebP supported
                'max_size'      => 2048,                  // 2MB (KB)
                'encrypt_name'  => TRUE                   // random filename
            );
            $this->load->library('upload', $config);

            if (!$this->upload->do_upload('foto')) {
                $error = $this->upload->display_errors('', '');
                $err = strtolower($error);
                if (strpos($err, 'size') !== false) {
                    $status = 413; // file too large
                } elseif (strpos($err, 'filetype') !== false || strpos($err, 'extension') !== false) {
                    $status = 415; // disallowed type
                } else {
                    $status = 400;
                }
                $this->output->set_content_type('application/json')->set_status_header($status);
                echo json_encode(array(
                    "message" => "Gagal mengunggah foto: " . $error,
                    "status" => $status,
                    "data" => new stdClass()
                ));
                return;
            }

            $upload_data = $this->upload->data();
            $foto_url = 'uploads/sarpras/' . $upload_data['file_name'];
        }

        // ── 7. CREATE: INSERT ──
        if (!$is_update) {
            $sarpras_id = generate_uuid4();

            $sql = "INSERT INTO tbl_sarpras (sarpras_id, polda_id, kode_barang, nama_barang, kategori, kondisi, tahun_pengadaan, foto_url, created_at) "
                 . "VALUES ("
                 . "'" . $this->db->escape_str($sarpras_id) . "', "
                 . "'" . $this->db->escape_str($polda_id) . "', "
                 . "'" . $this->db->escape_str($kode_barang) . "', "
                 . "'" . $this->db->escape_str($nama_barang) . "', "
                 . ($kategori !== '' ? "'" . $this->db->escape_str($kategori) . "'" : "NULL") . ", "
                 . ($kondisi !== '' ? "'" . $this->db->escape_str($kondisi) . "'" : "'Baik'") . ", "
                 . ($tahun_pengadaan !== '' ? "'" . $this->db->escape_str($tahun_pengadaan) . "'" : "NULL") . ", "
                 . ($foto_url !== null ? "'" . $this->db->escape_str($foto_url) . "'" : "NULL") . ", "
                 . "NOW()"
                 . ")";

            $insert = $this->db->query($sql);

            if (!$insert) {
                // Rollback: delete saved file
                if ($foto_url !== null) {
                    @unlink(FCPATH . $foto_url);
                }
                $this->output->set_content_type('application/json')->set_status_header(500);
                echo json_encode(array(
                    "message" => "Gagal menyimpan data sarpras",
                    "status" => 500,
                    "data" => new stdClass()
                ));
                return;
            }

            // SUCCESS: HTTP 201 Created
            $this->output->set_content_type('application/json')->set_status_header(201);
            echo json_encode(array(
                "status" => 201,
                "message" => "Data sarpras berhasil didaftarkan.",
                "data" => array(
                    "sarpras_id" => $sarpras_id
                )
            ));
            return;
        }

        // ── 8. UPDATE: EXECUTE (jurisdiction re-enforced in WHERE) ──
        if ($foto_url !== null) {
            $set[] = "foto_url = '" . $this->db->escape_str($foto_url) . "'";
        }

        if (empty($set)) {
            $this->output->set_content_type('application/json')->set_status_header(400);
            echo json_encode(array(
                "message" => "Tidak ada field yang dapat diperbarui.",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }

        $update = $this->db->query(
            "UPDATE tbl_sarpras SET " . implode(', ', $set) . ", updated_at = NOW() "
            . "WHERE sarpras_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        );

        if (!$update) {
            // Rollback: delete newly saved file
            if ($foto_url !== null) {
                @unlink(FCPATH . $foto_url);
            }
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal memperbarui data sarpras",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // SUCCESS: HTTP 200 OK
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data sarpras berhasil diperbarui.",
            "data" => new stdClass()
        ));
    }

    /**
     * DELETE /api/v1/logistik/sarpras/(:any)
     *
     * Hapus data sarpras & altmatsus. ID dibaca dari URL segment.
     * Auth: JWT (polda_id untuk jurisdiksi)
     */
    public function sarpras_delete($id)
    {
        // ── 1. AUTH: JWT ──
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

        // ── 2. EXISTENCE & JURISDICTION CHECK ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        $sarpras = $this->db->query(
            "SELECT sarpras_id, foto_url FROM tbl_sarpras "
            . "WHERE sarpras_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$sarpras) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Data sarpras tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. DELETE (jurisdiction re-enforced in WHERE) ──
        $sql = "DELETE FROM tbl_sarpras "
             . "WHERE sarpras_id = " . $this->db->escape($id)
             . " AND polda_id = " . $this->db->escape($polda_id);
        $delete = $this->db->query($sql);

        // Clean up local photo file (uploads/* only; skip remote placeholder URLs)
        if ($delete && $sarpras['foto_url'] !== null && strpos($sarpras['foto_url'], 'uploads/') === 0) {
            @unlink(FCPATH . $sarpras['foto_url']);
        }

        if (!$delete) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menghapus data sarpras",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 4. SUCCESS ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data berhasil dihapus",
            "data" => new stdClass()
        ));
    }
}
