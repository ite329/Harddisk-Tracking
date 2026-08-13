-- เพิ่มเลขที่ปัญหาและหมายเหตุสำหรับรายการเบิก Drum
-- สำรองฐานข้อมูลก่อนดำเนินการ

ALTER TABLE `harddisk_db`.`drum_withdrawals`
    ADD COLUMN `problem_no` VARCHAR(100) NULL AFTER `drum_code`,
    ADD COLUMN `remark` VARCHAR(500) NULL AFTER `problem_no`;

-- ข้อมูลเก่าคงเป็น NULL เพื่อไม่กระทบรายการเดิม
-- ระบบบังคับกรอก problem_no สำหรับรายการเพิ่ม/แก้ไขใหม่ที่ระดับ PHP และ HTML
