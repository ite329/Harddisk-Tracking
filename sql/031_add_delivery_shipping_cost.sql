-- เพิ่ม Field ค่าส่งสำหรับรายการส่งของ
-- ใช้กับฐานข้อมูล Harddisk Delivery Web
-- รันไฟล์นี้ 1 ครั้งผ่าน phpMyAdmin หรือ MySQL Client

SET @column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'delivery_headers'
      AND COLUMN_NAME = 'shipping_cost'
);

SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE delivery_headers ADD COLUMN shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER remark',
    'SELECT ''shipping_cost already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
