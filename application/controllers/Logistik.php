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
     * POST /api/v1/logistik/senjata, POST /api/v1/logistik/senjata/(:any)
     *
     * Registrasi senjata api baru (CREATE, $id = null) atau perbarui data
     * senjata api (UPDATE, $id diisi dari URL segment).
     * Payload (multipart/form-data): nomor_seri, kategori_id, tahun_pengadaan,
     * status_kelayakan, foto (file: jpg|jpeg|png|webp, max 2MB).
     * Foto wajib pada CREATE, opsional pada UPDATE (hanya diganti bila file baru dikirim).
     * Auth: JWT (auto-inject polda_id)
     */
    public function senjata_post($id = null)
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
        //    way PHP populates $_FILES; do NOT block multipart like the old code) ──
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
        $nomor_seri       = $this->input->post('nomor_seri') !== null ? trim($this->input->post('nomor_seri')) : '';
        $kategori_id      = $this->input->post('kategori_id') !== null ? (int) $this->input->post('kategori_id') : 0;
        $tahun_pengadaan  = $this->input->post('tahun_pengadaan') !== null ? trim($this->input->post('tahun_pengadaan')) : '';
        $status_kelayakan = $this->input->post('status_kelayakan') !== null ? trim($this->input->post('status_kelayakan')) : '';

        $is_update = ($id !== null && $id !== '');

        // ── 4. CREATE PATH ──
        if (!$is_update) {
            // Mandatory photo rule
            if (!isset($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Validasi gagal. Foto bukti fisik senjata wajib dilampirkan.",
                    "data" => new stdClass()
                ));
                return;
            }

            // Unique serial rule
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
        }

        // ── 5. UPDATE PATH: EXISTENCE & JURISDICTION CHECK ──
        if ($is_update) {
            $senjata = $this->db->query(
                "SELECT senjata_id FROM tbl_senjata "
                . "WHERE senjata_id = " . $this->db->escape($id)
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

            $set = array();

            if ($nomor_seri !== '') {
                // Uniqueness check — exclude current record
                $check = $this->db->query(
                    "SELECT senjata_id FROM tbl_senjata WHERE nomor_seri = " . $this->db->escape($nomor_seri)
                    . " AND senjata_id != " . $this->db->escape($id)
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

            if ($kategori_id > 0) {
                $set[] = "kategori_id = '" . $this->db->escape_str($kategori_id) . "'";
            }

            if ($tahun_pengadaan !== '') {
                $set[] = "tahun_pengadaan = '" . $this->db->escape_str($tahun_pengadaan) . "'";
            }

            if ($status_kelayakan !== '') {
                $set[] = "status_kelayakan = '" . $this->db->escape_str($status_kelayakan) . "'";
            }
        }

        // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
        //    Only when a real file was submitted; foto is optional and
        //    on UPDATE it is only replaced when a new file is sent.
        //    Field name is `foto` (matches Flutter MultipartRequest).
        $foto_url = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_path = FCPATH . 'uploads/senjata/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }

            $config = array(
                'upload_path'   => $upload_path,          // ./uploads/senjata/ (app root)
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
            $foto_url = 'uploads/senjata/' . $upload_data['file_name'];
        }

        // ── 7. CREATE: INSERT ──
        if (!$is_update) {
            $senjata_id = generate_uuid4();

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
                if ($foto_url !== null) {
                    @unlink(FCPATH . $foto_url);
                }
                $this->output->set_content_type('application/json')->set_status_header(500);
                echo json_encode(array(
                    "message" => "Gagal menyimpan data senjata",
                    "status" => 500,
                    "data" => new stdClass()
                ));
                return;
            }

            // SUCCESS: HTTP 201 Created
            $this->output->set_content_type('application/json')->set_status_header(201);
            echo json_encode(array(
                "status" => 201,
                "message" => "Data senjata berhasil diregistrasi.",
                "data" => array(
                    "senjata_id" => $senjata_id
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
            "UPDATE tbl_senjata SET " . implode(', ', $set) . " "
            . "WHERE senjata_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        );

        if (!$update) {
            // Rollback: delete newly saved file
            if ($foto_url !== null) {
                @unlink(FCPATH . $foto_url);
            }
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal memperbarui data senjata",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // SUCCESS: HTTP 200 OK
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data senjata berhasil diperbarui.",
            "data" => new stdClass()
        ));
    }

    /**
     * GET /api/v1/logistik/senjata
     *
     * Inventarisasi senjata api — joined with kategori for readable labels.
     * Auth: JWT (polda_id for jurisdiction).
     * Query params: ?search= (nomor_seri OR kaliber OR tipe_laras),
     *               ?page= (1-based, default 1), ?limit= (1..100, default 10).
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

        // ── 3. QUERY PARAMS (pagination & real-time search) ──
        // ?page= is 1-based; ?limit= is clamped to 1..100 like personil_get.
        $search = $this->input->get('search');
        $page   = max(1, (int) ($this->input->get('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

        // ── 4. BUILD QUERY ──
        $this->db->select('s.*, k.tipe_laras, k.kaliber');
        $this->db->from('tbl_senjata s');
        // LEFT JOIN so senjata still appear even if kategori was soft-deleted,
        // but the deleted kategori labels must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 's.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }

        // Search filter — nomor_seri OR kaliber OR tipe_laras.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('s.nomor_seri', $search);
            $this->db->or_like('k.kaliber', $search);
            $this->db->or_like('k.tipe_laras', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_senjata s') set by ->from() above. Passing the
        // table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE/JOIN state for the get() below.
        $total_data = $this->db->count_all_results('', false);

        // ── 6. ORDER & PAGINATION ──
        $this->db->order_by('s.created_at', 'DESC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $query = $this->db->get(); // NO table name — qb_from is already set
        $rows = $query->result_array();

        // ── 7. INTEGER CASTING & MAP ──
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

        // ── 8. SUCCESS RESPONSE (paginated envelope) ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar senjata termuat.",
            "data" => array(
                "items" => $mapped,
                "pagination" => array(
                    "total_data"   => (int) $total_data,
                    "total_pages"  => (int) ceil($total_data / $limit),
                    "current_page" => $page,
                    "per_page"     => $limit
                )
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
     * Monitoring batch amunisi + H-90 alert engine — joined with kategori
     * for the kaliber label. Paginated with real-time search.
     * Auth: JWT (role-based polda_id jurisdiction).
     * Query params: ?search= (kode_batch OR kaliber),
     *               ?page= (1-based, default 1), ?limit= (1..100, default 10).
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

        // ── 2. ROLE & JURISDICTION ──
        // Operator Polda (role_id=2) is locked to the JWT polda_id.
        // Super Admin (role_id=1) / Eksekutif (role_id=3) may optionally
        // override with ?polda_id= to inspect another jurisdiction.
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $polda_id = 0;
        if ($role_id == 2) {
            $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        } else if ($role_id == 1 || $role_id == 3) {
            $query_polda = $this->input->get('polda_id');
            if ($query_polda !== null && $query_polda !== '') {
                $polda_id = (int) $query_polda;
            }
        }

        // ── 3. QUERY PARAMS (pagination & real-time search) ──
        // ?page= is 1-based; ?limit= is clamped to 1..100 like senjata_get.
        $search = $this->input->get('search');
        $page   = max(1, (int) ($this->input->get('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

        // ── 4. BUILD QUERY ──
        $this->db->select('a.*, k.kaliber');
        $this->db->from('tbl_amunisi_batch a');
        // LEFT JOIN so batches still appear even if the Kategori was soft-deleted,
        // but the (deleted) Kategori name must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('a.polda_id', $polda_id);
        }

        // Search filter — kode_batch OR kaliber.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('a.kode_batch', $search);
            $this->db->or_like('k.kaliber', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_amunisi_batch a') set by ->from() above. Passing
        // the table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE/JOIN state for the get() below.
        $total_data = $this->db->count_all_results('', false);

        // ── 6. ORDER & PAGINATION ──
        $this->db->order_by('a.created_at', 'DESC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $rows = $this->db->get()->result_array(); // NO table name — qb_from is already set

        // ── 7. H-90 ALERT ENGINE & DATA MAPPING ──
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

        // ── 8. SUCCESS RESPONSE (paginated envelope) ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar amunisi termuat.",
            "data" => array(
                "items" => $mapped,
                "pagination" => array(
                    "total_data"   => (int) $total_data,
                    "total_pages"  => (int) ceil($total_data / $limit),
                    "current_page" => $page,
                    "per_page"     => $limit
                )
            )
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
     * POST /api/v1/logistik/satwa        (create, $id = null)
     * POST /api/v1/logistik/satwa/(:any)  (update, $id set)
     *
     * Registrasi / perbarui aset satwa (K9 & Turangga).
     * Upload via multipart/form-data — CI3 Upload library handles real
     * image files (jpg|jpeg|png|webp, max 2MB, encrypted filename).
     * Deliberately does NOT enforce the application/json gate that
     * senjata_post uses, because PHP only populates $_FILES on multipart.
     *
     * Form fields: nomor_registrasi, jenis_satwa, nama_satwa, nama_handler,
     *              kualifikasi, jadwal_vaksin
     * File field:  foto (optional; only replaced when a new file is sent)
     * Auth: JWT (polda_id auto-inject / jurisdiction)
     */
    public function satwa_post($id = null)
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
        $nomor_registrasi = $this->input->post('nomor_registrasi') !== null ? trim($this->input->post('nomor_registrasi')) : '';
        $jenis_satwa      = $this->input->post('jenis_satwa') !== null ? trim($this->input->post('jenis_satwa')) : '';
        $nama_satwa       = $this->input->post('nama_satwa') !== null ? trim($this->input->post('nama_satwa')) : '';
        $nama_handler     = $this->input->post('nama_handler') !== null ? trim($this->input->post('nama_handler')) : '';
        $kualifikasi      = $this->input->post('kualifikasi') !== null ? trim($this->input->post('kualifikasi')) : '';
        $jadwal_vaksin    = $this->input->post('jadwal_vaksin') !== null ? trim($this->input->post('jadwal_vaksin')) : '';

        $is_update = ($id !== null && $id !== '');

        // ── 4. CREATE PATH ──
        if (!$is_update) {
            // Required field (NOT NULL column in tbl_satwa)
            if ($nomor_registrasi === '') {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Validasi gagal. nomor_registrasi wajib diisi.",
                    "data" => new stdClass()
                ));
                return;
            }

            // Unique nomor_registrasi rule
            $check = $this->db->query(
                "SELECT satwa_id FROM tbl_satwa WHERE nomor_registrasi = " . $this->db->escape($nomor_registrasi)
            );
            if ($check->num_rows() > 0) {
                $this->output->set_content_type('application/json')->set_status_header(422);
                echo json_encode(array(
                    "status" => 422,
                    "message" => "Nomor registrasi ini sudah terdaftar di pangkalan data.",
                    "data" => new stdClass()
                ));
                return;
            }
        }

        // ── 5. UPDATE PATH: EXISTENCE & JURISDICTION CHECK ──
        if ($is_update) {
            $satwa = $this->db->query(
                "SELECT satwa_id FROM tbl_satwa "
                . "WHERE satwa_id = " . $this->db->escape($id)
                . " AND polda_id = " . $this->db->escape($polda_id)
            )->row_array();

            if (!$satwa) {
                $this->output->set_content_type('application/json')->set_status_header(404);
                echo json_encode(array(
                    "message" => "Data satwa tidak ditemukan.",
                    "status" => 404,
                    "data" => new stdClass()
                ));
                return;
            }

            $set = array();

            if ($nomor_registrasi !== '') {
                // Uniqueness check — exclude current record
                $check = $this->db->query(
                    "SELECT satwa_id FROM tbl_satwa WHERE nomor_registrasi = " . $this->db->escape($nomor_registrasi)
                    . " AND satwa_id != " . $this->db->escape($id)
                );
                if ($check->num_rows() > 0) {
                    $this->output->set_content_type('application/json')->set_status_header(422);
                    echo json_encode(array(
                        "status" => 422,
                        "message" => "Nomor registrasi ini sudah terdaftar di pangkalan data.",
                        "data" => new stdClass()
                    ));
                    return;
                }
                $set[] = "nomor_registrasi = '" . $this->db->escape_str($nomor_registrasi) . "'";
            }

            if ($jenis_satwa !== '') {
                $set[] = "jenis_satwa = '" . $this->db->escape_str($jenis_satwa) . "'";
            }

            if ($nama_satwa !== '') {
                $set[] = "nama_satwa = '" . $this->db->escape_str($nama_satwa) . "'";
            }

            if ($nama_handler !== '') {
                $set[] = "nama_handler = '" . $this->db->escape_str($nama_handler) . "'";
            }

            if ($kualifikasi !== '') {
                $set[] = "kualifikasi = '" . $this->db->escape_str($kualifikasi) . "'";
            }

            if ($jadwal_vaksin !== '') {
                $set[] = "jadwal_vaksin = '" . $this->db->escape_str($jadwal_vaksin) . "'";
            }
        }

        // ── 6. FILE UPLOAD (multipart, CI3 Upload library) ──
        //    Only when a real file was submitted; foto is optional and
        //    on UPDATE it is only replaced when a new file is sent.
        //    Field name is `foto` (matches Flutter MultipartRequest).
        $foto_url = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_path = FCPATH . 'uploads/satwa/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }

            $config = array(
                'upload_path'   => $upload_path,          // ./uploads/satwa/ (app root)
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
            $foto_url = 'uploads/satwa/' . $upload_data['file_name'];
        }

        // ── 7. CREATE: INSERT ──
        if (!$is_update) {
            $satwa_id = generate_uuid4();

            $sql = "INSERT INTO tbl_satwa (satwa_id, polda_id, nomor_registrasi, jenis_satwa, nama_satwa, nama_handler, kualifikasi, jadwal_vaksin, foto_url, created_at) "
                 . "VALUES ("
                 . "'" . $this->db->escape_str($satwa_id) . "', "
                 . "'" . $this->db->escape_str($polda_id) . "', "
                 . "'" . $this->db->escape_str($nomor_registrasi) . "', "
                 . ($jenis_satwa !== '' ? "'" . $this->db->escape_str($jenis_satwa) . "'" : "NULL") . ", "
                 . ($nama_satwa !== '' ? "'" . $this->db->escape_str($nama_satwa) . "'" : "NULL") . ", "
                 . ($nama_handler !== '' ? "'" . $this->db->escape_str($nama_handler) . "'" : "NULL") . ", "
                 . ($kualifikasi !== '' ? "'" . $this->db->escape_str($kualifikasi) . "'" : "NULL") . ", "
                 . ($jadwal_vaksin !== '' ? "'" . $this->db->escape_str($jadwal_vaksin) . "'" : "NULL") . ", "
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
                    "message" => "Gagal menyimpan data satwa",
                    "status" => 500,
                    "data" => new stdClass()
                ));
                return;
            }

            // SUCCESS: HTTP 201 Created
            $this->output->set_content_type('application/json')->set_status_header(201);
            echo json_encode(array(
                "status" => 201,
                "message" => "Data satwa berhasil didaftarkan.",
                "data" => array(
                    "satwa_id" => $satwa_id
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
            "UPDATE tbl_satwa SET " . implode(', ', $set) . " "
            . "WHERE satwa_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        );

        if (!$update) {
            // Rollback: delete newly saved file
            if ($foto_url !== null) {
                @unlink(FCPATH . $foto_url);
            }
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal memperbarui data satwa",
                "status" => 500,
                "data" => new stdClass()
            ));
            return;
        }

        // SUCCESS: HTTP 200 OK
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Data satwa berhasil diperbarui.",
            "data" => new stdClass()
        ));
    }

    /**
     * GET /api/v1/logistik/satwa
     *
     * Inventarisasi aset satwa (K9 & Turangga).
     * Auth: JWT (polda_id for jurisdiction), ?search= filters
     * nomor_registrasi OR nama_satwa.
     */
    public function satwa_get()
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
        $this->db->from('tbl_satwa');

        // Jurisdiction filter — strict: Operator Polda only sees own data
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }

        // Search filter — nomor_registrasi OR nama_satwa
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('nomor_registrasi', $search);
            $this->db->or_like('nama_satwa', $search);
            $this->db->group_end();
        }

        $this->db->order_by('created_at', 'DESC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 4. INTEGER CASTING & MAP ──
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[] = array(
                'satwa_id'         => $row['satwa_id'],
                'polda_id'         => (int) $row['polda_id'],
                'nomor_registrasi' => $row['nomor_registrasi'],
                'jenis_satwa'      => $row['jenis_satwa'],
                'nama_satwa'       => $row['nama_satwa'],
                'nama_handler'     => $row['nama_handler'],
                'kualifikasi'      => $row['kualifikasi'],
                'jadwal_vaksin'    => $row['jadwal_vaksin'],
                'foto_url'         => $row['foto_url'],
                'created_at'       => $row['created_at'],
            );
        }

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar satwa termuat.",
            "data" => $mapped
        ));
    }

    /**
     * DELETE /api/v1/logistik/satwa/(:any)
     *
     * Hapus data satwa. ID dibaca dari URL segment.
     * Auth: JWT (polda_id untuk jurisdiksi)
     */
    public function satwa_delete($id)
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
        $satwa = $this->db->query(
            "SELECT satwa_id, foto_url FROM tbl_satwa "
            . "WHERE satwa_id = " . $this->db->escape($id)
            . " AND polda_id = " . $this->db->escape($polda_id)
        )->row_array();

        if (!$satwa) {
            $this->output->set_content_type('application/json')->set_status_header(404);
            echo json_encode(array(
                "message" => "Data satwa tidak ditemukan.",
                "status" => 404,
                "data" => new stdClass()
            ));
            return;
        }

        // ── 3. DELETE (jurisdiction re-enforced in WHERE) ──
        $sql = "DELETE FROM tbl_satwa "
             . "WHERE satwa_id = " . $this->db->escape($id)
             . " AND polda_id = " . $this->db->escape($polda_id);
        $delete = $this->db->query($sql);

        // Clean up local photo file (uploads/* only; skip remote placeholder URLs)
        if ($delete && $satwa['foto_url'] !== null && strpos($satwa['foto_url'], 'uploads/') === 0) {
            @unlink(FCPATH . $satwa['foto_url']);
        }

        if (!$delete) {
            $this->output->set_content_type('application/json')->set_status_header(500);
            echo json_encode(array(
                "message" => "Gagal menghapus data satwa",
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
     * OPTIONS /api/v1/logistik/satwa, /api/v1/logistik/satwa/(:any)
     *
     * CORS preflight. Routes exist so CI3 passes the pre-dispatch
     * method_exists() gate (CodeIgniter.php:423) and instantiates the
     * controller, letting __construct() emit CORS headers.
     * $id = null satisfies both the exact and (:any) OPTIONS routes.
     */
    public function satwa_options($id = null) {
        http_response_code(200);
        exit;
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
            "SELECT senjata_id, foto_url FROM tbl_senjata "
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

        // Clean up local photo file (uploads/* only; skip remote placeholder URLs)
        if ($delete && $senjata['foto_url'] !== null && strpos($senjata['foto_url'], 'uploads/') === 0) {
            @unlink(FCPATH . $senjata['foto_url']);
        }

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
     * Auth: JWT (role-based jurisdiction: Operator locked to polda_id,
     *        Super Admin/Eksekutif may ?polda_id= override),
     * ?search= filters nama_barang OR kode_barang,
     * ?page= (1-based) + ?limit= (1..100, default 10) pagination.
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

        // ── 2. ROLE & JURISDICTION ──
        // Operator Polda (role_id=2) is locked to the JWT polda_id.
        // Super Admin (role_id=1) / Eksekutif (role_id=3) may optionally
        // override with ?polda_id= to inspect another jurisdiction.
        $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        $polda_id = 0;
        if ($role_id == 2) {
            $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
        } else if ($role_id == 1 || $role_id == 3) {
            $query_polda = $this->input->get('polda_id');
            if ($query_polda !== null && $query_polda !== '') {
                $polda_id = (int) $query_polda;
            }
        }

        // ── 3. QUERY PARAMS (pagination & real-time search) ──
        // ?page= is 1-based; ?limit= is clamped to 1..100 like senjata_get.
        $search = $this->input->get('search');
        $page   = max(1, (int) ($this->input->get('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

        // ── 4. BUILD QUERY ──
        $this->db->from('tbl_sarpras s');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }

        // Search filter — nama_barang OR kode_barang.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('s.nama_barang', $search);
            $this->db->or_like('s.kode_barang', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_sarpras s') set by ->from() above. Passing the
        // table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE state for the get() below.
        $total_data = $this->db->count_all_results('', false);

        // ── 6. ORDER & PAGINATION ──
        $this->db->order_by('s.created_at', 'DESC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $query = $this->db->get(); // NO table name — qb_from is already set
        $rows = $query->result_array();

        // ── 7. INTEGER CASTING & MAP ──
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

        // ── 8. SUCCESS RESPONSE (paginated envelope) ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar sarpras termuat.",
            "data" => array(
                "items" => $mapped,
                "pagination" => array(
                    "total_data"   => (int) $total_data,
                    "total_pages"  => (int) ceil($total_data / $limit),
                    "current_page" => $page,
                    "per_page"     => $limit
                )
            )
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
