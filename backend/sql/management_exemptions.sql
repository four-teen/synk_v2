-- Fresh Management-only lists and saved exemptions. List edits never rewrite grants.
CREATE TABLE IF NOT EXISTS tbl_management_exemption_lists (
    list_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    title VARCHAR(150) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (list_id),
    KEY ix_management_exemption_list_period (college_id,organization_id,ay_id,semester,exam_term),
    CONSTRAINT fk_management_exemption_list_org FOREIGN KEY (college_id,organization_id)
        REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_exemption_list_members (
    list_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id,student_id),
    CONSTRAINT fk_management_exemption_member_list FOREIGN KEY (list_id)
        REFERENCES tbl_management_exemption_lists (list_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_member_student FOREIGN KEY (student_id)
        REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_exemptions (
    exemption_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NULL,
    contribution_id INT UNSIGNED NULL,
    source_event_id INT UNSIGNED NULL,
    list_id INT UNSIGNED NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (exemption_id),
    UNIQUE KEY uq_management_exemption_event (student_id,event_id),
    UNIQUE KEY uq_management_exemption_contribution (student_id,contribution_id),
    KEY ix_management_exemption_period (college_id,organization_id,ay_id,semester,exam_term,student_id),
    CONSTRAINT fk_management_exemption_org FOREIGN KEY (college_id,organization_id)
        REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_student FOREIGN KEY (student_id)
        REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_event FOREIGN KEY (event_id)
        REFERENCES tbl_management_events (event_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_contribution FOREIGN KEY (contribution_id)
        REFERENCES tbl_management_contributions (contribution_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_source_event FOREIGN KEY (source_event_id)
        REFERENCES tbl_management_events (event_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_list FOREIGN KEY (list_id)
        REFERENCES tbl_management_exemption_lists (list_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_exemption_actor FOREIGN KEY (recorded_by)
        REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_exemption_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    actor_user_id INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    details MEDIUMTEXT NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (audit_id),
    KEY ix_management_exemption_audit (college_id,organization_id,ay_id,semester,exam_term,audit_id),
    CONSTRAINT fk_management_exemption_audit_org FOREIGN KEY (college_id,organization_id)
        REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
