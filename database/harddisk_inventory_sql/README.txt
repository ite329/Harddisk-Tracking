ไฟล์แปลงข้อมูลจาก diy.sql เป็นตาราง harddisk_inventory

จำนวนข้อมูลที่แปลงได้: 300 รายการ
Serial ซ้ำที่ตัดออก: 0 รายการ
ช่วง source_d_id: 1884 - 2183
วันที่รับเข้า: 2026-06-26

การ map คอลัมน์:
- diy.d_id  -> harddisk_inventory.source_d_id
- diy.d_sn  -> harddisk_inventory.hdd_serial
- diy.d_day -> harddisk_inventory.received_date
- status    -> available

แนะนำให้ Import ไฟล์นี้ใน phpMyAdmin:
1) harddisk_inventory_full_import.sql

ไฟล์ในชุดนี้:
- 01_create_harddisk_inventory.sql
  ใช้สร้างตาราง ถ้ายังไม่มีตาราง

- 02_insert_harddisk_inventory.sql
  ใช้นำเข้าข้อมูลอย่างเดียว เหมาะกับกรณีมีตารางอยู่แล้ว
  มี ON DUPLICATE KEY UPDATE เพื่อป้องกัน Serial HDD ซ้ำ

- harddisk_inventory_full_import.sql
  สร้างตารางถ้ายังไม่มี แล้วนำเข้าข้อมูล
  ไม่ลบข้อมูลเดิม

- harddisk_inventory_recreate_full_import.sql
  ลบตารางเดิมแล้วสร้างใหม่ ใช้เฉพาะกรณีต้องการล้างข้อมูลเดิมทั้งหมด

หมายเหตุ:
- ถ้ามีข้อมูลเดิมอยู่แล้ว แนะนำให้ Backup ก่อน Import
- ตารางนี้กำหนด UNIQUE ที่ hdd_serial เพื่อป้องกัน HDD Serial ซ้ำ
