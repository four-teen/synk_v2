-- Fresh, Management-only attendance. No legacy history or shared settings changes.
CREATE TABLE IF NOT EXISTS tbl_management_attendance_events (
    event_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    attendance_status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
    absence_fine DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opened_by INT NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by INT NULL,
    closed_at DATETIME NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (event_id),
    KEY ix_management_attendance_period (college_id,organization_id,ay_id,semester,exam_term),
    CONSTRAINT fk_management_attendance_event FOREIGN KEY (college_id,event_id)
        REFERENCES tbl_management_events (college_id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_attendance_roster (
    event_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id,student_id),
    KEY ix_management_attendance_student (student_id,event_id),
    CONSTRAINT fk_management_attendance_roster_event FOREIGN KEY (event_id)
        REFERENCES tbl_management_attendance_events (event_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_attendance_roster_student FOREIGN KEY (student_id)
        REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_attendance_logs (
    event_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id,student_id),
    CONSTRAINT fk_management_attendance_log_roster FOREIGN KEY (event_id,student_id)
        REFERENCES tbl_management_attendance_roster (event_id,student_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_student_qr_tokens (
    student_id INT UNSIGNED NOT NULL,
    qr_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id),
    UNIQUE KEY uq_management_student_qr_token (qr_token),
    CONSTRAINT fk_management_student_qr_student FOREIGN KEY (student_id)
        REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_attendance_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    action ENUM('OPEN','CLOSE','REOPEN') NOT NULL,
    recorded_by INT NOT NULL,
    roster_count INT UNSIGNED NOT NULL,
    present_count INT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_management_attendance_audit_event (event_id,audit_id),
    CONSTRAINT fk_management_attendance_audit_event FOREIGN KEY (event_id)
        REFERENCES tbl_management_attendance_events (event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
