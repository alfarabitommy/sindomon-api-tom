-- phpMyAdmin SQL Dump
-- version 5.1.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 15, 2026 at 03:51 PM
-- Server version: 10.4.19-MariaDB
-- PHP Version: 7.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sindomondb`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_dokumen_hukum`
--

CREATE TABLE `tbl_dokumen_hukum` (
  `dokumen_id` int(11) NOT NULL,
  `kategori` enum('Perkap','Perpol','SOP','Juknis') COLLATE utf8mb4_unicode_ci NOT NULL,
  `judul_dokumen` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tbl_dokumen_hukum`
--

INSERT INTO `tbl_dokumen_hukum` (`dokumen_id`, `kategori`, `judul_dokumen`, `file_url`, `created_at`) VALUES
(1, 'Perkap', 'Peraturan Kapolri No 1 Tahun 2024 tentang Pelayanan Publik', '/uploads/dokumen/perkap_1_2024.pdf', '2024-10-15 08:00:00'),
(2, 'Perpol', 'Peraturan Polisi No 3 Tahun 2024 tentang Patroli', '/uploads/dokumen/perpol_3_2024.pdf', '2024-09-20 10:30:00'),
(3, 'SOP', 'SOP Penanganan Pengaduan Masyarakat', '/uploads/dokumen/sop_pengaduan.pdf', '2024-08-01 14:00:00'),
(4, 'Juknis', 'Juknis Aplikasi e-Complaint 2024', '/uploads/dokumen/juknis_ecomplaint.pdf', '2024-07-10 09:15:00'),
(5, 'Perkap', 'Peraturan Kapolri No 5 Tahun 2023 tentang IT', '/uploads/dokumen/perkap_5_2023.pdf', '2023-12-01 11:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_hub_pengaduan`
--

CREATE TABLE `tbl_hub_pengaduan` (
  `pengaduan_id` int(11) NOT NULL,
  `polda_id` int(11) DEFAULT NULL,
  `sumber` enum('Email','Hotline') COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') COLLATE utf8mb4_unicode_ci DEFAULT 'Open',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tbl_hub_pengaduan`
--

INSERT INTO `tbl_hub_pengaduan` (`pengaduan_id`, `polda_id`, `sumber`, `deskripsi`, `status`, `created_at`) VALUES
(1, 12, 'Hotline', 'Tes tiket masih Open (Polda ID 12)', 'In Progress', '2026-07-13 21:53:38'),
(2, 12, 'Email', 'Tes tiket sudah Closed (Polda ID 12)', 'Closed', '2026-07-13 21:53:38'),
(3, 15, 'Hotline', 'Tes tiket milik wilayah lain (Polda ID 15)', 'Open', '2026-07-13 21:53:38');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_polda`
--

CREATE TABLE `tbl_polda` (
  `id` int(11) NOT NULL,
  `nama_polda` varchar(100) DEFAULT NULL,
  `latitude` varchar(100) DEFAULT NULL,
  `longitude` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_polres`
--

CREATE TABLE `tbl_polres` (
  `id` int(11) NOT NULL,
  `polda_id` int(11) NOT NULL DEFAULT 0,
  `nama_polda` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_role`
--

CREATE TABLE `tbl_role` (
  `id` int(11) NOT NULL,
  `roles` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tbl_role`
--

INSERT INTO `tbl_role` (`id`, `roles`, `created_at`) VALUES
(1, 'Super Admin', '2026-07-11 00:00:00'),
(2, 'Operator Polda', '2026-07-12 00:00:00'),
(3, 'Eksekutif', '2026-07-14 11:25:28');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roles_id` int(11) DEFAULT NULL,
  `polda_id` int(11) DEFAULT NULL,
  `uuid` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expired` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`id`, `username`, `password`, `roles_id`, `uuid`, `token`, `expired`, `created_at`) VALUES
(4, 'admin', '$2y$10$IDgbgGycDFkbdsJ/SZYLge.jo/0lWOHR0RINxo5.tptPAKzRuAWwW', 1, '7997324a-bce7-48b1-bfad-3008068d7ebe', '3b9ecb26465fada071efba2eab80da00', '30', '2026-07-11 16:07:10');

--
-- Indexes for dumped tables
--

--
-- --------------------------------------------------------

--
-- Table structure for table `tbl_proses_hukum`
--

CREATE TABLE `tbl_proses_hukum` (
  `hukum_id` int(11) NOT NULL AUTO_INCREMENT,
  `personil_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `klasifikasi` enum('Pemeriksaan Propam','Sidang Kode Etik','Sidang Disiplin','Pidana Umum') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_hukum` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `deskripsi_kasus` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`hukum_id`),
  KEY `idx_personil_id` (`personil_id`),
  CONSTRAINT `fk_proses_hukum_personil` FOREIGN KEY (`personil_id`) REFERENCES `tbl_personil` (`personil_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for table `tbl_dokumen_hukum`
--
ALTER TABLE `tbl_dokumen_hukum`
  ADD PRIMARY KEY (`dokumen_id`);

--
-- Indexes for table `tbl_hub_pengaduan`
--
ALTER TABLE `tbl_hub_pengaduan`
  ADD PRIMARY KEY (`pengaduan_id`),
  ADD KEY `idx_polda_id` (`polda_id`);

--
-- Indexes for table `tbl_polda`
--
ALTER TABLE `tbl_polda`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_polres`
--
ALTER TABLE `tbl_polres`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_role`
--
ALTER TABLE `tbl_role`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_polda_id` (`polda_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_dokumen_hukum`
--
ALTER TABLE `tbl_dokumen_hukum`
  MODIFY `dokumen_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_hub_pengaduan`
--
ALTER TABLE `tbl_hub_pengaduan`
  MODIFY `pengaduan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_polda`
--
ALTER TABLE `tbl_polda`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_polres`
--
ALTER TABLE `tbl_polres`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_role`
--
ALTER TABLE `tbl_role`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
