README - Import diy.sql เข้า harddisk_inventory

ไฟล์ต้นทาง: diy.sql
ไฟล์โครงสร้างตารางอ้างอิง: harddisk_inventory.sql
จำนวนข้อมูลที่แปลงได้: 300 รายการ
Serial HDD ไม่ซ้ำในไฟล์ต้นทาง: 300 รายการ

การ Mapping คอลัมน์:
- diy.d_sn  -> harddisk_inventory.hdd_serial
- diy.d_day -> harddisk_inventory.received_at เวลา 00:00:00
- status    -> available
- received_from -> DIY Import
- created_by -> admin
- remark -> นำเข้าจาก diy.sql เดิม d_id=...

ไฟล์ที่แนะนำให้ Import:
1) harddisk_inventory_full_import_from_diy.sql
   ใช้กรณีต้องการสร้างตารางถ้ายังไม่มี แล้ว Import ข้อมูล
   ใช้ INSERT IGNORE เพื่อไม่ให้ Error หาก hdd_serial ซ้ำกับข้อมูลเดิม

2) 01_import_diy_to_harddisk_inventory_insert_ignore.sql
   ใช้กรณีมีตาราง harddisk_inventory อยู่แล้ว
   ข้อมูล Serial ที่ซ้ำจะถูกข้าม ไม่เขียนทับข้อมูลเดิม

3) 02_import_diy_to_harddisk_inventory_upsert_optional.sql
   ตัวเลือกเสริม กรณีพบ Serial ซ้ำ จะอัปเดตเฉพาะ remark และ updated_at
   ไม่แนะนำถ้าไม่ต้องการแก้ไขข้อมูลเดิม

หมายเหตุ:
- ไฟล์นี้ไม่ TRUNCATE และไม่ DROP ตารางเดิม
- แนะนำ Backup ฐานข้อมูลก่อน Import
