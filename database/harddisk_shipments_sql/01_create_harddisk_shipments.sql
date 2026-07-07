-- Generated from: รายงาน HDD ที่ส่งให้สาขาแล้ว.xlsx
-- Table: harddisk_shipments
-- Compatible: MySQL 5.7 / MariaDB / XAMPP
-- Rows: 1861

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `harddisk_shipments`;
CREATE TABLE `harddisk_shipments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seq_no` INT UNSIGNED NULL COMMENT 'ลำดับจากไฟล์ Excel',
  `main_branch_code` VARCHAR(20) NULL COMMENT 'รหัสสาขา',
  `branch_code` VARCHAR(20) NULL COMMENT 'Cost Center',
  `branch_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อสาขา',
  `hdd_serial` VARCHAR(100) NULL COMMENT 'SN_HDD',
  `shipped_date` DATE NULL COMMENT 'วันที่ส่งให้สาขา',
  `reported_by` VARCHAR(255) NULL COMMENT 'คนแจ้ง',
  `shipment_status` ENUM('sent','received','installed','cancelled') NOT NULL DEFAULT 'sent' COMMENT 'สถานะการจัดส่ง',
  `remark` TEXT NULL COMMENT 'หมายเหตุ',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shipments_main_branch_code` (`main_branch_code`),
  KEY `idx_shipments_branch_code` (`branch_code`),
  KEY `idx_shipments_branch_name` (`branch_name`),
  KEY `idx_shipments_hdd_serial` (`hdd_serial`),
  KEY `idx_shipments_shipped_date` (`shipped_date`),
  KEY `idx_shipments_status` (`shipment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ตรวจสอบจำนวนรายการหลัง Import
-- SELECT COUNT(*) AS total_rows FROM harddisk_shipments;
-- SELECT branch_name, COUNT(*) AS total FROM harddisk_shipments GROUP BY branch_name HAVING COUNT(*) > 1 ORDER BY total DESC;
