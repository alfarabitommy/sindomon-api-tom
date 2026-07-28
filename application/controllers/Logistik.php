<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Logistik extends CI_Controller {

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
        $upload_dir = dirname(FCPATH) . '/uploads/senjata/';
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
        $this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id', 'left');

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
        $upload_dir = dirname(FCPATH) . '/uploads/satwa/';
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
}
