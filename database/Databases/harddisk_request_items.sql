-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 09:10 AM
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
-- Table structure for table `harddisk_request_items`
--

CREATE TABLE `harddisk_request_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL COMMENT 'อ้างอิงคำขอจาก harddisk_delivery_requests',
  `hdd_inventory_id` int(11) DEFAULT NULL COMMENT 'อ้างอิง HDD จาก harddisk_inventory',
  `hdd_serial` varchar(100) NOT NULL COMMENT 'Serial HDD ที่ยิงบาร์โค้ด',
  `scan_status` enum('matched','removed','cancelled') NOT NULL DEFAULT 'matched',
  `scanned_by` varchar(100) NOT NULL COMMENT 'ผู้ยิงบาร์โค้ด',
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'วันเวลาที่ยิงบาร์โค้ด',
  `removed_by` varchar(100) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `remove_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `harddisk_request_items`
--
ALTER TABLE `harddisk_request_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_request_hdd` (`request_id`,`hdd_serial`),
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `idx_hdd_serial` (`hdd_serial`),
  ADD KEY `idx_scan_status` (`scan_status`),
  ADD KEY `idx_scanned_at` (`scanned_at`),
  ADD KEY `fk_hdd_request_items_inventory` (`hdd_inventory_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `harddisk_request_items`
--
ALTER TABLE `harddisk_request_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `harddisk_request_items`
--
ALTER TABLE `harddisk_request_items`
  ADD CONSTRAINT `fk_hdd_request_items_inventory` FOREIGN KEY (`hdd_inventory_id`) REFERENCES `harddisk_inventory` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_hdd_request_items_request` FOREIGN KEY (`request_id`) REFERENCES `harddisk_delivery_requests` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
