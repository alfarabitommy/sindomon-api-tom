<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Seeder extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if (!is_cli()) {
            echo "CLI access only.";
            exit;
        }
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
        $this->_seed_operasional();
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
            array('nama_jabatan' => 'Anggota Dalmas',  'formasi_ideal' => 50, 'parent_id' => null),
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
        $dirsamapta_id    = $jabatan_map['Dirsamapta'];
        $komandan_peleton = $jabatan_map['Komandan Peleton'];
        $anggota_dalmas   = $jabatan_map['Anggota Dalmas'];

        $poldas = $this->db->query("SELECT id FROM tbl_polda")->result_array();
        $polda_ids = array_column($poldas, 'id');

        $polres_rows = $this->db->query("SELECT polres_id, polda_id FROM tbl_polres")->result_array();
        $polres_by_polda = array();
        foreach ($polres_rows as $r) {
            $polres_by_polda[(int) $r['polda_id']][] = (int) $r['polres_id'];
        }

        $persons = array();
        $assigned_personil_ids = array();

        for ($i = 1; $i <= 25; $i++) {
            $polda_id = $polda_ids[array_rand($polda_ids)];
            $polres_opts = isset($polres_by_polda[$polda_id]) ? $polres_by_polda[$polda_id] : array(null);
            $polres_id = $polres_opts[array_rand($polres_opts)];

            $pangkat_id = rand(1, 13);

            // Vacancy trap: assign TO "Anggota Dalmas" (20) and "Komandan Peleton" (5),
            // deliberately ZERO to "Dirsamapta"
            if ($i <= 20) {
                $jabatan_id = $anggota_dalmas;
            } else {
                $jabatan_id = $komandan_peleton;
            }

            $personil_id = $this->_generate_uuid_v4();
            $nrp = 'NRP2024' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            $persons[] = array(
                'personil_id'  => $personil_id,
                'nrp'          => $nrp,
                'nama_lengkap' => 'Personel ' . $i,
                'pangkat_id'   => $pangkat_id,
                'jabatan_id'   => $jabatan_id,
                'status_aktif' => 'Aktif',
                'polda_id'     => $polda_id,
                'polres_id'    => $polres_id,
            );
            $assigned_personil_ids[] = $personil_id;
        }

        $this->db->insert_batch('tbl_personil', $persons);
        echo "  Seeded " . count($persons) . " Personil (Dirsamapta deliberately empty → triggers Vacancy Alert).\n";

        // Insert 1-2 records into tbl_proses_hukum
        $hukum_data = array();
        $target_ids = array_slice($assigned_personil_ids, 0, 2);
        $klasifikasi_opts = array('Pemeriksaan Propam', 'Sidang Kode Etik');

        foreach ($target_ids as $idx => $pid) {
            $hukum_data[] = array(
                'personil_id'     => $pid,
                'klasifikasi'     => $klasifikasi_opts[$idx],
                'status_hukum'    => 'Dalam Penyelidikan',
                'tanggal_mulai'   => date('Y-m-d', strtotime('-1 day')),
                'deskripsi_kasus' => 'Kasus disiplin simulasi seeder — ' . ($idx + 1),
            );
        }
        $this->db->insert_batch('tbl_proses_hukum', $hukum_data);
        echo "  Seeded " . count($hukum_data) . " Proses Hukum (active disciplinary case).\n";
    }

    private function _seed_operasional()
    {
        // H-90 AMMO ALERT: expiry exactly 45 days from today
        $this->db->insert('tbl_amunisi_batch', array(
            'polda_id'            => 1,
            'kode_batch'          => 'BATCH-H90-TRIGGER',
            'kategori_id'         => 1,
            'jumlah_butir'        => 5000,
            'tanggal_masuk'       => date('Y-m-d', strtotime('-100 days')),
            'tanggal_kedaluwarsa' => date('Y-m-d', strtotime('+45 days')),
        ));
        echo "  Seeded 1 Amunisi Batch with expiry +45d (triggers H-90 alert).\n";

        // WEAPON: 2 rows in tbl_senjata
        $senjatas = array(
            array(
                'senjata_id'       => $this->_generate_uuid_v4(),
                'nomor_seri'       => 'SNJ-00-2024-001',
                'kategori_id'      => 1,
                'polda_id'         => 1,
                'tahun_pengadaan'  => '2024',
                'status_kelayakan' => 'Laik',
                'foto_url'         => 'https://placehold.co/400x300?text=Senjata+1',
            ),
            array(
                'senjata_id'       => $this->_generate_uuid_v4(),
                'nomor_seri'       => 'SNJ-00-2024-002',
                'kategori_id'      => 2,
                'polda_id'         => 1,
                'tahun_pengadaan'  => '2024',
                'status_kelayakan' => 'Laik',
                'foto_url'         => 'https://placehold.co/400x300?text=Senjata+2',
            ),
        );
        $this->db->insert_batch('tbl_senjata', $senjatas);
        echo "  Seeded " . count($senjatas) . " Senjata.\n";

        // EMERGENCY MAP ALERT: 1 Aman + 1 Darurat
        $sitkamtibmas = array(
            array(
                'sitkamtibmas_id'    => $this->_generate_uuid_v4(),
                'polda_id'           => 1,
                'deskripsi_kejadian' => 'Situasi kondusif — laporan rutin.',
                'level_kritis'       => 'Aman',
                'foto_tkp_url'       => 'https://placehold.co/400x300?text=Aman',
            ),
            array(
                'sitkamtibmas_id'    => $this->_generate_uuid_v4(),
                'polda_id'           => 1,
                'deskripsi_kejadian' => 'Laporan Darurat — Tes Trigger Command Center.',
                'level_kritis'       => 'Darurat',
                'foto_tkp_url'       => 'https://placehold.co/400x300?text=Darurat',
            ),
        );
        $this->db->insert_batch('tbl_sitkamtibmas', $sitkamtibmas);
        echo "  Seeded " . count($sitkamtibmas) . " Sitkamtibmas (1 Aman, 1 Darurat → triggers red blinking).\n";
    }
}
