USE harddisk_db;

DELIMITER $$

DROP PROCEDURE IF EXISTS add_column_if_missing $$
CREATE PROCEDURE add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN column_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', table_name_value, '` ADD COLUMN ', column_definition_value);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DROP PROCEDURE IF EXISTS add_index_if_missing $$
CREATE PROCEDURE add_index_if_missing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN index_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', table_name_value, '` ADD ', index_definition_value);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

CALL add_column_if_missing('users', 'employee_code', '`employee_code` VARCHAR(50) NULL AFTER `id`');
CALL add_column_if_missing('users', 'first_name', '`first_name` VARCHAR(100) NULL AFTER `employee_code`');
CALL add_column_if_missing('users', 'last_name', '`last_name` VARCHAR(100) NULL AFTER `first_name`');
CALL add_column_if_missing('users', 'last_login_at', '`last_login_at` DATETIME NULL DEFAULT NULL AFTER `is_active`');
CALL add_column_if_missing('users', 'deleted_at', '`deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`');

SET @has_username = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'username'
);

SET @has_full_name = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'full_name'
);

SET @sql = IF(
    @has_username > 0,
    "UPDATE users SET employee_code = COALESCE(NULLIF(employee_code, ''), NULLIF(username, ''), CONCAT('USER', id)) WHERE employee_code IS NULL OR employee_code = ''",
    "UPDATE users SET employee_code = COALESCE(NULLIF(employee_code, ''), CONCAT('USER', id)) WHERE employee_code IS NULL OR employee_code = ''"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = CASE
    WHEN @has_full_name > 0 AND @has_username > 0 THEN
        "UPDATE users SET first_name = COALESCE(NULLIF(first_name, ''), NULLIF(full_name, ''), username, employee_code) WHERE first_name IS NULL OR first_name = ''"
    WHEN @has_full_name > 0 THEN
        "UPDATE users SET first_name = COALESCE(NULLIF(first_name, ''), NULLIF(full_name, ''), employee_code) WHERE first_name IS NULL OR first_name = ''"
    WHEN @has_username > 0 THEN
        "UPDATE users SET first_name = COALESCE(NULLIF(first_name, ''), username, employee_code) WHERE first_name IS NULL OR first_name = ''"
    ELSE
        "UPDATE users SET first_name = COALESCE(NULLIF(first_name, ''), employee_code) WHERE first_name IS NULL OR first_name = ''"
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE users
SET last_name = COALESCE(last_name, '')
WHERE last_name IS NULL;

ALTER TABLE users MODIFY employee_code VARCHAR(50) NOT NULL;
ALTER TABLE users MODIFY first_name VARCHAR(100) NOT NULL;
ALTER TABLE users MODIFY last_name VARCHAR(100) NOT NULL;

CALL add_index_if_missing('users', 'uk_employee_code', 'UNIQUE KEY `uk_employee_code` (`employee_code`)');
CALL add_index_if_missing('users', 'idx_deleted_at', 'INDEX `idx_deleted_at` (`deleted_at`)');

CALL add_column_if_missing('harddisk_inventory', 'scanned_by', '`scanned_by` VARCHAR(100) NULL AFTER `status`');
CALL add_column_if_missing('harddisk_inventory', 'scanned_at', '`scanned_at` DATETIME NULL DEFAULT NULL AFTER `scanned_by`');
CALL add_index_if_missing('harddisk_inventory', 'idx_scanned_at', 'INDEX `idx_scanned_at` (`scanned_at`)');

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('100001','ผู้ดูแล','ระบบ','$2y$10$JPwoY0ks74De06SxaMW42Ovdpaq3EDuz8tnkzZCxuYTS3HZCIYnvm','admin',1,NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;
