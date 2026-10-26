CREATE TABLE IF NOT EXISTS tbl_management_violations (
    violation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    ay_id INT UNSIGNED NULL,
    semester TINYINT UNSIGNED NULL,
    violation_type VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (violation_id),
    UNIQUE KEY uq_management_violation_org_term (organization_id, ay_id, semester, violation_type),
    UNIQUE KEY uq_management_violation_scope (college_id, violation_id),
    CONSTRAINT fk_management_violation_college FOREIGN KEY (college_id) REFERENCES tbl_college (college_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_violation_org FOREIGN KEY (college_id, organization_id) REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_violation_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
