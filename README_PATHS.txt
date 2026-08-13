Harddisk Delivery Web - SB Admin 2 Style Package

ให้นำไฟล์/โฟลเดอร์ในชุดนี้ไปวางทับในโปรเจกต์:
C:\xampp\htdocs\harddisk_delivery_web

รายการไฟล์ที่แก้ไข/เพิ่ม:
1) includes\header.php
   - ปรับ Layout เป็น Sidebar/Topbar สไตล์ SB Admin 2
   - คง Badge แจ้งเตือนเดิม และปรับเงื่อนไขนับสถานะให้รองรับ pending/pending_scan และ matched/reserved/pending_delivery/pending_ship/waiting_ship
   - Badge รอยืนยันจัดส่งนับตาม requested_by ของผู้ใช้งานปัจจุบัน
   - Badge รับคืน HDD ส่งเคลมนับเฉพาะ received, preparing_claim, sent_claim

2) includes\footer.php
   - ปิดโครง Layout ใหม่
   - ใช้ Bootstrap 5 เดิมของระบบ
   - เพิ่ม JavaScript สำหรับย่อ/ขยาย Sidebar โดยไม่ต้องพึ่ง Bootstrap 4

3) config\database.php
   - เพิ่ม date_default_timezone_set('Asia/Bangkok')
   - เพิ่ม SET time_zone = '+07:00' ให้ MySQL Session

4) public\login.php
   - เพิ่ม stylesheet ของ SB Admin 2 และ custom CSS
   - คง Logic Login เดิมไว้
   - เวลา Login ใช้ Asia/Bangkok แล้ว

5) assets\sb-admin-2\css\sb-admin-2.min.css
   - CSS จาก Template SB Admin 2

6) assets\css\hdd-sb-admin-custom.css
   - CSS ปรับแต่งเฉพาะระบบ Harddisk Delivery Web

หมายเหตุ:
- ชุดนี้ไม่ได้ใส่ vendor/fontawesome-free/webfonts เพื่อหลีกเลี่ยงการส่งไฟล์ฟอนต์
- ใช้ emoji แทน icon font เพื่อให้เมนูยังแสดงผลได้ครบ
- ระบบยังใช้ Bootstrap 5 เดิม ไม่ได้เปลี่ยนเป็น Bootstrap 4 เพื่อไม่ให้หน้า modules เดิมพัง

หลังวางไฟล์:
1. เปิด http://localhost/harddisk_delivery_web/public/login.php
2. Login แล้วตรวจ Dashboard / Requests / Assign HDD / Matched
3. ถ้า assets/css/bootstrap.min.css หรือ assets/js/bootstrap.bundle.min.js ไม่มีในโปรเจกต์เดิม ให้แจ้งเพื่อให้จัดชุด asset เพิ่ม
