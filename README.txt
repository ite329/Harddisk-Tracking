ไฟล์ที่ปรับปรุง
1. modules/requests/create.php
   - เพิ่มการตรวจสอบประวัติส่ง HDD จากตาราง harddisk_shipments โดยเทียบจากชื่อสาขา branch_name
   - ถ้าพบประวัติส่งเดิม จะแสดงแจ้งเตือนรายการซ้ำ
   - แสดงช่องบังคับกรอกเหตุผลที่ต้องส่ง HDD ไปอีกรอบ

2. modules/requests/save.php
   - ตรวจสอบซ้ำฝั่ง Server ก่อนบันทึกข้อมูล
   - ถ้าพบประวัติใน harddisk_shipments แต่ไม่กรอกเหตุผล จะไม่ให้บันทึก
   - บันทึกเหตุผลส่งซ้ำต่อท้ายในช่อง remark

3. api/check_shipment_history.php
   - API สำหรับตรวจประวัติจากตาราง harddisk_shipments โดยใช้ branch_name

วิธีติดตั้ง
1. Backup ไฟล์เดิมก่อน
2. วาง modules/requests/create.php และ modules/requests/save.php ทับไฟล์เดิม
3. วาง api/check_shipment_history.php ไว้ที่ C:\xampp\htdocs\harddisk_delivery_web\api\check_shipment_history.php
4. ทดสอบเลือกสาขาที่เคยมีข้อมูลใน harddisk_shipments
