-- Kawani personnel categories and the non-teaching position catalog.
CREATE TABLE IF NOT EXISTS tbl_hr_non_teaching_positions (
    position_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    position_title VARCHAR(200) NOT NULL,
    salary_grade TINYINT UNSIGNED NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id),
    UNIQUE KEY uq_hr_non_teaching_position_title (position_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_personnel_details (
    record_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    personnel_category ENUM('teaching', 'non_teaching') NOT NULL,
    position_id INT UNSIGNED NULL,
    office_unit VARCHAR(200) NOT NULL DEFAULT '',
    plantilla_item_number VARCHAR(64) NOT NULL DEFAULT '',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (record_key),
    CONSTRAINT fk_hr_personnel_position FOREIGN KEY (position_id)
        REFERENCES tbl_hr_non_teaching_positions (position_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
