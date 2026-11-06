-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 06, 2026 at 09:39 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `comfortbook_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `student_id` int(10) UNSIGNED DEFAULT NULL,
  `semester_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `student_id`, `semester_id`, `action`, `details`, `ip`, `user_agent`, `created_at`) VALUES
(1, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:19:24'),
(2, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:40:06'),
(3, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:53:37'),
(4, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:02:32'),
(5, 2, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:02:42'),
(6, 2, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:07'),
(7, 3, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:16'),
(8, 3, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:31'),
(9, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:07:05'),
(10, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:08:26'),
(11, 2, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:08:36'),
(12, 2, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:09:13'),
(13, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:09:21'),
(14, 1, NULL, NULL, 'demo_reset', 'Demo reset', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:10:32'),
(15, 1, NULL, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:10:57'),
(16, 1, NULL, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:11:10'),
(17, 1, NULL, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:11:49'),
(18, 1, NULL, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:12'),
(19, 1, NULL, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:42'),
(20, 1, NULL, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:45'),
(21, 1, NULL, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:48'),
(22, 1, NULL, NULL, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:23:07'),
(23, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:24:23'),
(24, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:25:38'),
(25, 4, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:45:35'),
(26, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:49:34'),
(27, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:54:48'),
(28, 2, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:54:59'),
(29, 2, NULL, NULL, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:56:23'),
(30, 2, NULL, NULL, 'csv_download', 'Downloaded csv: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:56:38'),
(31, 2, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:58:47'),
(32, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:58:58'),
(33, 4, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:00:40'),
(34, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:00:48'),
(35, 1, NULL, NULL, 'block_edit', 'Edited block #1: New Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:02:24'),
(36, 1, NULL, NULL, 'block_edit', 'Edited block #1: New Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:10'),
(37, 1, NULL, NULL, 'block_edit', 'Edited block #2: Old Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:28'),
(38, 1, NULL, NULL, 'block_edit', 'Edited block #3: Annex', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:50'),
(39, 1, NULL, NULL, 'block_edit', 'Edited block #4: Box', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:04:03'),
(40, 1, NULL, NULL, 'pdf_download', 'Downloaded pdf: staff', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:11:42'),
(41, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:15:19'),
(42, 5, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:16:11'),
(43, 5, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:17:15'),
(44, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:17:25'),
(45, 4, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:19:39'),
(46, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 20:46:22'),
(47, 1, NULL, NULL, 'config_save', 'Updated phase/config', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 20:46:39'),
(48, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:16:40'),
(49, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:19:37'),
(50, 1, 7, NULL, 'student_add', 'Added student #7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:21:34'),
(51, 1, 8, NULL, 'student_add', 'Added student #8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:24:00'),
(52, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:26:49'),
(53, 5, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:26:59'),
(54, 5, 6, NULL, 'manual_assign', 'Assigned UEAB/006 to room #6 (C-101)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:30:39'),
(55, 5, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:30:56'),
(56, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:31:03'),
(57, 4, 7, NULL, 'manual_assign', 'Assigned SJUNOD2621 to room #1 (A-101)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:31:14'),
(58, 4, NULL, NULL, 'pdf_download', 'Downloaded pdf: bookings', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 21:58:39'),
(59, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:25:16'),
(60, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:42:51'),
(61, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:42:57'),
(62, 4, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:45:35'),
(63, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:45:42'),
(64, 1, 82, NULL, 'student_edit', 'Edited student #82', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:52:09'),
(65, 1, 82, NULL, 'student_edit', 'Edited student #82', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:55:21'),
(66, 1, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:56:09'),
(67, 4, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 08:56:16'),
(68, 4, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:05:47'),
(69, 5, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:05:54'),
(70, 5, NULL, NULL, 'bulk_assign', 'Bulk-assigned 21 students in Female wing', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:06:06'),
(71, 5, NULL, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:12:33'),
(72, 1, NULL, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:12:41'),
(73, 1, NULL, 1, 'demo_reset', 'Reset current semester', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:46:36'),
(74, 1, NULL, 1, 'demo_reset', 'Reset current semester', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:50:16'),
(75, 1, NULL, 1, 'demo_reset', 'Reset current semester', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:50:41'),
(76, 1, NULL, 1, 'demo_reset', 'Reset current semester', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:52:11'),
(77, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:52:22'),
(78, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:52:33'),
(79, 2, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:53:44'),
(80, 3, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:53:52'),
(81, 3, NULL, 1, 'pdf_download', 'Downloaded pdf: blocks', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:54:32'),
(82, 3, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:55:34'),
(83, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:55:44'),
(84, 2, 7, 1, 'links_send_one', 'Resent link to SJUNOD2621', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:56:14'),
(85, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:59:13'),
(86, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 09:59:58'),
(87, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:00:45'),
(88, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:01:00'),
(89, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:01:48'),
(90, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:02:01'),
(91, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:05:05'),
(92, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:05:21'),
(93, 2, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:31:43'),
(94, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:31:52'),
(95, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:33:29'),
(96, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:33:40'),
(97, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:40:19'),
(98, 2, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:40:36'),
(99, 3, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:40:43'),
(100, 3, NULL, 1, 'phase_windows_save', 'Set Female windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:41:05'),
(101, 3, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:41:14'),
(102, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:41:22'),
(103, 2, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:51:20'),
(104, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:51:27'),
(105, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:56:44'),
(106, 3, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:56:51'),
(107, 3, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:58:21'),
(108, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 10:58:29'),
(109, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:08:41'),
(110, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:08:50'),
(111, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:09:21'),
(112, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:09:24'),
(113, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:10:16'),
(114, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: bookings', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:10:28'),
(115, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:10:37'),
(116, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:10:57'),
(117, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: blocks', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:11:05'),
(118, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: bookings', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:11:13'),
(119, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:12:50'),
(120, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: rooms', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:12:59'),
(121, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:13:38'),
(122, 2, NULL, 1, 'pdf_download', 'Downloaded pdf: rooms', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:13:44'),
(123, 2, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:14:00'),
(124, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:14:07'),
(125, 1, NULL, 1, 'pdf_download', 'Downloaded pdf: staff', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:14:19'),
(126, 1, NULL, 1, 'pdf_download', 'Downloaded pdf: rooms', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:14:48'),
(127, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:16:05'),
(128, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:22:53'),
(129, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:22:58'),
(130, 1, NULL, 1, 'password_reset_request', 'Requested reset', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:24:27'),
(131, 1, NULL, 1, 'password_reset_done', 'Password reset completed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:25:53'),
(132, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:29:26'),
(133, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:29:33'),
(134, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:29:39'),
(135, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:29:46'),
(136, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:31:26'),
(137, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:31:55'),
(138, 4, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:34:46'),
(139, 4, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:35:46'),
(140, 1, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:35:54'),
(141, 1, NULL, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:36:53'),
(142, 2, NULL, 1, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:37:05'),
(143, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:37:36'),
(144, 2, NULL, 1, 'phase_windows_save', 'Set Male windows for 2026/2027 · Sem 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-06 11:38:00');

-- --------------------------------------------------------

--
-- Table structure for table `blocks`
--

CREATE TABLE `blocks` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `blocks`
--

INSERT INTO `blocks` (`id`, `name`, `gender`, `description`, `is_active`, `created_at`) VALUES
(1, 'New Men', 'Male', NULL, 1, '2026-11-05 11:08:40'),
(2, 'Old Men', 'Male', NULL, 1, '2026-11-05 11:08:40'),
(3, 'Annex', 'Female', NULL, 1, '2026-11-05 11:08:40'),
(4, 'Box', 'Female', NULL, 1, '2026-11-05 11:08:40');

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(10) UNSIGNED NOT NULL,
  `semester_id` int(10) UNSIGNED DEFAULT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `phase` tinyint(3) UNSIGNED NOT NULL,
  `action` enum('keep','release','book') NOT NULL,
  `status` enum('pending_otp','pending_payment','pending_approval','confirmed','cancelled') NOT NULL DEFAULT 'pending_otp',
  `otp_code` char(6) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `otp_verified_at` datetime DEFAULT NULL,
  `mpesa_ref` varchar(10) DEFAULT NULL,
  `penalty_amount` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `cancelled_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `config`
--

CREATE TABLE `config` (
  `config_key` varchar(60) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `config`
--

INSERT INTO `config` (`config_key`, `config_value`, `updated_at`) VALUES
('active_semester_id', '1', '2026-11-06 09:18:37'),
('auto_keep_on_timeout', '1', '2026-11-05 11:08:21'),
('otp_ttl_minutes', '10', '2026-11-05 11:08:21'),
('penalty_amount', '200', '2026-11-05 11:08:21'),
('phase1_end', '2026-11-04 12:12:00', '2026-11-05 20:46:39'),
('phase1_start', '2026-11-05 12:12:00', '2026-11-05 20:46:39'),
('phase2_end', '2026-11-04 12:12:00', '2026-11-05 20:46:39'),
('phase2_start', '2026-11-08 00:00:00', '2026-11-05 12:10:56'),
('show_otp_in_ui', '1', '2026-11-05 11:08:21');

-- --------------------------------------------------------

--
-- Table structure for table `magic_links`
--

CREATE TABLE `magic_links` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `semester_id` int(10) UNSIGNED DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `magic_links`
--

INSERT INTO `magic_links` (`id`, `student_id`, `semester_id`, `token_hash`, `expires_at`, `used_at`, `created_by`, `created_at`) VALUES
(1, 7, NULL, '3d316541420a88b23d3a8b4f3e8b7741ece7872329a2407cf92f32ad6fa40fea', '2026-11-13 09:56:11', '2026-11-06 11:37:38', 2, '2026-11-06 09:56:11');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 1, '$2y$10$43oLpJ.Bx3sC7u4dmq5bLu3TC2Zqf34BGGaXTsm1cE0AcuVBsQKhi', '2026-11-06 12:24:24', '2026-11-06 11:25:53', '2026-11-06 11:24:24');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(10) UNSIGNED NOT NULL,
  `block_id` int(10) UNSIGNED NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `capacity` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `gender` enum('Male','Female') NOT NULL,
  `status` enum('vacant','available_p2','reserved','occupied') NOT NULL DEFAULT 'vacant',
  `reserved_by_student_id` int(10) UNSIGNED DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `block_id`, `room_number`, `capacity`, `gender`, `status`, `reserved_by_student_id`, `reserved_at`, `notes`, `created_at`) VALUES
(1, 1, 'NM-101', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(2, 1, 'NM-102', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(3, 1, 'NM-103', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(4, 1, 'NM-104', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(5, 1, 'NM-105', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(6, 1, 'NM-106', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(7, 1, 'NM-107', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(8, 1, 'NM-108', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(9, 1, 'NM-109', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(10, 1, 'NM-110', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(11, 1, 'NM-111', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(12, 1, 'NM-112', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(13, 1, 'NM-113', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(14, 1, 'NM-114', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(15, 1, 'NM-115', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(16, 1, 'NM-116', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(17, 1, 'NM-117', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(18, 1, 'NM-118', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(19, 1, 'NM-119', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(20, 1, 'NM-120', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(21, 1, 'NM-121', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(22, 1, 'NM-122', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(23, 1, 'NM-123', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(24, 1, 'NM-124', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(25, 1, 'NM-125', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(26, 1, 'NM-126', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(27, 1, 'NM-127', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(28, 1, 'NM-128', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(29, 1, 'NM-129', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(30, 1, 'NM-130', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(31, 1, 'NM-131', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(32, 1, 'NM-132', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(33, 1, 'NM-133', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(34, 1, 'NM-134', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(35, 1, 'NM-135', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(36, 1, 'NM-136', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(37, 1, 'NM-137', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(38, 1, 'NM-138', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(39, 1, 'NM-139', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(40, 1, 'NM-140', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(41, 1, 'NM-141', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(42, 1, 'NM-142', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(43, 1, 'NM-143', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(44, 1, 'NM-144', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(45, 1, 'NM-145', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(46, 1, 'NM-146', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(47, 1, 'NM-147', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(48, 1, 'NM-148', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(49, 1, 'NM-149', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(50, 1, 'NM-150', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(51, 2, 'OM-101', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(52, 2, 'OM-102', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(53, 2, 'OM-103', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(54, 2, 'OM-104', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(55, 2, 'OM-105', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(56, 2, 'OM-106', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(57, 2, 'OM-107', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(58, 2, 'OM-108', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(59, 2, 'OM-109', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(60, 2, 'OM-110', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(61, 2, 'OM-111', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(62, 2, 'OM-112', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(63, 2, 'OM-113', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(64, 2, 'OM-114', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(65, 2, 'OM-115', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(66, 2, 'OM-116', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(67, 2, 'OM-117', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(68, 2, 'OM-118', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(69, 2, 'OM-119', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(70, 2, 'OM-120', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(71, 2, 'OM-121', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(72, 2, 'OM-122', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(73, 2, 'OM-123', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(74, 2, 'OM-124', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(75, 2, 'OM-125', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(76, 2, 'OM-126', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(77, 2, 'OM-127', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(78, 2, 'OM-128', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(79, 2, 'OM-129', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(80, 2, 'OM-130', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(81, 2, 'OM-131', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(82, 2, 'OM-132', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(83, 2, 'OM-133', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(84, 2, 'OM-134', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(85, 2, 'OM-135', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(86, 2, 'OM-136', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(87, 2, 'OM-137', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(88, 2, 'OM-138', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(89, 2, 'OM-139', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(90, 2, 'OM-140', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(91, 2, 'OM-141', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(92, 2, 'OM-142', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(93, 2, 'OM-143', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(94, 2, 'OM-144', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(95, 2, 'OM-145', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(96, 2, 'OM-146', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(97, 2, 'OM-147', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(98, 2, 'OM-148', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(99, 2, 'OM-149', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(100, 2, 'OM-150', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(101, 3, 'AN-101', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(102, 3, 'AN-102', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(103, 3, 'AN-103', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(104, 3, 'AN-104', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(105, 3, 'AN-105', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(106, 3, 'AN-106', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(107, 3, 'AN-107', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(108, 3, 'AN-108', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(109, 3, 'AN-109', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(110, 3, 'AN-110', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(111, 3, 'AN-111', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(112, 3, 'AN-112', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(113, 3, 'AN-113', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(114, 3, 'AN-114', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(115, 3, 'AN-115', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(116, 3, 'AN-116', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(117, 3, 'AN-117', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(118, 3, 'AN-118', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(119, 3, 'AN-119', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(120, 3, 'AN-120', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(121, 3, 'AN-121', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(122, 3, 'AN-122', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(123, 3, 'AN-123', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(124, 3, 'AN-124', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(125, 3, 'AN-125', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(126, 3, 'AN-126', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(127, 3, 'AN-127', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(128, 3, 'AN-128', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(129, 3, 'AN-129', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(130, 3, 'AN-130', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(131, 3, 'AN-131', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(132, 3, 'AN-132', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(133, 3, 'AN-133', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(134, 3, 'AN-134', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(135, 3, 'AN-135', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(136, 3, 'AN-136', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(137, 3, 'AN-137', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(138, 3, 'AN-138', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(139, 3, 'AN-139', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(140, 3, 'AN-140', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(141, 3, 'AN-141', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(142, 3, 'AN-142', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(143, 3, 'AN-143', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(144, 3, 'AN-144', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(145, 3, 'AN-145', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(146, 3, 'AN-146', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(147, 3, 'AN-147', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(148, 3, 'AN-148', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(149, 3, 'AN-149', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(150, 3, 'AN-150', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(151, 4, 'BX-101', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(152, 4, 'BX-102', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(153, 4, 'BX-103', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(154, 4, 'BX-104', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(155, 4, 'BX-105', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(156, 4, 'BX-106', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(157, 4, 'BX-107', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(158, 4, 'BX-108', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(159, 4, 'BX-109', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(160, 4, 'BX-110', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(161, 4, 'BX-111', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(162, 4, 'BX-112', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(163, 4, 'BX-113', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(164, 4, 'BX-114', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(165, 4, 'BX-115', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(166, 4, 'BX-116', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(167, 4, 'BX-117', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(168, 4, 'BX-118', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(169, 4, 'BX-119', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(170, 4, 'BX-120', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(171, 4, 'BX-121', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(172, 4, 'BX-122', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(173, 4, 'BX-123', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(174, 4, 'BX-124', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(175, 4, 'BX-125', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(176, 4, 'BX-126', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(177, 4, 'BX-127', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(178, 4, 'BX-128', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(179, 4, 'BX-129', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(180, 4, 'BX-130', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(181, 4, 'BX-131', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(182, 4, 'BX-132', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(183, 4, 'BX-133', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(184, 4, 'BX-134', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(185, 4, 'BX-135', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(186, 4, 'BX-136', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(187, 4, 'BX-137', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(188, 4, 'BX-138', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(189, 4, 'BX-139', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(190, 4, 'BX-140', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(191, 4, 'BX-141', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(192, 4, 'BX-142', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(193, 4, 'BX-143', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(194, 4, 'BX-144', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(195, 4, 'BX-145', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(196, 4, 'BX-146', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(197, 4, 'BX-147', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(198, 4, 'BX-148', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(199, 4, 'BX-149', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11'),
(200, 4, 'BX-150', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-06 08:28:11');

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(10) UNSIGNED NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester_no` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `phase1_start` datetime DEFAULT NULL,
  `phase1_end` datetime DEFAULT NULL,
  `phase2_start` datetime DEFAULT NULL,
  `phase2_end` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `academic_year`, `semester_no`, `name`, `start_date`, `end_date`, `phase1_start`, `phase1_end`, `phase2_start`, `phase2_end`, `is_active`, `created_at`) VALUES
(1, '2026/2027', 1, '2026/2027 · Sem 1', '2026-09-01', '2026-12-20', '2026-11-15 00:00:00', '2026-11-17 23:59:59', '2026-11-18 00:00:00', '2026-11-20 23:59:59', 1, '2026-11-06 09:18:29'),
(2, '2026/2027', 2, '2026/2027 · Sem 2', '2027-01-10', '2027-04-30', NULL, NULL, NULL, NULL, 0, '2026-11-06 09:18:29');

-- --------------------------------------------------------

--
-- Table structure for table `semester_windows`
--

CREATE TABLE `semester_windows` (
  `id` int(10) UNSIGNED NOT NULL,
  `semester_id` int(10) UNSIGNED NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `phase1_start` datetime DEFAULT NULL,
  `phase1_end` datetime DEFAULT NULL,
  `phase2_start` datetime DEFAULT NULL,
  `phase2_end` datetime DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semester_windows`
--

INSERT INTO `semester_windows` (`id`, `semester_id`, `gender`, `phase1_start`, `phase1_end`, `phase2_start`, `phase2_end`, `updated_by`, `updated_at`) VALUES
(1, 1, 'Male', '2026-11-01 00:00:00', '2026-11-02 23:59:00', '2026-11-03 00:00:00', '2026-11-07 23:59:00', 2, '2026-11-06 11:38:00'),
(2, 2, 'Male', NULL, NULL, NULL, NULL, NULL, '2026-11-06 09:37:31'),
(4, 1, 'Female', '2026-11-06 00:00:00', '2026-11-08 23:59:00', '2026-11-11 00:00:00', '2026-11-13 23:59:00', 3, '2026-11-06 10:41:05'),
(5, 2, 'Female', NULL, NULL, NULL, NULL, NULL, '2026-11-06 09:37:39');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `reg_number` varchar(30) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `year_of_study` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `course` varchar(120) DEFAULT NULL,
  `current_room_id` int(10) UNSIGNED DEFAULT NULL,
  `phase1_action` enum('none','keep','release') NOT NULL DEFAULT 'none',
  `placed` tinyint(1) NOT NULL DEFAULT 0,
  `late_booker` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `reg_number`, `full_name`, `email`, `phone`, `gender`, `year_of_study`, `course`, `current_room_id`, `phase1_action`, `placed`, `late_booker`, `is_active`, `created_at`) VALUES
(1, 'UEAB/001', 'John Doe', 'john.doe@student.ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(2, 'UEAB/002', 'Peter Kamau', 'peter.kamau@student.ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(3, 'UEAB/003', 'James Mwangi', 'james.mwangi@student.ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(4, 'UEAB/004', 'Mary Wanjiku', 'mary.wanjiku@student.ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(5, 'UEAB/005', 'Grace Achieng', 'grace.a@student.ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(6, 'UEAB/006', 'Faith Njeri', 'faith.n@student.ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(7, 'SJUNOD2621', 'MESHACK ODONDI', 'sjunod2621@ueab.ac.ke', '0717778146', 'Male', 3, 'SOFTWARE ENGINEER', NULL, 'none', 0, 0, 1, '2026-11-05 21:21:34'),
(8, 'SJUNOD2611', 'MARTIN OUMA', 'odondimeshack1@gmail.com', '0717778146', 'Male', 1, 'SOFTWARE ENGINEER', NULL, 'none', 0, 0, 1, '2026-11-05 21:24:00'),
(49, 'SPETKA2411', 'Peter Kamau', 'spetka2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(50, 'SJAMOU2411', 'James Ouma', 'sjamou2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(51, 'SDAVWE2511', 'David Wekesa', 'sdavwe2511@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(52, 'SSAMNG2521', 'Samuel Ngugi', 'ssamng2521@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(53, 'SDANMW2611', 'Daniel Mwangi', 'sdanmw2611@ueab.ac.ke', NULL, 'Male', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(54, 'SMICBA2411', 'Michael Barasa', 'smicba2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(55, 'SBRIKI2521', 'Brian Kiprop', 'sbriki2521@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(56, 'SKEVMU2611', 'Kevin Mutua', 'skevmu2611@ueab.ac.ke', NULL, 'Male', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(57, 'SCOLOU2411', 'Collins Ouma', 'scolou2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(58, 'SDENNJ2511', 'Dennis Njoroge', 'sdennj2511@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(59, 'SELIKI2621', 'Elijah Kiptoo', 'seliki2621@ueab.ac.ke', NULL, 'Male', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(60, 'SGEOOM2411', 'George Omondi', 'sgeoom2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(61, 'SISAOM2521', 'Isaac Omondi', 'sisaom2521@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(62, 'SJOSOD2611', 'Joseph Odhiambo', 'sjosod2611@ueab.ac.ke', NULL, 'Male', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(63, 'SMARCH2421', 'Mark Chebet', 'smarck2421@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(64, 'SNATKO2511', 'Nathan Koech', 'snatko2511@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(65, 'SPATOM2621', 'Patrick Omolo', 'spatom2621@ueab.ac.ke', NULL, 'Male', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(66, 'SROBKA2411', 'Robert Karanja', 'srobka2411@ueab.ac.ke', NULL, 'Male', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(67, 'SVICON2521', 'Victor Onyango', 'svicon2521@ueab.ac.ke', NULL, 'Male', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(68, 'SMARAC2411', 'Mary Achieng', 'smarac2411@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(69, 'SGRAWA2411', 'Grace Wanjiku', 'sragwa2411@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(70, 'SFAINJ2511', 'Faith Njeri', 'sfainj2511@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(71, 'SESTAN2521', 'Esther Anyango', 'sestan2521@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(72, 'SMERCH2621', 'Mercy Chepkoech', 'smerch2621@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(73, 'SSARMU2611', 'Sarah Muthoni', 'ssarmu2611@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(74, 'SRUTAT2411', 'Ruth Atieno', 'srutat2411@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(75, 'SJOYCHE2421', 'Joy Chebet', 'sjoyche2421@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(76, 'SBEANY2511', 'Beatrice Nyambura', 'sebany2511@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(77, 'SJANKE2521', 'Jane Kemunto', 'sjanke2521@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(78, 'SWINOCH2611', 'Winnie Ochieng', 'swinoch2611@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(79, 'SLYDJE2621', 'Lydia Jepkosgei', 'slydje2621@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(80, 'SPURWA2411', 'Purity Wambui', 'spurwa2411@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(81, 'SHANNA2421', 'Hannah Nasimiyu', 'shanna2421@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(82, 'SROSAK2511', 'Rose Akinyi', 'srosak2511@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(83, 'SNANKI2521', 'Nancy Kilonzo', 'snanki2521@ueab.ac.ke', NULL, 'Female', 2, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(84, 'SCYMU2621', 'Cynthia Mutheu', 'scymu2621@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(85, 'SRACHA2611', 'Rachel Kalekye', 'sracha2611@ueab.ac.ke', NULL, 'Female', 3, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(86, 'SNAOAU2411', 'Naomi Auma', 'snaoau2411@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37'),
(87, 'SABINE2421', 'Abigail Nekesa', 'sabine2421@ueab.ac.ke', NULL, 'Female', 1, NULL, NULL, 'none', 0, 0, 1, '2026-11-06 08:38:37');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `role` enum('admin','dean_men','dean_women','secretary_men','secretary_women') NOT NULL,
  `gender_scope` enum('Male','Female') DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `gender_scope`, `phone`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'odondijunior.ke@gmail.com', '$2y$10$skZrWhXl1zNkwVKvo5hThe89UAeR0nsGNmgd.TDX9Y6ir7UU99O/2', 'System Administrator', 'admin', NULL, NULL, 1, '2026-11-06 11:35:54', '2026-11-05 11:09:00', '2026-11-06 11:35:54'),
(2, 'dean_m', 'deanm@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Dean of Men', 'dean_men', 'Male', NULL, 1, '2026-11-06 11:37:05', '2026-11-05 11:09:00', '2026-11-06 11:37:05'),
(3, 'dean_w', 'deanw@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Dean of Women', 'dean_women', 'Female', NULL, 1, '2026-11-06 10:56:51', '2026-11-05 11:09:00', '2026-11-06 10:56:51'),
(4, 'sec_m', 'secm@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Secretary (Men)', 'secretary_men', 'Male', NULL, 1, '2026-11-06 11:34:46', '2026-11-05 11:09:00', '2026-11-06 11:34:46'),
(5, 'sec_w', 'secw@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Secretary (Women)', 'secretary_women', 'Female', NULL, 1, '2026-11-06 09:05:54', '2026-11-05 11:09:00', '2026-11-06 09:05:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_semester` (`semester_id`);

--
-- Indexes for table `blocks`
--
ALTER TABLE `blocks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mpesa_ref` (`mpesa_ref`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_bookings_status` (`status`),
  ADD KEY `idx_bookings_student` (`student_id`),
  ADD KEY `idx_bookings_semester` (`semester_id`);

--
-- Indexes for table `config`
--
ALTER TABLE `config`
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `magic_links`
--
ALTER TABLE `magic_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_magic_student` (`student_id`),
  ADD KEY `idx_magic_semester` (`semester_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr_user` (`user_id`),
  ADD KEY `idx_pr_expires` (`expires_at`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_block_room` (`block_id`,`room_number`),
  ADD KEY `idx_rooms_status` (`status`),
  ADD KEY `idx_rooms_gender` (`gender`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_year_sem` (`academic_year`,`semester_no`);

--
-- Indexes for table `semester_windows`
--
ALTER TABLE `semester_windows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sem_gender` (`semester_id`,`gender`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reg_number` (`reg_number`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `current_room_id` (`current_room_id`),
  ADD KEY `idx_students_gender` (`gender`),
  ADD KEY `idx_students_placed` (`placed`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=145;

--
-- AUTO_INCREMENT for table `blocks`
--
ALTER TABLE `blocks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `magic_links`
--
ALTER TABLE `magic_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=201;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `semester_windows`
--
ALTER TABLE `semester_windows`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `bookings_semester_fk` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `magic_links`
--
ALTER TABLE `magic_links`
  ADD CONSTRAINT `magic_links_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `magic_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `magic_links_semester_fk` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `pr_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`block_id`) REFERENCES `blocks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `semester_windows`
--
ALTER TABLE `semester_windows`
  ADD CONSTRAINT `sem_win_fk` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`current_room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
