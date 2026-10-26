-- Management-only Midterm / Final settings, one per organization/year/semester.
-- Does not alter shared Academic Settings, workloads, scheduling, or reports.
CREATE TABLE IF NOT EXISTS tbl_management_period_settings (
    organization_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    current_exam_term ENUM('MIDTERM','FINAL') NOT NULL DEFAULT 'MIDTERM',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, ay_id, semester),
    KEY ix_management_period_scope (college_id, organization_id),
    KEY ix_management_period_ay (ay_id),
    CONSTRAINT fk_management_period_org FOREIGN KEY (college_id, organization_id)
        REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_period_ay FOREIGN KEY (ay_id)
        REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
