CREATE TABLE IF NOT EXISTS delivery_headers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_no VARCHAR(30) NOT NULL,
    delivery_date DATE NOT NULL,
    branch_code VARCHAR(50) NOT NULL,
    main_branch_name VARCHAR(255) DEFAULT NULL,
    sub_branch_name VARCHAR(255) DEFAULT NULL,
    carrier VARCHAR(100) DEFAULT NULL,
    tracking_no VARCHAR(150) DEFAULT NULL,
    reference_no VARCHAR(150) DEFAULT NULL,
    remark TEXT DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_headers_no (delivery_no),
    KEY idx_delivery_headers_date (delivery_date),
    KEY idx_delivery_headers_branch (branch_code),
    KEY idx_delivery_headers_tracking (tracking_no),
    KEY idx_delivery_headers_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id INT UNSIGNED NOT NULL,
    item_type VARCHAR(120) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    item_detail VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_delivery_items_delivery (delivery_id),
    KEY idx_delivery_items_type (item_type),
    CONSTRAINT fk_delivery_items_header FOREIGN KEY (delivery_id)
        REFERENCES delivery_headers(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_item_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_item_types_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO delivery_item_types (item_name, sort_order) VALUES
('คอมพิวเตอร์',10),('จอคอมพิวเตอร์',20),('เครื่องปริ้น',30),('Scanner',40),
('Harddisk',50),('SSD',60),('RAM',70),('UPS',80),('Keyboard',90),('Mouse',100),
('Drum',110),('Toner',120),('Switch',130),('Router',140),('Access Point',150),
('กล้องวงจรปิด',160),('อื่น ๆ',999);

INSERT IGNORE INTO permissions (module_code, permission_code, permission_name, is_active) VALUES
('delivery_log','delivery_log.view','ดูรายการส่งของ',1),
('delivery_log','delivery_log.create','เพิ่มรายการส่งของ',1),
('delivery_log','delivery_log.edit','แก้ไขรายการส่งของ',1),
('delivery_log','delivery_log.delete','ลบรายการส่งของ',1),
('delivery_log','delivery_log.export','ส่งออกรายการส่งของ',1);
