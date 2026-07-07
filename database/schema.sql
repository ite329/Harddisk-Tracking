CREATE DATABASE IF NOT EXISTS harddisk_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE harddisk_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(50) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','it_staff','viewer') NOT NULL DEFAULT 'it_staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_employee_code (employee_code),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branch_directory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    main_branch_code VARCHAR(20) NOT NULL,
    branch_code VARCHAR(20) NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    branch_address TEXT NULL,
    landmark VARCHAR(255) NULL,
    area_name VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_branch_code (branch_code),
    INDEX idx_main_branch_code (main_branch_code),
    INDEX idx_branch_name (branch_name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS harddisk_delivery_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(50) NOT NULL,
    main_branch_code VARCHAR(20) NOT NULL,
    branch_code VARCHAR(20) NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    request_reason VARCHAR(255) NULL,
    remark TEXT NULL,
    status ENUM('pending_scan','matched','shipped','received','cancelled') NOT NULL DEFAULT 'pending_scan',
    requested_by VARCHAR(100) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matched_by VARCHAR(100) NULL,
    matched_at DATETIME NULL,
    shipped_by VARCHAR(100) NULL,
    shipped_at DATETIME NULL,
    received_by VARCHAR(100) NULL,
    received_at DATETIME NULL,
    cancelled_by VARCHAR(100) NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uk_request_no (request_no),
    INDEX idx_main_branch_code (main_branch_code),
    INDEX idx_branch_code (branch_code),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS harddisk_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hdd_serial VARCHAR(100) NOT NULL,
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    capacity VARCHAR(50) NULL,
    status ENUM('available','reserved','shipped','used','damaged','cancelled') NOT NULL DEFAULT 'available',
    scanned_by VARCHAR(100) NULL,
    scanned_at DATETIME NULL DEFAULT NULL,
    received_from VARCHAR(255) NULL,
    received_at DATETIME NULL,
    remark TEXT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uk_hdd_serial (hdd_serial),
    INDEX idx_status (status),
    INDEX idx_scanned_at (scanned_at),
    INDEX idx_received_at (received_at),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS harddisk_request_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    hdd_inventory_id INT NULL,
    hdd_serial VARCHAR(100) NOT NULL,
    scan_status ENUM('matched','removed','cancelled') NOT NULL DEFAULT 'matched',
    scanned_by VARCHAR(100) NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_by VARCHAR(100) NULL,
    removed_at DATETIME NULL,
    remove_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_request_hdd (request_id, hdd_serial),
    INDEX idx_request_id (request_id),
    INDEX idx_hdd_serial (hdd_serial),
    INDEX idx_scan_status (scan_status),
    INDEX idx_scanned_at (scanned_at),
    CONSTRAINT fk_hdd_request_items_request FOREIGN KEY (request_id) REFERENCES harddisk_delivery_requests(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hdd_request_items_inventory FOREIGN KEY (hdd_inventory_id) REFERENCES harddisk_inventory(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS harddisk_shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NULL,
    delivery_request_no VARCHAR(50) NULL,
    main_branch_code VARCHAR(20) NOT NULL,
    branch_code VARCHAR(20) NOT NULL,
    branch_name VARCHAR(255) NOT NULL,
    hdd_serial VARCHAR(100) NOT NULL,
    tracking_no VARCHAR(100) NULL,
    courier_name VARCHAR(100) NULL,
    status ENUM('pending','shipped','received','cancelled') NOT NULL DEFAULT 'shipped',
    remark TEXT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    shipped_by VARCHAR(100) NULL,
    shipped_at DATETIME NULL,
    received_by VARCHAR(100) NULL,
    received_at DATETIME NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_request_id (request_id),
    INDEX idx_delivery_request_no (delivery_request_no),
    INDEX idx_branch_code (branch_code),
    INDEX idx_main_branch_code (main_branch_code),
    INDEX idx_hdd_serial (hdd_serial),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (employee_code, first_name, last_name, password_hash, role) VALUES
('100001','ผู้ดูแล','ระบบ','$2y$10$JPwoY0ks74De06SxaMW42Ovdpaq3EDuz8tnkzZCxuYTS3HZCIYnvm','admin')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), role = VALUES(role);

INSERT INTO branch_directory (main_branch_code, branch_code, branch_name, phone, branch_address, landmark, province) VALUES
('1001','1001001','สาขาเมืองนนทบุรี','02-000-0001','อำเภอเมืองนนทบุรี จังหวัดนนทบุรี','ใกล้ตลาด','นนทบุรี'),
('1001','1001002','สาขาบางบัวทอง','02-000-0002','อำเภอบางบัวทอง จังหวัดนนทบุรี','ใกล้ที่ว่าการอำเภอ','นนทบุรี'),
('2001','2001001','สาขาเมืองนครราชสีมา','044-000-001','อำเภอเมืองนครราชสีมา จังหวัดนครราชสีมา','ใกล้สถานีขนส่ง','นครราชสีมา')
ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), phone = VALUES(phone), branch_address = VALUES(branch_address);

INSERT INTO harddisk_inventory (hdd_serial, brand, model, capacity, status, received_from, received_at, created_by) VALUES
('WD-PURPLE-0001','Western Digital','Purple','1TB','available','IT Stock',NOW(),'admin'),
('WD-PURPLE-0002','Western Digital','Purple','2TB','available','IT Stock',NOW(),'admin'),
('SEAGATE-0001','Seagate','SkyHawk','1TB','available','IT Stock',NOW(),'admin')
ON DUPLICATE KEY UPDATE status = VALUES(status);
