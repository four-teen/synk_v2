CREATE TABLE IF NOT EXISTS tbl_hr_faculty_reviews (
    record_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_status ENUM('pending', 'follow-up', 'reviewed') NOT NULL,
    review_note VARCHAR(2000) NOT NULL DEFAULT '',
    data_version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (record_key),
    CONSTRAINT fk_hr_faculty_review_account FOREIGN KEY (updated_by)
        REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
