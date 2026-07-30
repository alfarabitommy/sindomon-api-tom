<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Seeder extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // if (!is_cli()) {
        //     echo "CLI access only.";
        //     exit;
        // }
        $this->load->database();
        $this->load->helper('url');
    }

    public function run()
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->_ensure_tables();
        $this->db->truncate('tbl_sitkamtibmas');
        $this->db->truncate('tbl_senjata');
        $this->db->truncate('tbl_amunisi_batch');
        $this->db->truncate('tbl_proses_hukum');
        $this->db->truncate('tbl_personil');
        $this->db->truncate('tbl_satwa');
        $this->db->truncate('tbl_sarpras');
        $this->db->truncate('tbl_dms_surat');
        $this->db->truncate('tbl_hub_pengaduan');
        $this->db->truncate('tbl_kategori_senjata');
        $this->db->truncate('tbl_pangkat');
        $this->db->truncate('tbl_jabatan');
        $this->db->truncate('tbl_polres');
        $this->db->truncate('tbl_polda');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->_seed_wilayah();
        $this->_seed_sdm_master();
        $this->_seed_logistik_master();
        echo "Master Data Seeded Successfully!\n";
        $this->_seed_personil();
        $this->_seed_logistik();
        $this->_seed_satwa();
        $this->_seed_sarpras();
        $this->_seed_operasional();
        $this->_seed_dms_surat();
        $this->_seed_pengaduan();
        echo "Transactional Data Seeded Successfully!\n";
    }

    private function _ensure_tables()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_polda` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nama_polda` varchar(100) DEFAULT NULL,
            `latitude` varchar(100) DEFAULT NULL,
            `longitude` varchar(100) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $has_lat = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_polda'
            AND COLUMN_NAME = 'latitude'")->num_rows();
        if (!$has_lat) {
            $this->db->query("ALTER TABLE `tbl_polda` ADD COLUMN `latitude` varchar(100) DEFAULT NULL AFTER `nama_polda`");
            $this->db->query("ALTER TABLE `tbl_polda` ADD COLUMN `longitude` varchar(100) DEFAULT NULL AFTER `latitude`");
        }
        $has_auto = $this->db->query("SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_polda'
            AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'")->num_rows();
        if (!$has_auto) {
            $this->db->query("ALTER TABLE `tbl_polda` MODIFY COLUMN `id` int(11) NOT NULL AUTO_INCREMENT");
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_polres` (
            `polres_id` int(11) NOT NULL AUTO_INCREMENT,
            `polda_id` int(11) NOT NULL DEFAULT 0,
            `nama_polres` varchar(100) NOT NULL,
            PRIMARY KEY (`polres_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $check = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_polres'
            AND COLUMN_NAME = 'polres_id'")->num_rows();
        if (!$check) {
            $this->db->query("ALTER TABLE `tbl_polres` CHANGE `id` `polres_id` INT(11) NOT NULL AUTO_INCREMENT");
            $this->db->query("ALTER TABLE `tbl_polres` CHANGE `nama_polda` `nama_polres` VARCHAR(100) NOT NULL");
            $this->db->query("ALTER TABLE `tbl_polres` DROP `created_at`");
            try {
                $this->db->query("ALTER TABLE `tbl_polres` ADD CONSTRAINT `fk_polres_polda` FOREIGN KEY (`polda_id`) REFERENCES `tbl_polda`(`id`) ON DELETE RESTRICT");
            } catch (Exception $e) {}
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_pangkat` (
            `pangkat_id` int(11) NOT NULL AUTO_INCREMENT,
            `nama_pangkat` varchar(100) NOT NULL,
            PRIMARY KEY (`pangkat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_jabatan` (
            `jabatan_id` int(11) NOT NULL AUTO_INCREMENT,
            `nama_jabatan` varchar(100) NOT NULL,
            `formasi_ideal` int(11) NOT NULL DEFAULT 0,
            `parent_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`jabatan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_kategori_senjata` (
            `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
            `tipe_laras` enum('Panjang','Pendek') NOT NULL,
            `kaliber` varchar(20) NOT NULL,
            PRIMARY KEY (`kategori_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $has_tipe = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_kategori_senjata'
            AND COLUMN_NAME = 'tipe_laras'")->num_rows();
        if (!$has_tipe) {
            $this->db->query("ALTER TABLE `tbl_kategori_senjata` ADD COLUMN `tipe_laras` enum('Panjang','Pendek') NOT NULL AFTER `kategori_id`");
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_personil` (
            `personil_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `nrp` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
            `nama_lengkap` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `pangkat_id` int(11) DEFAULT NULL,
            `jabatan_id` int(11) DEFAULT NULL,
            `status_aktif` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `polda_id` int(11) DEFAULT NULL,
            `polres_id` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`personil_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_proses_hukum` (
            `hukum_id` int(11) NOT NULL AUTO_INCREMENT,
            `personil_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `klasifikasi` enum('Pemeriksaan Propam','Sidang Kode Etik','Sidang Disiplin','Pidana Umum') COLLATE utf8mb4_unicode_ci NOT NULL,
            `status_hukum` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `tanggal_mulai` date NOT NULL,
            `deskripsi_kasus` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`hukum_id`),
            KEY `idx_personil_id` (`personil_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_amunisi_batch` (
            `batch_id` int(11) NOT NULL AUTO_INCREMENT,
            `polda_id` int(11) DEFAULT NULL,
            `kode_batch` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `kategori_id` int(11) DEFAULT NULL,
            `jumlah_butir` int(11) DEFAULT 0,
            `tanggal_masuk` date DEFAULT NULL,
            `tanggal_kedaluwarsa` date DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_senjata` (
            `senjata_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `nomor_seri` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `kategori_id` int(11) DEFAULT NULL,
            `polda_id` int(11) DEFAULT NULL,
            `tahun_pengadaan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `status_kelayakan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `foto_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`senjata_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_sitkamtibmas` (
            `sitkamtibmas_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `polda_id` int(11) DEFAULT NULL,
            `deskripsi_kejadian` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `level_kritis` enum('Aman','Waspada','Darurat') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `foto_tkp_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`sitkamtibmas_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $satwa_col = $this->db->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA='sindomondb' AND TABLE_NAME='tbl_satwa' AND COLUMN_NAME='satwa_id'")->row_array();
        if ($satwa_col && $satwa_col['DATA_TYPE'] !== 'varchar') {
            $this->db->query("DROP TABLE IF EXISTS `tbl_satwa`");
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_satwa` (
            `satwa_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `polda_id` int(11) DEFAULT NULL,
            `nomor_registrasi` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `jenis_satwa` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `nama_satwa` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `nama_handler` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `kualifikasi` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `jadwal_vaksin` date DEFAULT NULL,
            `foto_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`satwa_id`),
            UNIQUE KEY `uq_nomor_registrasi` (`nomor_registrasi`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_sarpras` (
            `sarpras_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `polda_id` int(11) DEFAULT NULL,
            `kode_barang` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `nama_barang` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `kategori` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `kondisi` enum('Baik','Rusak Ringan','Rusak Berat') COLLATE utf8mb4_unicode_ci DEFAULT 'Baik',
            `tahun_pengadaan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `foto_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`sarpras_id`),
            UNIQUE KEY `uq_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_dms_surat` (
            `surat_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
            `pengirim_polda_id` int(11) DEFAULT NULL,
            `penerima_polda_id` int(11) DEFAULT NULL,
            `judul_surat` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `nomor_surat` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `file_pdf_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `status_tracking` enum('Terkirim','Dibaca') COLLATE utf8mb4_unicode_ci DEFAULT 'Terkirim',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`surat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_hub_pengaduan` (
            `pengaduan_id` int(11) NOT NULL AUTO_INCREMENT,
            `polda_id` int(11) DEFAULT NULL,
            `sumber` enum('Email','Hotline') COLLATE utf8mb4_unicode_ci NOT NULL,
            `deskripsi` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `status` enum('Open','In Progress','Resolved','Closed') COLLATE utf8mb4_unicode_ci DEFAULT 'Open',
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`pengaduan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function _seed_wilayah()
    {
        $poldas = array(
            array('nama_polda' => 'Polda Aceh',                'latitude' => '5.550000',  'longitude' => '95.316666'),
            array('nama_polda' => 'Polda Sumatera Utara',      'latitude' => '3.583333',  'longitude' => '98.666667'),
            array('nama_polda' => 'Polda Sumatera Barat',      'latitude' => '-0.916667', 'longitude' => '100.366667'),
            array('nama_polda' => 'Polda Riau',                'latitude' => '0.533333',  'longitude' => '101.450000'),
            array('nama_polda' => 'Polda Kepulauan Riau',      'latitude' => '0.916667',  'longitude' => '104.450000'),
            array('nama_polda' => 'Polda Jambi',               'latitude' => '-1.583333', 'longitude' => '103.616667'),
            array('nama_polda' => 'Polda Sumatera Selatan',    'latitude' => '-2.983333', 'longitude' => '104.750000'),
            array('nama_polda' => 'Polda Bangka Belitung',     'latitude' => '-2.133333', 'longitude' => '106.116667'),
            array('nama_polda' => 'Polda Bengkulu',            'latitude' => '-3.800000', 'longitude' => '102.266667'),
            array('nama_polda' => 'Polda Lampung',             'latitude' => '-5.416667', 'longitude' => '105.250000'),
            array('nama_polda' => 'Polda Metro Jaya',          'latitude' => '-6.200000', 'longitude' => '106.816666'),
            array('nama_polda' => 'Polda Banten',              'latitude' => '-6.116667', 'longitude' => '106.150000'),
            array('nama_polda' => 'Polda Jawa Barat',          'latitude' => '-6.914744', 'longitude' => '107.609810'),
            array('nama_polda' => 'Polda Jawa Tengah',         'latitude' => '-6.983333', 'longitude' => '110.366667'),
            array('nama_polda' => 'Polda D.I. Yogyakarta',     'latitude' => '-7.800000', 'longitude' => '110.366667'),
            array('nama_polda' => 'Polda Jawa Timur',          'latitude' => '-7.250000', 'longitude' => '112.750000'),
            array('nama_polda' => 'Polda Kalimantan Barat',    'latitude' => '-0.016667', 'longitude' => '109.350000'),
            array('nama_polda' => 'Polda Kalimantan Tengah',   'latitude' => '-2.216667', 'longitude' => '113.916667'),
            array('nama_polda' => 'Polda Kalimantan Selatan',  'latitude' => '-3.316667', 'longitude' => '114.583333'),
            array('nama_polda' => 'Polda Kalimantan Timur',    'latitude' => '-0.500000', 'longitude' => '117.150000'),
            array('nama_polda' => 'Polda Kalimantan Utara',    'latitude' => '3.000000',  'longitude' => '116.533333'),
            array('nama_polda' => 'Polda Bali',                'latitude' => '-8.550000', 'longitude' => '115.266667'),
            array('nama_polda' => 'Polda Nusa Tenggara Barat', 'latitude' => '-8.583333', 'longitude' => '116.116667'),
            array('nama_polda' => 'Polda Nusa Tenggara Timur', 'latitude' => '-10.166667','longitude' => '123.583333'),
            array('nama_polda' => 'Polda Sulawesi Utara',      'latitude' => '1.483333',  'longitude' => '124.850000'),
            array('nama_polda' => 'Polda Gorontalo',           'latitude' => '0.533333',  'longitude' => '123.066667'),
            array('nama_polda' => 'Polda Sulawesi Tengah',     'latitude' => '-0.900000', 'longitude' => '119.850000'),
            array('nama_polda' => 'Polda Sulawesi Selatan',    'latitude' => '-5.133333', 'longitude' => '119.416667'),
            array('nama_polda' => 'Polda Sulawesi Tenggara',   'latitude' => '-3.966667', 'longitude' => '122.516667'),
            array('nama_polda' => 'Polda Sulawesi Barat',      'latitude' => '-2.683333', 'longitude' => '118.900000'),
            array('nama_polda' => 'Polda Maluku',              'latitude' => '-3.700000', 'longitude' => '128.166667'),
            array('nama_polda' => 'Polda Maluku Utara',        'latitude' => '0.783333',  'longitude' => '127.366667'),
            array('nama_polda' => 'Polda Papua',               'latitude' => '-2.533333', 'longitude' => '140.716667'),
            array('nama_polda' => 'Polda Papua Barat',         'latitude' => '-0.866667', 'longitude' => '134.083333'),
            array('nama_polda' => 'Polda Papua Selatan',       'latitude' => '-8.500000', 'longitude' => '140.400000'),
            array('nama_polda' => 'Polda Papua Tengah',        'latitude' => '-3.350000', 'longitude' => '135.500000'),
            array('nama_polda' => 'Polda Papua Pegunungan',    'latitude' => '-4.100000', 'longitude' => '138.950000'),
            array('nama_polda' => 'Polda Papua Barat Daya',    'latitude' => '-0.866667', 'longitude' => '131.250000'),
        );

        $this->db->insert_batch('tbl_polda', $poldas);
        echo "  Seeded " . count($poldas) . " Polda.\n";

        $polres = array();
        for ($i = 1; $i <= 38; $i++) {
            $polres[] = array('polda_id' => $i, 'nama_polres' => "Polrestabes {$i}.1");
            $polres[] = array('polda_id' => $i, 'nama_polres' => "Polres {$i}.2");
        }
        $this->db->insert_batch('tbl_polres', $polres);
        echo "  Seeded " . count($polres) . " Polres.\n";
    }

    private function _seed_sdm_master()
    {
        $pangkat = array(
            array('nama_pangkat' => 'Bripda'),
            array('nama_pangkat' => 'Briptu'),
            array('nama_pangkat' => 'Brigpol'),
            array('nama_pangkat' => 'Bripka'),
            array('nama_pangkat' => 'Aipda'),
            array('nama_pangkat' => 'Aiptu'),
            array('nama_pangkat' => 'Ipda'),
            array('nama_pangkat' => 'Iptu'),
            array('nama_pangkat' => 'AKP'),
            array('nama_pangkat' => 'Kompol'),
            array('nama_pangkat' => 'AKBP'),
            array('nama_pangkat' => 'Kombes Pol'),
            array('nama_pangkat' => 'Irjen Pol'),
        );
        $this->db->insert_batch('tbl_pangkat', $pangkat);
        echo "  Seeded " . count($pangkat) . " Pangkat.\n";

        $jabatan = array(
            array('nama_jabatan' => 'Dirsamapta',      'formasi_ideal' => 1,  'parent_id' => null),
            array('nama_jabatan' => 'Wadirsamapta',    'formasi_ideal' => 1,  'parent_id' => null),
            array('nama_jabatan' => 'Kasat Sabhara',   'formasi_ideal' => 1,  'parent_id' => null),
            array('nama_jabatan' => 'Komandan Peleton','formasi_ideal' => 4,  'parent_id' => null),
            array('nama_jabatan' => 'Anggota Dalmas',  'formasi_ideal' => 20, 'parent_id' => null),
            array('nama_jabatan' => 'Kasi Propam',     'formasi_ideal' => 1,  'parent_id' => null),
            array('nama_jabatan' => 'Anggota Samapta', 'formasi_ideal' => 15, 'parent_id' => null),
            array('nama_jabatan' => 'Paur Humas',      'formasi_ideal' => 2,  'parent_id' => null),
        );
        $this->db->insert_batch('tbl_jabatan', $jabatan);
        echo "  Seeded " . count($jabatan) . " Jabatan.\n";
    }

    private function _seed_logistik_master()
    {
        $senjata = array(
            array('tipe_laras' => 'Pendek', 'kaliber' => '9mm'),
            array('tipe_laras' => 'Panjang', 'kaliber' => '5.56mm'),
        );
        $this->db->insert_batch('tbl_kategori_senjata', $senjata);
        echo "  Seeded " . count($senjata) . " Kategori Senjata.\n";
    }

    private function _generate_uuid_v4()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function _seed_personil()
    {
        $jabatan_rows = $this->db->query("SELECT jabatan_id, nama_jabatan FROM tbl_jabatan")->result_array();
        $jabatan_map = array();
        foreach ($jabatan_rows as $r) {
            $jabatan_map[$r['nama_jabatan']] = (int) $r['jabatan_id'];
        }

        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $polres_rows = $this->db->query("SELECT polres_id, polda_id FROM tbl_polres")->result_array();
        $polres_by_polda = array();
        foreach ($polres_rows as $r) {
            $polres_by_polda[(int) $r['polda_id']][] = (int) $r['polres_id'];
        }

        $nama_list = array(
            'AKBP Budi Santoso, S.I.K.', 'Iptu Rina Marlina', 'Bripka Hendra Gunawan', 'Briptu Andi Prasetyo',
            'Kompol Ahmad Fauzi, S.H.', 'Aiptu Dewi Sartika', 'Brigpol Eko Prasetyo', 'Ipda Agus Suprayitno',
            'AKP Bambang Hartono', 'Bripda Sari Wijaya', 'Aipda Dani Ramdani', 'Iptu Mega Lestari',
            'Kompol Agung Prabowo', 'Briptu Wulan Rahmawati', 'Bripka Yulianto Saputra', 'Aiptu Suryadi Putra',
            'Brigpol Ratna Dewi', 'Ipda Hadi Kusuma', 'Bripka Fitri Handayani', 'Aipda Danang Wibowo',
            'Iptu Titis Rahayu', 'Bripda Wahyu Nugroho', 'Kompol Joko Susilo', 'Briptu Indah Permata',
            'Aiptu Slamet Riyadi', 'Bripka Retno Puspita', 'AKP Gunawan Setiawan', 'Brigpol Ari Wibisono',
            'Ipda Iwan Kurniawan', 'Bripda Nilam Sari', 'Bripka Dwi Santosa', 'Aipda Tri Nugroho',
            'Iptu Murni Handayani', 'Briptu Adi Prakoso', 'Brigpol Heru Susanto', 'Aiptu Agung Wijaya',
            'Bripka Catur Prasetyo', 'Ipda Susi Rahmawati', 'Briptu Didi Kurniawan', 'Bripda Dian Pertiwi',
            'AKP Bagus Purnomo', 'Brigpol Yuniarti', 'Kompol Hartono', 'Aipda I Made Sudarma',
            'Bripda Putu Ayu', 'Iptu Laila Khairunnisa', 'Briptu Yogi Pratama', 'Brigpol Reza Maulana',
            'Aiptu Suprapti', 'Ipda Donny Lesmana, S.T.',
        );

        $assigned_personil_ids = array();
        $persons = array();

        for ($i = 0; $i < 50; $i++) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $polres_opts = isset($polres_by_polda[$polda_id]) ? $polres_by_polda[$polda_id] : array(null);
            $polres_id = $polres_opts[array_rand($polres_opts)];

            $pangkat_id = rand(1, 13);

            if ($i < 2) {
                $jabatan_id = $jabatan_map['Dirsamapta'];
            } elseif ($i < 5) {
                $jabatan_id = $jabatan_map['Wadirsamapta'];
            } elseif ($i < 8) {
                $jabatan_id = $jabatan_map['Kasat Sabhara'];
            } elseif ($i < 15) {
                $jabatan_id = $jabatan_map['Komandan Peleton'];
            } elseif ($i < 20) {
                $jabatan_id = $jabatan_map['Kasi Propam'];
            } elseif ($i < 25) {
                $jabatan_id = $jabatan_map['Anggota Samapta'];
            } elseif ($i < 28) {
                $jabatan_id = $jabatan_map['Paur Humas'];
            } else {
                $jabatan_id = $jabatan_map['Anggota Dalmas'];
            }

            $personil_id = $this->_generate_uuid_v4();
            $nrp = str_pad((string) (81000000 + $i * 100 + rand(1, 99)), 8, '0', STR_PAD_LEFT);

            $status_roll = rand(1, 10);
            if ($status_roll <= 7) {
                $status = 'Aktif';
            } elseif ($status_roll <= 9) {
                $status = 'Mutasi';
            } else {
                $status = 'Pensiun';
            }

            $persons[] = array(
                'personil_id'  => $personil_id,
                'nrp'          => $nrp,
                'nama_lengkap' => $nama_list[$i],
                'pangkat_id'   => $pangkat_id,
                'jabatan_id'   => $jabatan_id,
                'status_aktif' => $status,
                'polda_id'     => $polda_id,
                'polres_id'    => $polres_id,
            );
            $assigned_personil_ids[] = $personil_id;
        }

        $this->db->insert_batch('tbl_personil', $persons);
        echo "  Seeded " . count($persons) . " Personil.\n";

        $hukum_data = array();
        $target_ids = array_slice($assigned_personil_ids, 0, 8);
        $klasifikasi_opts = array('Pemeriksaan Propam', 'Sidang Kode Etik', 'Sidang Disiplin', 'Pidana Umum');
        $status_opts = array('Dalam Penyelidikan', 'Proses Sidang', 'Putusan', 'Banding');

        foreach ($target_ids as $idx => $pid) {
            $hukum_data[] = array(
                'personil_id'     => $pid,
                'klasifikasi'     => $klasifikasi_opts[$idx % 4],
                'status_hukum'    => $status_opts[$idx % 4],
                'tanggal_mulai'   => date('Y-m-d', strtotime('-' . (($idx + 1) * 7) . ' days')),
                'deskripsi_kasus' => 'Kasus disiplin simulasi seeder — ' . ($idx + 1),
            );
        }
        $this->db->insert_batch('tbl_proses_hukum', $hukum_data);
        echo "  Seeded " . count($hukum_data) . " Proses Hukum.\n";
    }

    private function _seed_logistik()
    {
        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $senjatas = array();
        $prefixes = array('SNJ', 'HNZ', 'SS2', 'G2', 'P1');
        $kelayakan_opts = array('Laik', 'Laik', 'Laik', 'Rusak Ringan', 'Laik', 'Laik', 'Rusak Berat');
        $tahun_opts = array('2020', '2021', '2022', '2023', '2024');

        for ($i = 0; $i < 35; $i++) {
            $kategori_id = ($i < 20) ? 1 : 2;
            $senjatas[] = array(
                'senjata_id'       => $this->_generate_uuid_v4(),
                'nomor_seri'       => $prefixes[array_rand($prefixes)] . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT) . '-' . strval(rand(2020, 2026)),
                'kategori_id'      => $kategori_id,
                'polda_id'         => $polda_ids[array_rand($polda_ids)],
                'tahun_pengadaan'  => $tahun_opts[array_rand($tahun_opts)],
                'status_kelayakan' => $kelayakan_opts[array_rand($kelayakan_opts)],
                'foto_url'         => 'https://placehold.co/400x300?text=Senjata+' . ($i + 1),
            );
        }
        $this->db->insert_batch('tbl_senjata', $senjatas);
        echo "  Seeded " . count($senjatas) . " Senjata.\n";

        $amunisi = array();
        $batch_prefixes = array('BATCH', 'LOT', 'PROD');

        for ($i = 0; $i < 30; $i++) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $kategori_id = rand(1, 2);
            $days_back = rand(30, 365);
            $days_forward = rand(30, 400);
            $this->db->insert('tbl_amunisi_batch', array(
                'polda_id'            => $polda_id,
                'kode_batch'          => $batch_prefixes[array_rand($batch_prefixes)] . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
                'kategori_id'         => $kategori_id,
                'jumlah_butir'        => rand(500, 10000),
                'tanggal_masuk'       => date('Y-m-d', strtotime('-' . $days_back . ' days')),
                'tanggal_kedaluwarsa' => date('Y-m-d', strtotime('+' . $days_forward . ' days')),
            ));
        }

        $this->db->insert('tbl_amunisi_batch', array(
            'polda_id'            => 1,
            'kode_batch'          => 'BATCH-H90-TRIGGER',
            'kategori_id'         => 1,
            'jumlah_butir'        => 5000,
            'tanggal_masuk'       => date('Y-m-d', strtotime('-100 days')),
            'tanggal_kedaluwarsa' => date('Y-m-d', strtotime('+45 days')),
        ));
        echo "  Seeded 31 Amunisi Batch (incl. H-90 trigger).\n";
    }

    private function _seed_satwa()
    {
        $poldas = $this->db->query("SELECT id, nama_polda FROM tbl_polda")->result_array();

        $k9_names = array('Helder', 'Bruno', 'Rocky', 'Rex', 'Argo', 'Boris', 'Cesar', 'Django', 'Grom', 'Hulk', 'Ivan', 'Loki', 'Max', 'Odin', 'Thor');
        $k9_handlers = array('Briptu Doni Kusuma', 'Bripka Rudi Hartono', 'Brigpol Eko Prasetyo', 'Aipda Suryadi', 'Briptu Adi Prakoso', 'Bripda Wahyu Nugroho', 'Aiptu Slamet Riyadi', 'Brigpol Ari Wibisono', 'Ipda Hadi Kusuma', 'Bripka Dwi Santosa', 'Briptu Yogi Pratama', 'Aiptu Agung Wijaya', 'Brigpol Reza Maulana', 'Bripda Dian Pertiwi', 'Aipda Dani Ramdani');
        $k9_kualifikasi = array('Pelacak', 'Narkotika', 'Patroli', 'Pelacak', 'Narkotika');

        $turangga_names = array('Gagak Rimang', 'Bima Sakti', 'Singa Barong', 'Kyai Slamet', 'Puspo Negoro', 'Roro Mendut', 'Sembada', 'Wahyu Kliyu', 'Panji Asmoro', 'Cakra Buana');
        $turangga_handlers = array('Aiptu Suryadi', 'Briptu Wulan Rahmawati', 'Bripka Hendra Gunawan', 'Brigpol Eko Prasetyo', 'Bripda Sari Wijaya', 'Aipda Danang Wibowo', 'Briptu Indah Permata', 'Ipda Titis Rahayu', 'Brigpol Ratna Dewi', 'Bripka Yulianto Saputra');
        $turangga_kualifikasi = array('Dalmas', 'Patroli', 'Dalmas', 'Patroli', 'Patroli');

        $polda_ids = array_column($poldas, 'id');
        $satwa_data = array();

        for ($i = 0; $i < 15; $i++) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $polda_id_short = $polda_id;
            $satwa_data[] = array(
                'satwa_id'        => $this->_generate_uuid_v4(),
                'polda_id'        => $polda_id,
                'nomor_registrasi'=> 'K9-P' . str_pad((string) $polda_id_short, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'jenis_satwa'     => 'K9',
                'nama_satwa'      => $k9_names[$i],
                'nama_handler'    => $k9_handlers[$i],
                'kualifikasi'     => $k9_kualifikasi[$i % count($k9_kualifikasi)],
                'jadwal_vaksin'   => date('Y-m-d', strtotime('+' . rand(1, 300) . ' days')),
                'foto_url'        => 'https://placehold.co/400x300?text=K9-' . $k9_names[$i],
            );
        }

        for ($i = 0; $i < 10; $i++) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $polda_id_short = $polda_id;
            $satwa_data[] = array(
                'satwa_id'        => $this->_generate_uuid_v4(),
                'polda_id'        => $polda_id,
                'nomor_registrasi'=> 'TRG-P' . str_pad((string) $polda_id_short, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'jenis_satwa'     => 'Turangga',
                'nama_satwa'      => $turangga_names[$i],
                'nama_handler'    => $turangga_handlers[$i],
                'kualifikasi'     => $turangga_kualifikasi[$i % count($turangga_kualifikasi)],
                'jadwal_vaksin'   => date('Y-m-d', strtotime('+' . rand(1, 300) . ' days')),
                'foto_url'        => 'https://placehold.co/400x300?text=Turangga-' . str_replace(' ', '+', $turangga_names[$i]),
            );
        }

        $this->db->insert_batch('tbl_satwa', $satwa_data);
        echo "  Seeded " . count($satwa_data) . " Satwa (15 K9 + 10 Turangga).\n";
    }

    private function _seed_sarpras()
    {
        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $sarpras_items = array(
            array('nama' => 'APC Anoa 6x6',             'kategori' => 'Kendaraan Taktis',       'kondisi' => 'Baik'),
            array('nama' => 'Water Cannon Barracuda',   'kategori' => 'Kendaraan Taktis',       'kondisi' => 'Baik'),
            array('nama' => 'Rantis Tambun',            'kategori' => 'Kendaraan Taktis',       'kondisi' => 'Rusak Ringan'),
            array('nama' => 'Motor Trail Kawasaki KLX250', 'kategori' => 'Kendaraan',          'kondisi' => 'Baik'),
            array('nama' => 'HT Motorola GP380',        'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'Borgol Standar Polri',     'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Tameng Dalmas',            'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Helm Anti-Rusuh',          'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Pentungan Polri',          'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Rompi Anti Peluru IIIA',   'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Rusak Ringan'),
            array('nama' => 'Kamera Bodycam',           'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'Drone DJI Matrice',        'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'Laptop Lenovo ThinkPad',   'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Baik'),
            array('nama' => 'Printer Epson L3210',      'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Rusak Ringan'),
            array('nama' => 'Mobil Patroli Toyota Innova','kategori' => 'Kendaraan',           'kondisi' => 'Baik'),
            array('nama' => 'Mobil Tahanan Isuzu Elf',  'kategori' => 'Kendaraan',             'kondisi' => 'Baik'),
            array('nama' => 'Sepeda Motor Honda CB150R', 'kategori' => 'Kendaraan',            'kondisi' => 'Rusak Berat'),
            array('nama' => 'Radio Rig Yaesu FT-991',   'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'GPS Tracker Garmin',       'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'Tenda Pos PAM Dalmas',     'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Sound System Portable',    'kategori' => 'Alat Komunikasi',        'kondisi' => 'Baik'),
            array('nama' => 'Metal Detector Garett',    'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Baik'),
            array('nama' => 'Kendaraan Rantis Maung',   'kategori' => 'Kendaraan Taktis',       'kondisi' => 'Baik'),
            array('nama' => 'Kursi Roda Evakuasi',      'kategori' => 'Perlengkapan Dalmas',    'kondisi' => 'Rusak Ringan'),
            array('nama' => 'Generator Listrik 5kVA',   'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Baik'),
            array('nama' => 'AC Split 2 PK',             'kategori' => 'Perlengkapan Kantor',   'kondisi' => 'Rusak Berat'),
            array('nama' => 'Meja Rapat Kayu Jati',     'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Baik'),
            array('nama' => 'Kursi Kantor Eksklusif',   'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Baik'),
            array('nama' => 'Lemari Arsip Besi',        'kategori' => 'Perlengkapan Kantor',    'kondisi' => 'Rusak Ringan'),
            array('nama' => 'Dispenser Air',             'kategori' => 'Perlengkapan Kantor',   'kondisi' => 'Baik'),
        );

        $tahun_opts = array('2020', '2021', '2022', '2023', '2024');
        $sarpras_data = array();

        foreach ($sarpras_items as $idx => $item) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $kode = 'SPR-' . strtoupper(preg_replace('/[^A-Za-z]/', '', substr($item['kategori'], 0, 4))) . '-' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT);

            $sarpras_data[] = array(
                'sarpras_id'      => $this->_generate_uuid_v4(),
                'polda_id'        => $polda_id,
                'kode_barang'     => $kode,
                'nama_barang'     => $item['nama'],
                'kategori'        => $item['kategori'],
                'kondisi'         => $item['kondisi'],
                'tahun_pengadaan' => $tahun_opts[array_rand($tahun_opts)],
                'foto_url'        => 'https://placehold.co/400x300?text=Sarpras+' . ($idx + 1),
            );
        }

        $this->db->insert_batch('tbl_sarpras', $sarpras_data);
        echo "  Seeded " . count($sarpras_data) . " Sarpras.\n";
    }

    private function _seed_operasional()
    {
        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $aman_desk = array(
            'Situasi kondusif, patroli rutin berjalan normal.',
            'Lalu lintas lancar, tidak ada gangguan keamanan.',
            'Kegiatan masyarakat berjalan aman dan tertib.',
            'Pos pengamanan berfungsi normal, situasi terkendali.',
            'Tidak ditemukan aktivitas mencurigakan di wilayah hukum.',
            'Wilayah perbatasan dalam keadaan aman terkendali.',
            'Kegiatan pasar tradisional berlangsung aman.',
            'Objek vital negara dalam pengamanan maksimal.',
            'Situasi arus mudik lancar terkendali.',
            'Tidak ada laporan gangguan kamtibmas.',
            'Patroli dialogis bersama warga berjalan lancar.',
            'Kegiatan ibadah berlangsung aman dan tertib.',
            'Situasi perkantoran dan perbankan aman.',
            'Jalur wisata dalam pengamanan optimal.',
            'Koordinator keamanan lingkungan aktif melapor.',
        );

        $waspada_desk = array(
            'Terdeteksi kerumunan massa di pusat kota, patroli ditingkatkan.',
            'Potensi gesekan antar kelompok warga dilaporkan.',
            'Lonjakan arus kendaraan di titik rawan macet.',
            'Cuaca ekstrem berpotensi longsor di jalur utama.',
            'Aksi premanisme terpantau di terminal bus.',
            'Penambahan personil di lokasi rawan tawuran.',
            'Peningkatan aktivitas ormas di wilayah hukum.',
            'Laporan peredaran narkoba di lingkungan sekolah.',
            'Pemantauan ketat di lokasi bekas konflik.',
            'Evakuasi korban bencana alam masih berlangsung.',
        );

        $darurat_desk = array(
            'Bentrokan antar kelompok di wilayah perbatasan, backup diperlukan.',
            'Ledakan bahan peledak di area pemukiman warga.',
            'Konflik bersenjata melibatkan kelompok bersenjata.',
            'Bencana alam mengakibatkan korban jiwa dan kerusakan luas.',
            'Kerusuhan massa meluas, situasi genting memerlukan bantuan.',
        );

        $sitkamtibmas = array();

        foreach ($aman_desk as $desc) {
            $sitkamtibmas[] = array(
                'sitkamtibmas_id'    => $this->_generate_uuid_v4(),
                'polda_id'           => $polda_ids[array_rand($polda_ids)],
                'deskripsi_kejadian' => $desc,
                'level_kritis'       => 'Aman',
                'foto_tkp_url'       => 'https://placehold.co/400x300?text=Aman',
            );
        }

        foreach ($waspada_desk as $desc) {
            $sitkamtibmas[] = array(
                'sitkamtibmas_id'    => $this->_generate_uuid_v4(),
                'polda_id'           => $polda_ids[array_rand($polda_ids)],
                'deskripsi_kejadian' => $desc,
                'level_kritis'       => 'Waspada',
                'foto_tkp_url'       => 'https://placehold.co/400x300?text=Waspada',
            );
        }

        foreach ($darurat_desk as $desc) {
            $sitkamtibmas[] = array(
                'sitkamtibmas_id'    => $this->_generate_uuid_v4(),
                'polda_id'           => $polda_ids[array_rand($polda_ids)],
                'deskripsi_kejadian' => $desc,
                'level_kritis'       => 'Darurat',
                'foto_tkp_url'       => 'https://placehold.co/400x300?text=Darurat',
            );
        }

        $this->db->insert_batch('tbl_sitkamtibmas', $sitkamtibmas);
        echo "  Seeded " . count($sitkamtibmas) . " Sitkamtibmas (15 Aman, 10 Waspada, 5 Darurat).\n";
    }

    private function _seed_dms_surat()
    {
        $poldas = $this->db->query("SELECT id, nama_polda FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $surat_templates = array(
            array('nomor' => 'R/KUM.1/42/VI/2026',   'judul' => 'Permintaan Bantuan Hukum Kasus Narkotika'),
            array('nomor' => 'B/INTEL.1/15/VII/2026','judul' => 'Laporan Intelijen Mingguan Wilayah Perbatasan'),
            array('nomor' => 'R/OPS.1/88/VII/2026',  'judul' => 'Koordinasi Pengamanan Pilkada Serentak'),
            array('nomor' => 'B/SPK.1/03/VII/2026',  'judul' => 'Surat Perintah Penyelidikan Kasus Korupsi'),
            array('nomor' => 'R/PROP.1/21/VI/2026',  'judul' => 'Rekomendasi Sanksi Pelanggaran Disiplin Anggota'),
            array('nomor' => 'B/SDM.1/56/VII/2026',  'judul' => 'Permohonan Mutasi Personil Antar Polda'),
            array('nomor' => 'R/LOG.1/12/VI/2026',   'judul' => 'Pengajuan Pengadaan Senjata Api Tahun 2026'),
            array('nomor' => 'B/KAMT.1/78/VII/2026', 'judul' => 'Laporan Situasi Kamtibmas Bulanan'),
            array('nomor' => 'R/HUK.1/33/VI/2026',   'judul' => 'Permintaan Pendampingan Hukum Sidang Etik'),
            array('nomor' => 'B/YAN.1/09/VII/2026',  'judul' => 'Laporan Pelayanan Publik dan Pengaduan Masyarakat'),
            array('nomor' => 'R/DAL.1/65/VII/2026',  'judul' => 'Permintaan Bantuan Dalmas Pengamanan Unjuk Rasa'),
            array('nomor' => 'B/NARK.1/44/VI/2026',  'judul' => 'Laporan Pengungkapan Jaringan Narkoba Antar Provinsi'),
            array('nomor' => 'R/PAM.1/27/VII/2026',  'judul' => 'Rencana Pengamanan Kunjungan Pejabat Negara'),
            array('nomor' => 'B/TIP.1/18/VI/2026',   'judul' => 'Laporan Penyelidikan Tindak Pidana Siber'),
            array('nomor' => 'R/KER.1/51/VII/2026',  'judul' => 'Kerjasama Patroli Perbatasan Antar Polda'),
            array('nomor' => 'B/INT.1/06/VI/2026',   'judul' => 'Informasi Intelejen Gangguan Keamanan Terkini'),
            array('nomor' => 'R/PEG.1/39/VII/2026',  'judul' => 'Rekomendasi Kenaikan Pangkat Anggota Berprestasi'),
            array('nomor' => 'B/KES.1/11/VI/2026',   'judul' => 'Laporan Kesehatan Personil Satuan Brimob'),
            array('nomor' => 'R/LAP.1/73/VII/2026',  'judul' => 'Laporan Akhir Operasi Ketupat 2026'),
            array('nomor' => 'B/HUB.1/29/VI/2026',   'judul' => 'Undangan Rapat Koordinasi Pimpinan Polda'),
            array('nomor' => 'R/SAT.1/08/VII/2026',  'judul' => 'Permintaan Data Satwa dan Sarpras Polda Jajaran'),
            array('nomor' => 'B/BEN.1/47/VI/2026',   'judul' => 'Laporan Kerusakan Sarana dan Prasarana Akibat Bencana'),
            array('nomor' => 'R/DIK.1/14/VII/2026',  'judul' => 'Usulan Diklat Pengembangan Spesialisasi Anggota'),
            array('nomor' => 'B/KEU.1/22/VI/2026',   'judul' => 'Laporan Realisasi Anggaran Triwulan II'),
            array('nomor' => 'R/KOM.1/05/VII/2026',  'judul' => 'Permohonan Data Dukung Siaran Pers Pengungkapan Kasus'),
        );

        $surat_data = array();
        foreach ($surat_templates as $idx => $tmpl) {
            $pengirim = (rand(0, 1)) ? $polda_ids[array_rand($polda_ids)] : null;
            $penerima = null;
            if ($pengirim !== null) {
                $candidates = array_diff($polda_ids, array($pengirim));
                if (rand(0, 2) > 0) {
                    $penerima = $candidates[array_rand($candidates)];
                }
            } else {
                $penerima = $polda_ids[array_rand($polda_ids)];
            }

            $status = (rand(0, 3) > 0) ? 'Terkirim' : 'Dibaca';

            $surat_data[] = array(
                'surat_id'          => $this->_generate_uuid_v4(),
                'pengirim_polda_id' => $pengirim,
                'penerima_polda_id' => $penerima,
                'judul_surat'       => $tmpl['judul'],
                'nomor_surat'       => $tmpl['nomor'],
                'file_pdf_url'      => 'https://placehold.co/400x600?text=Surat+' . ($idx + 1),
                'status_tracking'   => $status,
            );
        }

        $this->db->insert_batch('tbl_dms_surat', $surat_data);
        echo "  Seeded " . count($surat_data) . " DMS Surat.\n";
    }

    private function _seed_pengaduan()
    {
        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $pengaduan_list = array(
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan pencurian kendaraan bermotor di Jalan Sudirman', 'status' => 'Open'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan pungutan liar oknum Polantas di Terminal Bus', 'status' => 'In Progress'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan balapan liar mengganggu ketertiban umum malam hari', 'status' => 'Resolved'),
            array('sumber' => 'Email',   'deskripsi' => 'Aduan pelayanan lambat pembuatan SKCK di Polres setempat', 'status' => 'Closed'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan penganiayaan oleh sekelompok orang tidak dikenal', 'status' => 'Open'),
            array('sumber' => 'Email',   'deskripsi' => 'Kritik terhadap penanganan kasus penggusuran lahan', 'status' => 'In Progress'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan peredaran narkoba di lingkungan perumahan', 'status' => 'Open'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan suara bising di atas batas wajar', 'status' => 'Resolved'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan penipuan online melalui media sosial', 'status' => 'In Progress'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan KDRT yang diabaikan oleh pihak kepolisian setempat', 'status' => 'Open'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan penemuan mayat tanpa identitas di pinggir sungai', 'status' => 'In Progress'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan marka jalan rusak dan tidak jelas', 'status' => 'Resolved'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan tawuran antar pelajar di jalan raya', 'status' => 'Closed'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan pencemaran lingkungan oleh pabrik', 'status' => 'Open'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan perampokan bersenjata di minimarket', 'status' => 'In Progress'),
            array('sumber' => 'Email',   'deskripsi' => 'Kritik tentang respon lambat piket Polsek dalam menangani kecelakaan', 'status' => 'Open'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan penculikan anak di lingkungan sekolah', 'status' => 'Resolved'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan parkir liar di pusat perbelanjaan', 'status' => 'Resolved'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan pembakaran lahan pertanian merambat ke pemukiman', 'status' => 'In Progress'),
            array('sumber' => 'Email',   'deskripsi' => 'Usulan perbaikan layanan pengaduan 24 jam', 'status' => 'Closed'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan kekerasan terhadap anak di bawah umur', 'status' => 'Open'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan anggota polisi tidak dinas melakukan pemukulan', 'status' => 'In Progress'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan penyalahgunaan wewenang oleh oknum kepala desa', 'status' => 'Open'),
            array('sumber' => 'Email',   'deskripsi' => 'Pengaduan rusaknya penerangan jalan umum', 'status' => 'Closed'),
            array('sumber' => 'Hotline', 'deskripsi' => 'Laporan kericuhan di pasar tradisional, butuh pengamanan', 'status' => 'In Progress'),
        );

        $pengaduan_data = array();
        foreach ($pengaduan_list as $item) {
            $pengaduan_data[] = array(
                'polda_id'   => $polda_ids[array_rand($polda_ids)],
                'sumber'     => $item['sumber'],
                'deskripsi'  => $item['deskripsi'],
                'status'     => $item['status'],
            );
        }

        $this->db->insert_batch('tbl_hub_pengaduan', $pengaduan_data);
        echo "  Seeded " . count($pengaduan_data) . " Hub Pengaduan.\n";
    }
}
