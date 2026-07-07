-- branch_directory table for Harddisk Delivery Web
-- Source: 06-Costcenter_June2026.xlsx / sheet: รวมทุกเขต
-- MySQL 5.7 compatible
-- Import in your existing database, for example: harddisk_delivery_web

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `branch_directory`;

CREATE TABLE `branch_directory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `main_branch_code` VARCHAR(20) NULL COMMENT 'รหัสสาขา',
  `branch_code` VARCHAR(20) NOT NULL COMMENT 'Cost Center',
  `branch_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อสาขา',
  `branch_name_2` VARCHAR(255) NULL COMMENT 'ชื่อสาขา_2',
  `full_address` TEXT NULL COMMENT 'ที่อยู่เต็ม',
  `phone` VARCHAR(50) NULL COMMENT 'เบอร์โทรศัพท์',
  `landmark` VARCHAR(500) NULL COMMENT 'สถานที่ใกล้เคียง',
  `area_code` VARCHAR(20) NULL COMMENT 'สังกัดเขต',
  `hierarchy_area` VARCHAR(50) NULL COMMENT 'Hierarchy area',
  `address_line` VARCHAR(500) NULL COMMENT 'บ้านเลขที่/หมู่ที่/ซอย/ถนน',
  `subdistrict` VARCHAR(150) NULL COMMENT 'ตำบล/แขวง',
  `district` VARCHAR(150) NULL COMMENT 'อำเภอ/เขต',
  `province` VARCHAR(150) NULL COMMENT 'จังหวัด',
  `postal_code` VARCHAR(10) NULL COMMENT 'รหัสไปรษณีย์',
  `bot_registered_date` DATE NULL COMMENT 'ว/ด/ป ธปท. ค.ศ.',
  `opening_date` DATE NULL COMMENT 'ว/ด/ป ทำการ ค.ศ.',
  `dbd_registration_no` VARCHAR(100) NULL COMMENT 'ลำดับจดทะเบียนกรมพัฒฯ',
  `latitude` DECIMAL(12,8) NULL COMMENT 'ละติจูด',
  `longitude` DECIMAL(12,8) NULL COMMENT 'ลองจิจูด',
  `payment_machine_no` VARCHAR(100) NULL COMMENT 'หมายเลขประจำเครื่องชำระเงิน',
  `ptd20_registered_date` DATE NULL COMMENT 'วันที่จดทะเบียน ภธ.20',
  `pp20_registered_date` DATE NULL COMMENT 'วันที่จดทะเบียน ภพ.20',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `source_file` VARCHAR(255) NULL DEFAULT '06-Costcenter_June2026.xlsx',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_directory_branch_code` (`branch_code`),
  KEY `idx_branch_directory_main_branch_code` (`main_branch_code`),
  KEY `idx_branch_directory_branch_name` (`branch_name`),
  KEY `idx_branch_directory_area_code` (`area_code`),
  KEY `idx_branch_directory_hierarchy_area` (`hierarchy_area`),
  KEY `idx_branch_directory_province` (`province`),
  KEY `idx_branch_directory_opening_date` (`opening_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
