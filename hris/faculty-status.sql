-- Apply in the Synk database after tbl_useraccount exists.
CREATE TABLE IF NOT EXISTS tbl_hr_faculty_statuses (
    record_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('active', 'enactive', 'retired') NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (record_key),
    CONSTRAINT fk_hr_faculty_status_account FOREIGN KEY (updated_by)
        REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
