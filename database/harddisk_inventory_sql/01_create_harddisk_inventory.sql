-- Create table for Harddisk Inventory
-- Compatible with MySQL 5.7 / XAMPP / phpMyAdmin

CREATE TABLE IF NOT EXISTS `harddisk_inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_d_id` INT UNSIGNED NULL COMMENT 'รหัสเดิมจากตาราง diy.d_id',
  `hdd_serial` VARCHAR(100) NOT NULL COMMENT 'Serial HDD จาก diy.d_sn',
  `received_date` DATE NULL COMMENT 'วันที่รับเข้า/วันที่เพิ่มเข้าคลัง จาก diy.d_day',
  `status` ENUM('available','reserved','shipped','damaged','cancelled') NOT NULL DEFAULT 'available' COMMENT 'สถานะ HDD ในคลัง',
  `remark` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_hdd_serial` (`hdd_serial`),
  KEY `idx_inventory_source_d_id` (`source_d_id`),
  KEY `idx_inventory_received_date` (`received_date`),
  KEY `idx_inventory_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
