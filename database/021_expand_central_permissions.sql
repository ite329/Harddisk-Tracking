SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (permission_code, permission_name, module_code, description) VALUES
('request.status.manage','ย้อนสถานะคำขอและการจัดส่ง','request','แก้ไขสถานะ Workflow และคืนสถานะ HDD'),
('computer_external.view','เปิดข้อมูลคอมพิวเตอร์ภายนอก','computer_external','เปิดระบบข้อมูลคอมพิวเตอร์ภายนอก'),
('server.view','ดูข้อมูล Server','server','เปิดดูเมนูและข้อมูล Server'),
('server.manage','จัดการข้อมูล Server','server','เพิ่ม แก้ไข หรือลบข้อมูล Server'),
('it_system.view','ดูทะเบียนระบบไอที','it_system','เปิดดูทะเบียนระบบไอทีสารสนเทศ'),
('it_system.manage','จัดการทะเบียนระบบไอที','it_system','เพิ่ม แก้ไข หรือลบทะเบียนระบบไอที'),
('license_software.view','ดู License Software','license_software','เปิดดูข้อมูล License Software'),
('license_software.manage','จัดการ License Software','license_software','เพิ่ม แก้ไข หรือลบ License Software'),
('notebook.view','ดู License Notebook','notebook','เปิดดูข้อมูล License Notebook'),
('notebook.manage','จัดการ License Notebook','notebook','เพิ่ม แก้ไข หรือลบ License Notebook'),
('branch_label.view','ดูและพิมพ์ที่อยู่สาขา','branch_label','ค้นหาและพิมพ์ที่อยู่สาขา'),
('branch_label.manage','จัดการข้อมูลที่อยู่สาขา','branch_label','แก้ไขข้อมูลสำหรับพิมพ์ที่อยู่สาขา'),
('delete_computer.view','ดูรายการลบชื่อเครื่อง','delete_computer','เปิดดูรายการชื่อเครื่อง Join Domain'),
('delete_computer.manage','จัดการลบชื่อเครื่อง','delete_computer','เพิ่ม แก้ไข หรือลบรายการชื่อเครื่อง'),
('keyboard_mouse.view','ดูข้อมูล Keyboard & Mouse','keyboard_mouse','เปิดดูข้อมูล Keyboard และ Mouse'),
('keyboard_mouse.manage','จัดการ Keyboard & Mouse','keyboard_mouse','เพิ่ม แก้ไข รับคืน หรือลบรายการ'),
('wcs_quote.view','ดูใบเสนอราคาซ่อม WCS','wcs_quote','เปิดดูใบเสนอราคาซ่อม WCS'),
('wcs_quote.manage','จัดการใบเสนอราคาซ่อม WCS','wcs_quote','นำเข้า เพิ่ม แก้ไข หรือลบใบเสนอราคา'),
('admin.branch_import','อัปเดตข้อมูลสาขา','admin','เข้าหน้านำเข้าหรืออัปเดตข้อมูลสาขา'),
('admin.asset_import','อัปโหลดข้อมูลทรัพย์สิน','admin','เข้าหน้านำเข้าข้อมูลทรัพย์สิน'),
('admin.online_users','ดูผู้ใช้งานออนไลน์','admin','เข้าหน้าตรวจสอบผู้ใช้งานออนไลน์');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_code = 'super_admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'it_admin' AND p.permission_code <> 'permission.manage';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'it_support' AND p.permission_code IN (
'server.view','server.manage','it_system.view','it_system.manage','license_software.view','license_software.manage',
'notebook.view','notebook.manage','branch_label.view','delete_computer.view','delete_computer.manage',
'keyboard_mouse.view','keyboard_mouse.manage','wcs_quote.view','wcs_quote.manage','computer_external.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_code = 'viewer' AND p.permission_code IN (
'server.view','it_system.view','license_software.view','notebook.view','branch_label.view',
'delete_computer.view','keyboard_mouse.view','wcs_quote.view','computer_external.view'
);
