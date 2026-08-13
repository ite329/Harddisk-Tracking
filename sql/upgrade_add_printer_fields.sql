ALTER TABLE `wcs_repair_quotes`
  ADD COLUMN `printer_model` VARCHAR(150) DEFAULT NULL AFTER `asset_code`,
  ADD COLUMN `serial_number` VARCHAR(150) DEFAULT NULL AFTER `printer_model`,
  ADD COLUMN `remark` TEXT DEFAULT NULL AFTER `serial_number`;

CREATE INDEX `idx_wcs_printer_model` ON `wcs_repair_quotes` (`printer_model`);
CREATE INDEX `idx_wcs_serial_number` ON `wcs_repair_quotes` (`serial_number`);
