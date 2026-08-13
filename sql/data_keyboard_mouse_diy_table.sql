CREATE TABLE IF NOT EXISTS `keyboard_mouse_diy` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_code` VARCHAR(50) NULL,
  `branch_name` VARCHAR(180) NULL,
  `keyboard_qty` INT NULL DEFAULT 0,
  `mouse_qty` INT NULL DEFAULT 0,
  `status` VARCHAR(80) NULL,
  `remark` TEXT NULL,
  `created_by` VARCHAR(180) NULL,
  `updated_by` VARCHAR(180) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_branch_code` (`branch_code`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
