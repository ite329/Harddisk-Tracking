CREATE TABLE IF NOT EXISTS drum_withdrawals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_no VARCHAR(30) NOT NULL,
    main_branch_code VARCHAR(30) NOT NULL,
    branch_name VARCHAR(255) DEFAULT NULL,
    drum_code VARCHAR(50) NOT NULL,
    recorded_by VARCHAR(255) NOT NULL,
    recorded_by_employee_code VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drum_request_item (request_no, drum_code),
    KEY idx_drum_branch_code (main_branch_code),
    KEY idx_drum_code (drum_code),
    KEY idx_drum_recorded_by (recorded_by_employee_code),
    KEY idx_drum_created_at (created_at),
    KEY idx_drum_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
