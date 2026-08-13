-- เพิ่มประเภทสาขาในตาราง branch_directory
-- รองรับ MySQL 5.7 / 8.0

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'branch_directory'
      AND COLUMN_NAME = 'branch_type'
);

SET @sql_add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `branch_directory` ADD COLUMN `branch_type` VARCHAR(50) NULL AFTER `branch_name_2`',
    'SELECT ''branch_type column already exists'' AS message'
);
PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'branch_directory'
      AND INDEX_NAME = 'idx_branch_directory_branch_type'
);

SET @sql_add_index := IF(
    @index_exists = 0,
    'CREATE INDEX `idx_branch_directory_branch_type` ON `branch_directory` (`branch_type`)',
    'SELECT ''idx_branch_directory_branch_type already exists'' AS message'
);
PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;
