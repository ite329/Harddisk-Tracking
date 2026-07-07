-- Import IT staff users into existing users table and keep position column
-- Default password for every user: 1234

ALTER TABLE users ADD COLUMN position_name VARCHAR(255) NULL COMMENT 'ตำแหน่ง' AFTER last_name;

START TRANSACTION;

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('00106', 'นายวิโรจน์', 'ลอยทับเลิศ', 'ผู้ช่วยผู้จัดการฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('00830', 'นายบุณยกร', 'แก้วแสนตอ', 'หัวหน้าส่วนฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('01395', 'นายชานนท์', 'สีหะวงษ์', 'หัวหน้าส่วนฝ่ายเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('06836', 'นายวันชัย', 'ระมั่ง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('09404', 'นายวัชระ', 'แย้มกร', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('10057', 'นายสุริยา', 'อุ่นใจ', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('15170', 'นายอานันท์', 'โนนดงกลาง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('16470', 'นายไชยมงคล', 'เข็มทอง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('17059', 'นายนิธิศ', 'สีหะคลัง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('14329', 'นายกฤษติพงษ์', 'ภูดินดง', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('19630', 'นายลัทธกิตต์', 'บัววิเชียร', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('21245', 'นายเสกสรร', 'จันทร์มาก', 'หัวหน้าหน่วยเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('28493', 'นายกฤษณะ', 'พันชื่น', 'พนักงานเทคโนโลยีสารสนเทศ (ไอที-ซัพพอร์ท)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('29762', 'นายฐานิต', 'ฉันทะประเสริฐ', 'พนักงานเทคโนโลยีสารสนเทศ (Technical Support)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

INSERT INTO users (employee_code, first_name, last_name, position_name, password_hash, role, is_active, created_at)
VALUES ('29761', 'นายณรงค์เดช', 'แสนทวีสุข', 'พนักงานเทคโนโลยีสารสนเทศ (Technical Support)', '$2y$12$RllnjRwZhxcN64.iqqMVJ.OkSjomsKkh.4MnW8UBnJAYMG/yzw8Va', 'it_staff', 1, NOW())
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    position_name = VALUES(position_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1,
    updated_at = NOW();

COMMIT;