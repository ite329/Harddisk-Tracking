CREATE TABLE IF NOT EXISTS `new_branch_it_users` (
  `employee_code` VARCHAR(50) NOT NULL,
  `display_name` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`employee_code`),
  KEY `idx_new_branch_it_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `new_branch_it_users` (`employee_code`, `display_name`, `is_active`)
VALUES ('06836', NULL, 1)
ON DUPLICATE KEY UPDATE `is_active` = 1;

CREATE TABLE IF NOT EXISTS `new_branch_import_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_filename` VARCHAR(255) NOT NULL,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(255) NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_new_branch_import_batches_uploaded_at` (`uploaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `new_branch_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `source_no` VARCHAR(100) NULL,
  `area_name` VARCHAR(255) NULL,
  `branch_name` VARCHAR(255) NOT NULL,
  `iplan` VARCHAR(255) NULL,
  `plan_on_service` VARCHAR(100) NULL,
  `status_on_service` VARCHAR(255) NULL,
  `main_internet` VARCHAR(255) NULL,
  `installed_join_ad` VARCHAR(255) NULL,
  `conducted_by` VARCHAR(255) NULL,
  `comment` TEXT NULL,
  `computer` VARCHAR(255) NULL,
  `computer_name_1` VARCHAR(255) NULL,
  `username_1` VARCHAR(255) NULL,
  `computer_name_2` VARCHAR(255) NULL,
  `username_2` VARCHAR(255) NULL,
  `password_value` VARCHAR(255) NULL,
  `assigned_employee_code` VARCHAR(50) NOT NULL,
  `assigned_name` VARCHAR(255) NULL,
  `assignment_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `updated_by` VARCHAR(255) NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_new_branch_tasks_assignee` (`assigned_employee_code`, `deleted_at`),
  KEY `idx_new_branch_tasks_batch` (`batch_id`),
  KEY `idx_new_branch_tasks_branch` (`branch_name`),
  CONSTRAINT `fk_new_branch_tasks_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `new_branch_import_batches` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
