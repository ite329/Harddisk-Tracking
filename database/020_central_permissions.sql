SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) NOT NULL UNIQUE,
    role_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    permission_name VARCHAR(150) NOT NULL,
    module_code VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_permissions_module (module_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_key VARCHAR(64) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_key, role_id),
    INDEX idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_key VARCHAR(64) NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    permission_type ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_key, permission_id),
    INDEX idx_user_permissions_permission (permission_id),
    CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_key VARCHAR(100) NULL,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    performed_by VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_permission_audit_target (target_type, target_key),
    INDEX idx_permission_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (role_code, role_name, description) VALUES
('super_admin','Super Admin','สิทธิ์ทั้งหมดของระบบ'),
('it_admin','IT Admin','จัดการข้อมูลหลักของระบบ'),
('it_support','IT Support','ใช้งานงานประจำวันของฝ่าย IT'),
('viewer','Viewer','ดูข้อมูลอย่างเดียว');

INSERT IGNORE INTO permissions (permission_code, permission_name, module_code) VALUES
('dashboard.view','ดู Dashboard','dashboard'),
('request.view','ดูคำขอ HDD','request'),('request.create','เพิ่มคำขอ HDD','request'),('request.edit','แก้ไขคำขอ HDD','request'),('request.delete','ลบคำขอ HDD','request'),
('inventory.view','ดูคลัง HDD','inventory'),('inventory.create','เพิ่ม HDD เข้าคลัง','inventory'),('inventory.edit','แก้ไข HDD ในคลัง','inventory'),('inventory.delete','ลบ HDD ในคลัง','inventory'),
('shipment.view','ดูข้อมูลจัดส่ง','shipment'),('shipment.manage','จัดการการจัดส่ง','shipment'),
('claim.view','ดูข้อมูลรับคืน/เคลม','claim'),('claim.manage','จัดการรับคืน/เคลม','claim'),
('asset.view','ดูข้อมูลทรัพย์สิน','asset'),('asset.manage','จัดการข้อมูลทรัพย์สิน','asset'),
('report.view','ดูและ Export รายงาน','report'),
('user.manage','จัดการ User','admin'),('permission.manage','จัดการ Role และ Permission','admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_code = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'it_admin' AND p.permission_code NOT IN ('permission.manage');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'it_support' AND p.permission_code IN (
'dashboard.view','request.view','request.create','request.edit','inventory.view','inventory.create','inventory.edit','shipment.view','shipment.manage','claim.view','claim.manage','asset.view','report.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'viewer' AND p.permission_code IN ('dashboard.view','request.view','inventory.view','shipment.view','claim.view','asset.view','report.view');
