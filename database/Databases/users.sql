-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 09:11 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `harddisk_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `employee_code` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `position_name` varchar(255) DEFAULT NULL COMMENT 'ตำแหน่ง',
  `password_hash` varchar(255) NOT NULL COMMENT 'รหัสผ่านแบบเข้ารหัส',
  `role` enum('admin','it_staff','viewer') NOT NULL DEFAULT 'it_staff' COMMENT 'สิทธิ์การใช้งาน',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'สถานะการใช้งาน 1=ใช้งาน, 0=ปิดใช้งาน',
  `last_login_at` datetime DEFAULT NULL COMMENT 'วันที่ Login ล่าสุด',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `employee_code`, `first_name`, `last_name`, `position_name`, `password_hash`, `role`, `is_active`, `last_login_at`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, '14329', 'นายกฤษติพงษ์', 'ภูดินดง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$6zM.pl447V3TB3ejETI6huhORldJpXr0SCcdv6vInRpeCpR9Y0Szi', 'admin', 1, '2026-07-07 11:40:12', '2026-06-29 11:48:13', '2026-06-30 08:44:24', NULL),
(2, '00106', 'นายวิโรจน์', 'ลอยทับเลิศ', 'ผู้ช่วยผู้จัดการฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, '2026-06-29 12:01:36', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(3, '00830', 'นายบุณยกร', 'แก้วแสนตอ', 'หัวหน้าส่วนฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NULL, '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(4, '01395', 'นายชานนท์', 'สีหะวงษ์', 'หัวหน้าส่วนฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$rEXFSFDmcfwhicUqQobANOrzwhEEqcu1yKANQTD/mpuklHWYkiTRe', 'it_staff', 1, '2026-07-06 10:12:06', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(5, '06836', 'นายวันชัย', 'ระมั่ง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NULL, '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(6, '09404', 'นายวัชระ', 'แย้มกร', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$pnHIMEaM3I2ZOMiHdQUiIeAPE2ZOgB0LZ3TbEcU2v.RV6PXTJ07ye', 'it_staff', 1, '2026-06-30 16:18:26', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(7, '10057', 'นายสุริยา', 'อุ่นใจ', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$rk0QRRVJPovUCLvt8ziECeSYa.jbkN9yS2OT9UonpHjKDafSRE62m', 'it_staff', 1, '2026-07-07 11:38:51', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(8, '15170', 'นายอานันท์', 'โนนดงกลาง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NULL, '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(9, '16470', 'นายไชยมงคล', 'เข็มทอง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NULL, '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(10, '17059', 'นายนิธิศ', 'สีหะคลัง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NULL, '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(12, '19630', 'นายลัทธกิตต์', 'บัววิเชียร', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$iDw.O4M8Kygsu4g04l0ui.dN.UTy28JtG4DyEdYnbILCnAaJhw/ja', 'it_staff', 1, '2026-07-06 14:43:52', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(13, '21245', 'นายเสกสรร', 'จันทร์มาก', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$ma/JmC09JmthdpsSkkf8NOxRkCiH7Ekw5GmXer3MdZQgU/IutPCMe', 'it_staff', 1, '2026-07-06 15:00:34', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(14, '28493', 'นายกฤษณะ', 'พันชื่น', 'พนักงานเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$10$TjE9UgasN7mTGX6.mama1eS0tNCa.uq11ZNPVjwYW9wWUkCUzUOgO', 'it_staff', 1, '2026-07-07 10:55:03', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(15, '29762', 'นายฐานิต', 'ฉันทะประเสริฐ', 'พนักงานเทคโนโลยีสารสนเทศ (Technical Support)', '$2y$10$r0.u8dYSN5p.k.JXEX8vK.VIymDtemBMZ7fc3gXUwQ987Qkr2sQOS', 'it_staff', 1, '2026-07-06 15:02:20', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(16, '29761', 'นายณรงค์เดช', 'แสนทวีสุข', 'พนักงานเทคโนโลยีสารสนเทศ (Technical Support)', '$2y$10$RAWCWYoglgQb6FnO/mr6bO9xfM2.X/HPGwcpNpyIskEH8DN3f3DOS', 'it_staff', 1, '2026-07-06 15:34:42', '2026-06-29 12:00:35', '2026-06-30 08:44:32', NULL),
(32, '100001', 'ผู้ดูแล', 'ระบบ', NULL, '$2y$10$JPwoY0ks74De06SxaMW42Ovdpaq3EDuz8tnkzZCxuYTS3HZCIYnvm', 'admin', 1, '2026-06-30 16:12:44', '2026-06-30 09:53:00', '2026-06-30 09:53:39', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_employee_code` (`employee_code`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
