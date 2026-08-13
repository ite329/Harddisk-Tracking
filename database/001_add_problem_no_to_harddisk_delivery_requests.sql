-- เพิ่มคอลัมน์เลขที่ปัญหา สำหรับตารางคำขอส่ง HDD
-- รองรับ MySQL 5.7: ตรวจสอบก่อนเพิ่มคอลัมน์/Index เพื่อป้องกัน error กรณีมีอยู่แล้ว

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'harddisk_delivery_requests'
      AND COLUMN_NAME = 'problem_no'
);

SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE harddisk_delivery_requests ADD COLUMN problem_no VARCHAR(100) NULL AFTER request_no',
    'SELECT ''problem_no column already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'harddisk_delivery_requests'
      AND INDEX_NAME = 'idx_hdd_requests_problem_no'
);

SET @sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_hdd_requests_problem_no ON harddisk_delivery_requests (problem_no)',
    'SELECT ''idx_hdd_requests_problem_no already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
