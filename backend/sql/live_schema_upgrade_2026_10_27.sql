-- SYNK: schema upgrade from the supplied live dump (2026-10-27).
-- Live source: synk_db (10.27.26).sql; MariaDB 10.4.27; 71 tables.
-- SHA-256: 9aeb12e39115c9622f13796ded5d9c3cd934f35b155f030063e21be2685ec179.
-- Target: 77 tables; six new Management tables and three altered catalogs.
-- Select the live Synk database in phpMyAdmin before running this entire file.
-- FIRST rerun live_schema_preflight_2026_10_27.sql after the previous error.
-- Confirm the selected database and that the server is MariaDB 10.4. DDL commits
-- implicitly. MariaDB IF NOT EXISTS clauses make this safe to resume.
-- This patch contains schema changes only: no INSERT, UPDATE, DELETE or DROP.
-- Status changes preserve existing enum values and add ACTIVE/INACTIVE.
-- Existing exam_term values remain NULL until explicitly assigned by Management.
-- Direct ALTER statements avoid dynamic SQL and information_schema references.
-- Keep a database backup before running this patch.

SET NAMES utf8mb4;
SET @synk_schema_original_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@synk_schema_original_sql_mode,''), 'STRICT_ALL_TABLES', 'NO_ENGINE_SUBSTITUTION');

-- 1. Extend catalog status types without rewriting existing status values.
ALTER TABLE `tbl_management_contributions` MODIFY COLUMN `status` ENUM('DRAFT','OPEN','CLOSED','ARCHIVED','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE';
ALTER TABLE `tbl_management_events` MODIFY COLUMN `status` ENUM('DRAFT','OPEN','CLOSED','ARCHIVED','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE';
ALTER TABLE `tbl_management_event_sessions` MODIFY COLUMN `status` ENUM('DRAFT','OPEN','CLOSED','ARCHIVED','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE';

-- 2. Add nullable Management-only period fields and their lookup indexes.
ALTER TABLE `tbl_management_contributions` ADD COLUMN IF NOT EXISTS `exam_term` ENUM('MIDTERM','FINAL') NULL DEFAULT NULL AFTER `semester`;
ALTER TABLE `tbl_management_contributions` ADD INDEX IF NOT EXISTS `ix_management_contribution_period` (`college_id`,`organization_id`,`ay_id`,`semester`,`exam_term`,`contribution_id`);

ALTER TABLE `tbl_management_events` ADD COLUMN IF NOT EXISTS `exam_term` ENUM('MIDTERM','FINAL') NULL DEFAULT NULL AFTER `semester`;
ALTER TABLE `tbl_management_events` ADD INDEX IF NOT EXISTS `ix_management_event_period` (`college_id`,`organization_id`,`ay_id`,`semester`,`exam_term`,`event_id`);

-- 3. Create only the six tables absent from the live dump.

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

-- 4. Restore the session SQL mode. Rerun the read-only preflight to verify.
SET SESSION sql_mode = @synk_schema_original_sql_mode;
