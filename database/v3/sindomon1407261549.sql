-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               12.3.2-MariaDB-log - Homebrew
-- Server OS:                    Linux
-- HeidiSQL Version:             12.20.1.1
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for sindomondb
CREATE DATABASE IF NOT EXISTS `sindomondb` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */;
USE `sindomondb`;

-- Dumping structure for table sindomondb.tbl_dokumen_hukum
CREATE TABLE IF NOT EXISTS `tbl_dokumen_hukum` (
  `dokumen_id` int(11) NOT NULL AUTO_INCREMENT,
  `kategori` enum('Perkap','Perpol','SOP','Juknis') NOT NULL,
  `judul_dokumen` varchar(255) NOT NULL,
  `file_url` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dokumen_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Dumping data for table sindomondb.tbl_dokumen_hukum: ~5 rows (approximately)
INSERT INTO `tbl_dokumen_hukum` (`dokumen_id`, `kategori`, `judul_dokumen`, `file_url`, `created_at`) VALUES
	(1, 'Perkap', 'Peraturan Kapolri No 1 Tahun 2024 tentang Pelayanan Publik', '/uploads/dokumen/perkap_1_2024.pdf', '2024-10-15 08:00:00'),
	(2, 'Perpol', 'Peraturan Polisi No 3 Tahun 2024 tentang Patroli', '/uploads/dokumen/perpol_3_2024.pdf', '2024-09-20 10:30:00'),
	(3, 'SOP', 'SOP Penanganan Pengaduan Masyarakat', '/uploads/dokumen/sop_pengaduan.pdf', '2024-08-01 14:00:00'),
	(4, 'Juknis', 'Juknis Aplikasi e-Complaint 2024', '/uploads/dokumen/juknis_ecomplaint.pdf', '2024-07-10 09:15:00'),
	(5, 'Perkap', 'Peraturan Kapolri No 5 Tahun 2023 tentang IT', '/uploads/dokumen/perkap_5_2023.pdf', '2023-12-01 11:00:00');

-- Dumping structure for table sindomondb.tbl_hub_pengaduan
CREATE TABLE IF NOT EXISTS `tbl_hub_pengaduan` (
  `pengaduan_id` int(11) NOT NULL AUTO_INCREMENT,
  `polda_id` int(11) DEFAULT NULL,
  `sumber` enum('Email','Hotline') NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`pengaduan_id`),
  KEY `idx_polda_id` (`polda_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Dumping data for table sindomondb.tbl_hub_pengaduan: ~3 rows (approximately)
INSERT INTO `tbl_hub_pengaduan` (`pengaduan_id`, `polda_id`, `sumber`, `deskripsi`, `status`, `created_at`) VALUES
	(1, 12, 'Hotline', 'Tes tiket masih Open (Polda ID 12)', 'In Progress', '2026-07-14 04:53:38'),
	(2, 12, 'Email', 'Tes tiket sudah Closed (Polda ID 12)', 'Closed', '2026-07-14 04:53:38'),
	(3, 15, 'Hotline', 'Tes tiket milik wilayah lain (Polda ID 15)', 'Open', '2026-07-14 04:53:38');

-- Dumping structure for table sindomondb.tbl_role
CREATE TABLE IF NOT EXISTS `tbl_role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `roles` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Dumping data for table sindomondb.tbl_role: ~2 rows (approximately)
INSERT INTO `tbl_role` (`id`, `roles`, `created_at`) VALUES
	(1, 'Administrator', '2026-07-11 00:00:00'),
	(2, 'Superadmin', '2026-07-12 00:00:00'),
	(3, 'Operator Polda', '2026-07-14 11:25:28');

-- Dumping structure for table sindomondb.tbl_users
CREATE TABLE IF NOT EXISTS `tbl_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `roles_id` int(11) DEFAULT NULL,
  `uuid` varchar(100) DEFAULT NULL,
  `token` varchar(100) DEFAULT NULL,
  `expired` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Dumping data for table sindomondb.tbl_users: ~1 rows (approximately)
INSERT INTO `tbl_users` (`id`, `username`, `password`, `roles_id`, `uuid`, `token`, `expired`, `created_at`) VALUES
	(4, 'admin', '$2y$10$IDgbgGycDFkbdsJ/SZYLge.jo/0lWOHR0RINxo5.tptPAKzRuAWwW', 1, '7997324a-bce7-48b1-bfad-3008068d7ebe', '3b9ecb26465fada071efba2eab80da00', '30', '2026-07-11 16:07:10');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
