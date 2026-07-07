# คู่มือติดตั้งระบบ HDD Delivery System

## 1. ความต้องการระบบ
- PHP 7.4 ขึ้นไป
- MySQL 5.7 ขึ้นไป
- Apache / Nginx / XAMPP / Laragon
- Browser: Chrome หรือ Edge

## 2. การติดตั้งฐานข้อมูล
1. สร้างฐานข้อมูลและตารางโดยรันไฟล์:

```bash
mysql -u root -p < database/schema.sql
```

2. ถ้ามีไฟล์ Import ทำเนียบสาขาที่สร้างไว้ก่อนหน้า ให้ Import ต่อได้ เช่น:

```bash
mysql -u root -p harddisk_db < branch_directory_import_extended.sql
```

3. ถ้ามีประวัติการส่ง HDD เดิม ให้ Import ต่อได้ เช่น:

```bash
mysql -u root -p harddisk_db < harddisk_shipments_import.sql
```

## 3. ตั้งค่าฐานข้อมูล
แก้ไฟล์ `config/database.php`

```php
$DB_HOST = 'localhost';
$DB_NAME = 'harddisk_db';
$DB_USER = 'root';
$DB_PASS = '';
```

## 4. วิธีเข้าใช้งาน
เปิด URL:

```text
http://localhost/harddisk_delivery_web/public/login.php
```

บัญชีเริ่มต้น:

```text
Employee Code: 100001
Password: admin123
```

หากเป็นฐานข้อมูลเดิมที่เคยสร้างไว้ก่อนหน้า ให้รัน migration นี้เพิ่มหลัง backup ฐานข้อมูลแล้ว:

```bash
mysql -u root -p harddisk_db < database/migrate_existing_schema_20260630.sql
```

## 5. Flow การใช้งาน
1. User เข้าเมนู `บันทึกคำขอส่ง HDD`
2. กรอกรหัสสาขาใหญ่ แล้วกดค้นหา
3. เลือกชื่อสาขา ระบบตรวจสอบรายการซ้ำให้ทันที
4. บันทึกคำขอ สถานะจะเป็น `รอยิงบาร์โค้ด`
5. เจ้าหน้าที่เปิดหน้า `รายการรอยิงบาร์โค้ด`
6. กดยิงบาร์โค้ด แล้วสแกน Serial HDD
7. ระบบตรวจสอบว่า HDD อยู่ในคลังและมีสถานะ `available`
8. เมื่อจับคู่สำเร็จ สถานะคำขอเป็น `matched` และ HDD เป็น `reserved`
9. เข้าเมนู `รอยืนยันจัดส่ง` กรอกเลข Tracking
10. ระบบบันทึกประวัติจริงลง `harddisk_shipments`

## 6. ตารางสำคัญ
- `branch_directory` ทำเนียบสาขา
- `harddisk_delivery_requests` คำขอรอจัดส่ง
- `harddisk_inventory` คลัง Serial HDD
- `harddisk_request_items` ประวัติการยิงบาร์โค้ดจับคู่
- `harddisk_shipments` ประวัติการจัดส่งจริง
- `users` ผู้ใช้งานระบบ

## 7. หมายเหตุด้านความปลอดภัย
- ระบบใช้ PDO Prepared Statement
- มี CSRF Token ในฟอร์มเขียนข้อมูล
- มี Session Login เบื้องต้น
- ก่อนใช้งานจริงควรเปลี่ยน Password admin
- หากขึ้น Production ควรตั้ง HTTPS และสิทธิ์ผู้ใช้ตามบทบาท
