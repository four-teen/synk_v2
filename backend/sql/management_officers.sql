-- Native Management delegation only. Existing faculty assignments are retained.
CREATE TABLE IF NOT EXISTS tbl_management_officers (
    officer_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    supervisor_user_id INT NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 0,
    can_attendance TINYINT(1) NOT NULL DEFAULT 0,
    can_payments TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_contributions TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_events TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_violations TINYINT(1) NOT NULL DEFAULT 0,
    expires_on DATE NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (officer_id),
    UNIQUE KEY uq_management_officer_user (user_id),
    UNIQUE KEY uq_management_officer_student (student_id),
    KEY ix_management_officer_supervisor (supervisor_user_id,organization_id),
    CONSTRAINT fk_management_officer_user FOREIGN KEY (user_id) REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_officer_student FOREIGN KEY (student_id) REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_officer_supervisor FOREIGN KEY (supervisor_user_id) REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_officer_org FOREIGN KEY (college_id,organization_id) REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tbl_management_officer_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    officer_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    actor_user_id INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (audit_id),
    KEY ix_management_officer_audit (officer_id,audit_id),
    KEY ix_management_officer_org_audit (organization_id,audit_id),
    CONSTRAINT fk_management_officer_audit FOREIGN KEY (officer_id) REFERENCES tbl_management_officers (officer_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New operations remain disabled for every existing student officer.
SET @synk_officer_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_officers' AND COLUMN_NAME='can_manage_contributions'),
  'ALTER TABLE tbl_management_officers ADD COLUMN can_manage_contributions TINYINT(1) NOT NULL DEFAULT 0 AFTER can_payments',
  'DO 0');
PREPARE synk_officer_stmt FROM @synk_officer_ddl;
EXECUTE synk_officer_stmt;
DEALLOCATE PREPARE synk_officer_stmt;
SET @synk_officer_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_officers' AND COLUMN_NAME='can_manage_events'),
  'ALTER TABLE tbl_management_officers ADD COLUMN can_manage_events TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_contributions',
  'DO 0');
PREPARE synk_officer_stmt FROM @synk_officer_ddl;
EXECUTE synk_officer_stmt;
DEALLOCATE PREPARE synk_officer_stmt;
SET @synk_officer_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_officers' AND COLUMN_NAME='can_manage_violations'),
  'ALTER TABLE tbl_management_officers ADD COLUMN can_manage_violations TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_events',
  'DO 0');
PREPARE synk_officer_stmt FROM @synk_officer_ddl;
EXECUTE synk_officer_stmt;
DEALLOCATE PREPARE synk_officer_stmt;
