-- Add quantity per Drum item while preserving the existing unique key (request_no + drum_code).
-- Existing rows become quantity = 1 automatically.
ALTER TABLE harddisk_db.drum_withdrawals
    ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER drum_code;
