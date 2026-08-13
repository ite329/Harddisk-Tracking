-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 09:09 AM
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
-- Table structure for table `harddisk_delivery_requests`
--

CREATE TABLE `harddisk_delivery_requests` (
  `id` int(11) NOT NULL,
  `hdd_inventory_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'อ้างอิง HDD จากคลัง',
  `hdd_serial` varchar(100) DEFAULT NULL COMMENT 'Serial HDD ที่ตัดจาก Stock',
  `request_no` varchar(50) NOT NULL COMMENT 'เลขที่คำขอ เช่น HDD202606290001',
  `main_branch_code` varchar(20) NOT NULL COMMENT 'รหัสสาขาใหญ่',
  `branch_code` varchar(20) NOT NULL COMMENT 'รหัสสาขา',
  `branch_name` varchar(255) NOT NULL COMMENT 'ชื่อสาขา',
  `request_reason` varchar(255) DEFAULT NULL COMMENT 'สาเหตุที่ต้องส่ง HDD เช่น HDD เสีย, เปลี่ยนทดแทน',
  `remark` text DEFAULT NULL COMMENT 'หมายเหตุจากผู้แจ้ง',
  `status` enum('pending_scan','matched','shipped','cancelled') NOT NULL DEFAULT 'pending_scan' COMMENT 'สถานะคำขอ',
  `requested_by` varchar(100) NOT NULL COMMENT 'ผู้บันทึกข้อมูล',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'วันเวลาที่ User กรอกข้อมูล',
  `matched_by` varchar(100) DEFAULT NULL COMMENT 'ผู้ยิงบาร์โค้ดจับคู่',
  `matched_at` datetime DEFAULT NULL COMMENT 'วันเวลาที่จับคู่ HDD',
  `shipped_by` varchar(100) DEFAULT NULL COMMENT 'ผู้ยืนยันจัดส่ง',
  `shipped_at` datetime DEFAULT NULL COMMENT 'วันเวลาจัดส่ง',
  `cancelled_by` varchar(100) DEFAULT NULL COMMENT 'ผู้ยกเลิก',
  `cancelled_at` datetime DEFAULT NULL COMMENT 'วันเวลายกเลิก',
  `cancel_reason` varchar(255) DEFAULT NULL COMMENT 'เหตุผลยกเลิก',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `harddisk_delivery_requests`
--

INSERT INTO `harddisk_delivery_requests` (`id`, `hdd_inventory_id`, `hdd_serial`, `request_no`, `main_branch_code`, `branch_code`, `branch_name`, `request_reason`, `remark`, `status`, `requested_by`, `requested_at`, `matched_by`, `matched_at`, `shipped_by`, `shipped_at`, `cancelled_by`, `cancelled_at`, `cancel_reason`, `created_at`, `updated_at`, `deleted_at`) VALUES
(23, NULL, NULL, 'HDD202607070001', '127', '2004808', '(ศ.2) ศูนย์ฯ ท่าทราย (นครปฐม) (ย.6)', 'HDD เสีย', 'เปลี่ยนอะแดปเตอร์แล้ว HDD เสีย เบิก HDD ส่งให้สาขา', 'pending_scan', 'นายณรงค์เดช แสนทวีสุข', '2026-07-07 09:09:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-07 04:09:24', '2026-07-07 04:09:24', NULL),
(24, NULL, NULL, 'HDD202607070002', '274', '2002997', '(ศ.5) ศูนย์ฯ เกาะใหญ่ (ย.3)', 'HDD เสีย', 'เบิก HDD ส่งให้สาขา', 'pending_scan', 'นายณรงค์เดช แสนทวีสุข', '2026-07-07 09:45:23', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-07-07 04:45:23', '2026-07-07 04:45:23', NULL),
(25, 289, 'WWD2FN9L', 'HDD202607070003', '148', '2000737', 'คำปิง', 'HDD เสีย', 'คนรับงาน : นายณรงค์เดช แสนทวีสุข', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 10:48:41', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 10:48:54', NULL, NULL, NULL, NULL, NULL, '2026-07-07 05:48:41', '2026-07-07 10:48:54', NULL),
(26, 322, 'WWD2FF5L', 'HDD202607070004', '006', '2000670', '(ย่อย 1) บ้านโคก', 'HDD เสีย', 'คนรับงาน : นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 10:51:41', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 10:53:22', NULL, NULL, NULL, NULL, NULL, '2026-07-07 05:51:41', '2026-07-07 10:53:22', NULL),
(27, 286, 'WWD2FF5W', 'HDD202607070005', '147', '2003117', '(ย่อย 7) ภูสิงห์ 2', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 10:53:56', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 10:53:58', NULL, NULL, NULL, NULL, NULL, '2026-07-07 05:53:56', '2026-07-07 10:53:58', NULL),
(28, 290, 'WWD2FF44', 'HDD202607070006', '113', '2001945', '(ย่อย 8) ห้วยกระบอก', 'HDD เสีย', 'นาย นิธิศ สีหะคลัง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 10:54:59', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 10:55:02', NULL, NULL, NULL, NULL, NULL, '2026-07-07 05:54:59', '2026-07-07 10:55:02', NULL),
(29, 291, 'WWD2FN9M', 'HDD202607070007', '339', '2000905', '(ย่อย 6) โพนทอง', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 10:55:43', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 10:55:49', NULL, NULL, NULL, NULL, NULL, '2026-07-07 05:55:43', '2026-07-07 10:55:49', NULL),
(30, 292, 'WWD2FF5V', 'HDD202607070008', '314', '2008517', '(ศ.1) ศูนย์ฯ บ้านไผ่ใหญ่ (ย.10)', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:15:05', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:15:13', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:15:05', '2026-07-07 11:15:49', NULL),
(31, 288, 'WWD2FN9T', 'HDD202607070009', '010', '2000387', '(ย่อย 8) หมู่บ้านรักไทย', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:16:06', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:16:08', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:16:06', '2026-07-07 11:16:08', NULL),
(32, 287, 'WWD2FF5X', 'HDD202607070010', '012', '2005903', '(ศ.1) ศูนย์ฯ บ้านโพธิ์ตะวันตก (ย.2)', 'HDD เสีย', 'นายณรงค์เดช แสนทวีสุข', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:16:24', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:16:27', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:16:24', '2026-07-07 11:16:27', NULL),
(33, 299, 'WWD2FN9Y', 'HDD202607070011', '323', '2002082', '(ย่อย 6) โพธิ์ศรีสุวรรณ', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:16:53', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:16:55', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:16:53', '2026-07-07 11:16:55', NULL),
(34, 321, 'WWD2FN5D', 'HDD202607070012', '041', '2000008', '(ย่อย 11) วัดตายม', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:17:18', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:17:26', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:17:18', '2026-07-07 11:17:26', NULL),
(35, 283, 'WWD2FNAD', 'HDD202607070013', '043', '2001009', '(ย่อย 5) สามแยกแตงโม', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:17:42', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:17:45', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:17:42', '2026-07-07 11:17:45', NULL),
(36, 284, 'WWD2FNAW', 'HDD202607070014', '207', '2005151', '(ย่อย 11) บางพูน', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:18:03', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:18:06', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:18:03', '2026-07-07 11:18:06', NULL),
(37, 285, 'WWD2FN5Q', 'HDD202607070015', '266', '2005490', '(ย่อย 1) ยางชุม (กลัดหลวง)', 'HDD เสีย', 'นายณรงค์เดช แสนทวีสุข', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:18:24', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:18:27', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:18:24', '2026-07-07 11:18:27', NULL),
(38, 293, 'WWD2FN99', 'HDD202607070016', '329', '2001779', '(ย่อย 12) ถนนแจ้งสนิท - อุบลฯ', 'HDD เสีย', 'นายวันชัย ระมั่ง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:18:46', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:18:49', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:18:46', '2026-07-07 11:18:49', NULL),
(39, 294, 'WWD2FN9A', 'HDD202607070017', '332', '2001780', '(ย่อย 16) หน้าสถานีรถไฟห้วยแถลง', 'HDD เสีย', 'นายวันชัย ระมั่ง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:19:15', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:19:17', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:19:15', '2026-07-07 11:19:17', NULL),
(40, 295, 'WWD2FNAV', 'HDD202607070018', '069', '2001138', '(ย่อย 8) ชำนิ', 'HDD เสีย', 'นายวันชัย ระมั่ง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:19:32', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:19:35', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:19:32', '2026-07-07 11:19:35', NULL),
(41, 296, 'WWD2FF5K', 'HDD202607070019', '376', '2004288', '(ศ.6) ศูนย์ฯ ชุมชนวัดโสมนัส (ย.11)', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:19:55', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:20:17', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:19:55', '2026-07-07 11:20:17', NULL),
(42, 297, 'WWD2FN5H', 'HDD202607070020', '181', '2001625', '(ย่อย 2) บางขัน', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:20:34', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:20:36', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:20:34', '2026-07-07 11:20:36', NULL),
(43, 298, 'WWD2FN63', 'HDD202607070021', '336', '2008499', '(ศ.6) ศูนย์ฯ บ้านยายดา (ย.1)', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:21:02', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:21:05', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:21:02', '2026-07-07 11:21:05', NULL),
(44, 300, 'WWD2FNAJ', 'HDD202607070022', '318', '2001106', 'ครบุรี', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:21:23', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:21:26', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:21:23', '2026-07-07 11:21:26', NULL),
(45, 301, 'WWD2FN91', 'HDD202607070023', '060', '2000455', 'โคกสำโรง', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:21:42', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:21:44', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:21:42', '2026-07-07 11:21:44', NULL),
(46, 302, 'WWD2FN8T', 'HDD202607070024', '246', '2008406', '(ศ.15) ศูนย์ฯ บ้านบะฮี (ย.4)', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:22:08', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 11:22:09', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:22:08', '2026-07-07 11:22:09', NULL),
(47, 275, 'WWD2FND0', 'HDD202607070025', '230', '2005269', '(ย่อย 13) ถนนเรืองฤทธิ์จรูญ', 'HDD เสีย', 'นาย อานันท์ โนนดงกลาง', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:22:26', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 13:26:53', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:22:26', '2026-07-07 13:26:53', NULL),
(48, 273, 'WWD2FN9Q', 'HDD202607070026', '041', '2005006', '(ศ.9) ศูนย์ฯ ถนนวิสุทธิ์กษัตริย์ (ย.5)', 'HDD เสีย', 'นาย สุริยา อุ่นใจ', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:22:41', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 13:26:46', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:22:41', '2026-07-07 13:26:46', NULL),
(49, 274, 'WWD2FN4L', 'HDD202607070027', '205', '2003993', '(ย่อย 1) นาคำ', 'HDD เสีย', 'นายกฤษณะ พันชื่น', 'matched', 'นายกฤษติพงษ์ ภูดินดง', '2026-07-07 11:22:54', 'นายกฤษติพงษ์ ภูดินดง (14329)', '2026-07-07 13:26:37', NULL, NULL, NULL, NULL, NULL, '2026-07-07 06:22:54', '2026-07-07 13:26:37', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `harddisk_delivery_requests`
--
ALTER TABLE `harddisk_delivery_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_request_no` (`request_no`),
  ADD KEY `idx_main_branch_code` (`main_branch_code`),
  ADD KEY `idx_branch_code` (`branch_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requested_at` (`requested_at`),
  ADD KEY `idx_deleted_at` (`deleted_at`),
  ADD KEY `idx_requests_hdd_inventory_id` (`hdd_inventory_id`),
  ADD KEY `idx_requests_hdd_serial` (`hdd_serial`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `harddisk_delivery_requests`
--
ALTER TABLE `harddisk_delivery_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
