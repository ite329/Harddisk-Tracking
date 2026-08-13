ALTER TABLE harddisk_db.drum_withdrawals
    ADD COLUMN delivery_status ENUM('pending','shipped') NOT NULL DEFAULT 'pending' AFTER drum_code,
    ADD COLUMN shipped_at DATETIME NULL AFTER delivery_status,
    ADD INDEX idx_drum_withdrawals_delivery_status (delivery_status),
    ADD INDEX idx_drum_withdrawals_shipped_at (shipped_at);

UPDATE harddisk_db.drum_withdrawals
SET delivery_status = 'pending'
WHERE delivery_status IS NULL OR delivery_status = '';
