CREATE TABLE IF NOT EXISTS `delete_computer` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `new_computer_name` VARCHAR(150) NOT NULL DEFAULT '',
  `old_computer_name` VARCHAR(150) NULL,
  `created_by` VARCHAR(180) NULL,
  `updated_by` VARCHAR(180) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_new_computer_name` (`new_computer_name`),
  KEY `idx_old_computer_name` (`old_computer_name`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
