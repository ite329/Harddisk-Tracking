-- Import IT staff users into existing users table
-- Default password for every user: 1234
-- Password is stored as password_hash compatible with PHP password_verify()

START TRANSACTION;

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('00106', 'นายวิโรจน์', 'ลอยทับเลิศ', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('00830', 'นายบุณยกร', 'แก้วแสนตอ', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('01395', 'นายชานนท์', 'สีหะวงษ์', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('06836', 'นายวันชัย', 'ระมั่ง', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('09404', 'นายวัชระ', 'แย้มกร', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('10057', 'นายสุริยา', 'อุ่นใจ', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('15170', 'นายอานันท์', 'โนนดงกลาง', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('16470', 'นายไชยมงคล', 'เข็มทอง', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('17059', 'นายนิธิศ', 'สีหะคลัง', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('14329', 'นายกฤษติพงษ์', 'ภูดินดง', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('19630', 'นายลัทธกิตต์', 'บัววิเชียร', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('21245', 'นายเสกสรร', 'จันทร์มาก', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('28493', 'นายกฤษณะ', 'พันชื่น', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('29762', 'นายฐานิต', 'ฉันทะประเสริฐ', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, password_hash, role, is_active, created_at)
VALUES ('29761', 'นายณรงค์เดช', 'แสนทวีสุข', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

COMMIT;