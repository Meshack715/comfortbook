-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2026 at 03:22 PM
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
  `action` varchar(60) NOT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `student_id`, `action`, `details`, `ip`, `user_agent`, `created_at`) VALUES
(1, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:19:24'),
(2, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:40:06'),
(3, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 11:53:37'),
(4, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:02:32'),
(5, 2, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:02:42'),
(6, 2, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:07'),
(7, 3, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:16'),
(8, 3, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:03:31'),
(9, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:07:05'),
(10, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:08:26'),
(11, 2, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:08:36'),
(12, 2, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:09:13'),
(13, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:09:21'),
(14, 1, NULL, 'demo_reset', 'Demo reset', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:10:32'),
(15, 1, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:10:57'),
(16, 1, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:11:10'),
(17, 1, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:11:49'),
(18, 1, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:12'),
(19, 1, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:42'),
(20, 1, NULL, 'phase1_start', 'Phase 1 started', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:45'),
(21, 1, NULL, 'phases_close', 'All phases closed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:12:48'),
(22, 1, NULL, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:23:07'),
(23, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:24:23'),
(24, 4, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:25:38'),
(25, 4, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:45:35'),
(26, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:49:34'),
(27, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:54:48'),
(28, 2, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:54:59'),
(29, 2, NULL, 'pdf_download', 'Downloaded pdf: students', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:56:23'),
(30, 2, NULL, 'csv_download', 'Downloaded csv: unplaced', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:56:38'),
(31, 2, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:58:47'),
(32, 4, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 12:58:58'),
(33, 4, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:00:40'),
(34, 1, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:00:48'),
(35, 1, NULL, 'block_edit', 'Edited block #1: New Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:02:24'),
(36, 1, NULL, 'block_edit', 'Edited block #1: New Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:10'),
(37, 1, NULL, 'block_edit', 'Edited block #2: Old Men', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:28'),
(38, 1, NULL, 'block_edit', 'Edited block #3: Annex', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:03:50'),
(39, 1, NULL, 'block_edit', 'Edited block #4: Box', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:04:03'),
(40, 1, NULL, 'pdf_download', 'Downloaded pdf: staff', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:11:42'),
(41, 1, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:15:19'),
(42, 5, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:16:11'),
(43, 5, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:17:15'),
(44, 4, NULL, 'login', 'Successful login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:17:25'),
(45, 4, NULL, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-05 13:19:39');

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
('auto_keep_on_timeout', '1', '2026-11-05 11:08:21'),
('otp_ttl_minutes', '10', '2026-11-05 11:08:21'),
('penalty_amount', '200', '2026-11-05 11:08:21'),
('phase1_end', '2026-11-04 12:12:48', '2026-11-05 12:12:48'),
('phase1_start', '2026-11-05 12:12:45', '2026-11-05 12:12:45'),
('phase2_end', '2026-11-04 12:12:48', '2026-11-05 12:12:48'),
('phase2_start', '2026-11-08 00:00:00', '2026-11-05 12:10:56'),
('show_otp_in_ui', '1', '2026-11-05 11:08:21');

-- --------------------------------------------------------

--
-- Table structure for table `magic_links`
--

CREATE TABLE `magic_links` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(1, 1, 'A-101', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(2, 1, 'A-102', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(3, 1, 'A-103', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(4, 2, 'B-101', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(5, 2, 'B-102', 4, 'Male', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(6, 3, 'C-101', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(7, 3, 'C-102', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(8, 3, 'C-103', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(9, 4, 'D-101', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50'),
(10, 4, 'D-102', 4, 'Female', 'vacant', NULL, NULL, NULL, '2026-11-05 11:08:50');

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
(1, 'UEAB/001', 'John Doe', 'john.doe@student.ueab.ac.ke', NULL, 'Male', 2, NULL, 1, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(2, 'UEAB/002', 'Peter Kamau', 'peter.kamau@student.ueab.ac.ke', NULL, 'Male', 2, NULL, 4, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(3, 'UEAB/003', 'James Mwangi', 'james.mwangi@student.ueab.ac.ke', NULL, 'Male', 1, NULL, 5, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(4, 'UEAB/004', 'Mary Wanjiku', 'mary.wanjiku@student.ueab.ac.ke', NULL, 'Female', 2, NULL, 6, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(5, 'UEAB/005', 'Grace Achieng', 'grace.a@student.ueab.ac.ke', NULL, 'Female', 2, NULL, 9, 'none', 0, 0, 1, '2026-11-05 11:09:33'),
(6, 'UEAB/006', 'Faith Njeri', 'faith.n@student.ueab.ac.ke', NULL, 'Female', 1, NULL, 10, 'none', 0, 0, 1, '2026-11-05 11:09:33');

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
(1, 'admin', 'admin@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'System Administrator', 'admin', NULL, NULL, 1, '2026-11-05 13:00:48', '2026-11-05 11:09:00', '2026-11-05 13:00:48'),
(2, 'dean_m', 'deanm@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Dean of Men', 'dean_men', 'Male', NULL, 1, '2026-11-05 12:54:59', '2026-11-05 11:09:00', '2026-11-05 12:54:59'),
(3, 'dean_w', 'deanw@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Dean of Women', 'dean_women', 'Female', NULL, 1, '2026-11-05 12:03:16', '2026-11-05 11:09:00', '2026-11-05 12:03:16'),
(4, 'sec_m', 'secm@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Secretary (Men)', 'secretary_men', 'Male', NULL, 1, '2026-11-05 13:17:25', '2026-11-05 11:09:00', '2026-11-05 13:17:25'),
(5, 'sec_w', 'secw@ueab.ac.ke', '$2y$10$XGBmjK7Nly0qwD3Q1cJdseHiPYZXUD0yxOc9eSghURv2NPoMJ8er.', 'Secretary (Women)', 'secretary_women', 'Female', NULL, 1, '2026-11-05 13:16:11', '2026-11-05 11:09:00', '2026-11-05 13:16:11');

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
  ADD KEY `idx_audit_created` (`created_at`);

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
  ADD KEY `idx_bookings_student` (`student_id`);

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
  ADD KEY `idx_magic_student` (`student_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_block_room` (`block_id`,`room_number`),
  ADD KEY `idx_rooms_status` (`status`),
  ADD KEY `idx_rooms_gender` (`gender`);

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `blocks`
--
ALTER TABLE `blocks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `magic_links`
--
ALTER TABLE `magic_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `magic_links`
--
ALTER TABLE `magic_links`
  ADD CONSTRAINT `magic_links_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `magic_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`block_id`) REFERENCES `blocks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`current_room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
