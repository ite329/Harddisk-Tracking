CREATE TABLE IF NOT EXISTS `wcs_repair_quotes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repair_job_no` VARCHAR(100) NOT NULL,
  `quote_date` DATE NOT NULL,
  `branch_name` VARCHAR(255) NOT NULL,
  `asset_code` VARCHAR(100) NOT NULL,
  `printer_model` VARCHAR(150) DEFAULT NULL,
  `serial_number` VARCHAR(150) DEFAULT NULL,
  `remark` TEXT DEFAULT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 7.00,
  `vat_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wcs_repair_job_no` (`repair_job_no`),
  KEY `idx_wcs_quote_date` (`quote_date`),
  KEY `idx_wcs_branch_name` (`branch_name`),
  KEY `idx_wcs_asset_code` (`asset_code`),
  KEY `idx_wcs_printer_model` (`printer_model`),
  KEY `idx_wcs_serial_number` (`serial_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wcs_repair_quote_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_id` INT UNSIGNED NOT NULL,
  `product_code` VARCHAR(100) NOT NULL,
  `repair_description` TEXT NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wcs_quote_items_quote_id` (`quote_id`),
  CONSTRAINT `fk_wcs_quote_items_quote` FOREIGN KEY (`quote_id`) REFERENCES `wcs_repair_quotes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wcs_repair_quote_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_id` INT UNSIGNED NOT NULL,
  `sheet_name` VARCHAR(100) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `source_file_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wcs_quote_attachments_quote_id` (`quote_id`),
  KEY `idx_wcs_quote_attachments_sheet_name` (`sheet_name`),
  CONSTRAINT `fk_wcs_quote_attachments_quote` FOREIGN KEY (`quote_id`) REFERENCES `wcs_repair_quotes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

