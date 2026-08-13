# รวมระบบ Harddisk Tracking Web + ระบบตรวจสอบข้อมูลงานซ่อม

ชุดนี้รวมระบบเดิม 2 ระบบเข้าด้วยกัน โดยใช้หน้า Login กลางของ Harddisk Tracking Web:

- หน้า Login กลาง: `/harddisk_delivery_web/public/login.php`
- ระบบ Harddisk: `/harddisk_delivery_web/modules/requests/create.php`
- ระบบตรวจสอบข้อมูลงานซ่อม: `/harddisk_delivery_web/modules/repair_system/index.php`

## สิ่งที่ปรับให้

1. คัดลอกระบบตรวจสอบข้อมูลงานซ่อมไว้ที่ `modules/repair_system/`
2. Login ผ่าน `public/login.php` ของ Harddisk Tracking Web เพียงจุดเดียว
3. หลัง Login ระบบจะสร้าง Session กลางให้ทั้ง 2 ระบบอ่านได้ เช่น `employee_code`, `user_id`, `id`, `pass`, `full_name`
4. เพิ่มเมนูซ้ายใน Harddisk Tracking Web ชื่อ `ระบบตรวจสอบงานซ่อม`
5. ปิดหน้า Login เดิมของระบบงานซ่อม โดยให้ redirect มาที่ Login กลาง
6. ปรับ Logout ให้กลับมาหน้า Login กลาง

## การใช้งาน

นำโฟลเดอร์และไฟล์ใน ZIP ไปวางทับที่:

`C:\xampp\htdocs\harddisk_delivery_web`

จากนั้นเข้าใช้งานที่:

`http://localhost/harddisk_delivery_web/public/login.php`

ถ้าต้องการเข้าไปยังระบบงานซ่อมโดยตรง ระบบจะเด้งไป Login กลางก่อน:

`http://localhost/harddisk_delivery_web/modules/repair_system/index.php`

## หมายเหตุฐานข้อมูล

ระบบ Harddisk ยังใช้ฐานข้อมูลจาก `config/database.php` เหมือนเดิม
ระบบงานซ่อมยังใช้ฐานข้อมูลเดิมจาก `modules/repair_system/connect_mtc.php`

ถ้ารหัสพนักงาน Login ได้ใน Harddisk แต่ในระบบงานซ่อมแสดงชื่อว่าง ให้ตรวจสอบว่ามีรหัสพนักงานเดียวกันในตาราง `login` ของฐานข้อมูลระบบงานซ่อมหรือไม่
