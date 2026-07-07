ไฟล์ SQL สำหรับสร้างตาราง harddisk_shipments

แหล่งข้อมูล: รายงาน HDD ที่ส่งให้สาขาแล้ว.xlsx
Sheet: Sheet1
จำนวนรายการที่นำเข้า: 1861 รายการ
ช่วงวันที่จัดส่ง: 2023-12-25 ถึง 2026-06-09

ไฟล์สำคัญ:
1) harddisk_shipments_full_import.sql
   - ใช้ไฟล์นี้นำเข้าใน phpMyAdmin ได้เลย
   - มี DROP TABLE, CREATE TABLE และ INSERT DATA ครบ

2) 01_create_harddisk_shipments.sql
   - สร้างเฉพาะตารางเปล่า

3) 02_insert_harddisk_shipments.sql
   - เพิ่มเฉพาะข้อมูลลงตารางเดิม

หมายเหตุ:
- branch_code = Cost Center
- main_branch_code = รหัสสาขา
- branch_name = ชื่อสาขา
- hdd_serial = SN_HDD
- shipped_date = วันที่ส่งให้สาขา
- reported_by = คนแจ้ง
- shipment_status ตั้งค่าเริ่มต้นเป็น sent

สถิติเบื้องต้น:
- ชื่อสาขาที่มีประวัติซ้ำมากกว่า 1 รายการ: 262 ชื่อสาขา
- Serial HDD ที่ซ้ำมากกว่า 1 รายการ: 30 รายการ
- ไม่มีชื่อสาขา: 0 รายการ
- ไม่มี Serial HDD: 0 รายการ
- ไม่มีวันที่ส่ง: 0 รายการ

คำแนะนำก่อน Import:
- สำรองฐานข้อมูลเดิมก่อน
- หากมีตาราง harddisk_shipments เดิมอยู่แล้ว ไฟล์ full_import จะลบทิ้งแล้วสร้างใหม่
