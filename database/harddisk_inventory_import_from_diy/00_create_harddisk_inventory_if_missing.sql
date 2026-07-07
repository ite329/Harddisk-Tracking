-- Create harddisk_inventory table if it does not exist
-- Based on uploaded harddisk_inventory.sql structure

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE IF NOT EXISTS `harddisk_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hdd_serial` varchar(100) NOT NULL COMMENT 'Serial HDD / Barcode HDD',
  `status` enum('available','reserved','shipped','used','damaged','cancelled') NOT NULL DEFAULT 'available' COMMENT 'สถานะ HDD',
  `scanned_by` varchar(100) DEFAULT NULL,
  `scanned_at` datetime DEFAULT NULL,
  `received_from` varchar(255) DEFAULT NULL COMMENT 'รับมาจากที่ใด',
  `received_at` datetime DEFAULT NULL COMMENT 'วันที่รับเข้า',
  `remark` text DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hdd_serial` (`hdd_serial`),
  KEY `idx_status` (`status`),
  KEY `idx_received_at` (`received_at`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
