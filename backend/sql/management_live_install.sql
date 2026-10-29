-- SYNK MANAGEMENT: complete schema installation / upgrade.
-- Select the LIVE SYNK database in phpMyAdmin before running the entire file.
-- Tested on MySQL 5.7.25; no stored routines or DELIMITER commands required.
-- Existing Synk tbl_college, tbl_academic_years, tbl_useraccount and
-- tbl_student_management are required.
-- Installs/updates Management schema and normalizes catalog/session statuses.
-- Does not import catalog rows, assign accounts, change the current academic
-- term, or create payments.
-- DDL commits implicitly. Take a database backup before applying to live.

SET NAMES utf8mb4;
SET @synk_management_database = DATABASE();

-- 1. Create missing Management tables in dependency order.
CREATE TABLE IF NOT EXISTS tbl_management_organizations (
    organization_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_code VARCHAR(30) NOT NULL,
    organization_name VARCHAR(150) NOT NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id),
    UNIQUE KEY uq_management_org_code (college_id, organization_code),
    UNIQUE KEY uq_management_org_scope (college_id, organization_id),
    CONSTRAINT fk_management_org_college FOREIGN KEY (college_id) REFERENCES tbl_college (college_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_events (
    event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    ay_id INT UNSIGNED NULL,
    semester TINYINT UNSIGNED NULL,
    exam_term ENUM('MIDTERM','FINAL') NULL DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    audience_mode ENUM('ALL','SELECTED','UNSPECIFIED') NOT NULL DEFAULT 'UNSPECIFIED',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    absence_fine DECIMAL(12,2) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    attendance_radius_m INT UNSIGNED NULL,
    source_table VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_record_id INT UNSIGNED NULL,
    source_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_management_event_scope (college_id, event_id),
    UNIQUE KEY uq_management_event_source (college_id, source_table, source_record_id),
    KEY ix_management_event_term (college_id, ay_id, semester, event_date),
    KEY ix_management_event_period (college_id, organization_id, ay_id, semester, exam_term, event_id),
    KEY ix_management_event_org (college_id, organization_id),
    KEY ix_management_event_ay (ay_id),
    CONSTRAINT fk_management_event_college FOREIGN KEY (college_id) REFERENCES tbl_college (college_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_event_org FOREIGN KEY (college_id, organization_id) REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_event_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_event_sessions (
    session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    fine_per_minute DECIMAL(12,4) NOT NULL DEFAULT 0,
    grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    potential_fine DECIMAL(12,2) NULL COMMENT 'IMPORTED REFERENCE AMOUNT; NOT A STUDENT CHARGE',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    source_table VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_record_id INT UNSIGNED NULL,
    source_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_management_session_scope (college_id, session_id),
    UNIQUE KEY uq_management_session_source (college_id, source_table, source_record_id),
    KEY ix_management_session_event (college_id, event_id, starts_at),
    CONSTRAINT fk_management_session_event FOREIGN KEY (college_id, event_id) REFERENCES tbl_management_events (college_id, event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_contributions (
    contribution_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NULL DEFAULT NULL,
    event_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    contribution_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    audience_mode ENUM('ALL','SELECTED','UNSPECIFIED') NOT NULL DEFAULT 'UNSPECIFIED',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    source_table VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_record_id INT UNSIGNED NULL,
    source_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (contribution_id),
    UNIQUE KEY uq_management_contribution_scope (college_id, contribution_id),
    UNIQUE KEY uq_management_contribution_source (college_id, source_table, source_record_id),
    KEY ix_management_contribution_term (college_id, ay_id, semester, contribution_date),
    KEY ix_management_contribution_period (college_id, organization_id, ay_id, semester, exam_term, contribution_id),
    KEY ix_management_contribution_org (college_id, organization_id),
    KEY ix_management_contribution_event (college_id, event_id),
    KEY ix_management_contribution_ay (ay_id),
    CONSTRAINT fk_management_contribution_college FOREIGN KEY (college_id) REFERENCES tbl_college (college_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_contribution_org FOREIGN KEY (college_id, organization_id) REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_contribution_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_contribution_event FOREIGN KEY (college_id, event_id) REFERENCES tbl_management_events (college_id, event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Upgrade existing contribution storage without guessing a historical term.
SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_contributions' AND COLUMN_NAME = 'exam_term'),
  'ALTER TABLE tbl_management_contributions ADD COLUMN exam_term ENUM(''MIDTERM'',''FINAL'') NULL DEFAULT NULL AFTER semester',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;
SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_contributions' AND INDEX_NAME = 'ix_management_contribution_period'),
  'ALTER TABLE tbl_management_contributions ADD INDEX ix_management_contribution_period (college_id, organization_id, ay_id, semester, exam_term, contribution_id)',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

-- Contribution statuses: retain raw source fields and original timestamps.
SET @synk_contribution_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_management_contributions'
    AND COLUMN_NAME = 'status' AND COLUMN_TYPE <> 'enum(''ACTIVE'',''INACTIVE'')'
);
SET @synk_contribution_status_ddl = IF(@synk_contribution_status_upgrade,
  'ALTER TABLE tbl_management_contributions MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_contribution_status_stmt FROM @synk_contribution_status_ddl;
EXECUTE synk_contribution_status_stmt;
DEALLOCATE PREPARE synk_contribution_status_stmt;
UPDATE tbl_management_contributions
SET status = CASE WHEN status = 'OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
    updated_at = updated_at
WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');
SET @synk_contribution_status_ddl = IF(@synk_contribution_status_upgrade,
  'ALTER TABLE tbl_management_contributions MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_contribution_status_stmt FROM @synk_contribution_status_ddl;
EXECUTE synk_contribution_status_stmt;
DEALLOCATE PREPARE synk_contribution_status_stmt;

-- Management event periods. Existing events retain an unknown exam term for
-- explicit review. Session periods are inherited from their parent event.
SET @synk_event_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND COLUMN_NAME='exam_term'),
  'ALTER TABLE tbl_management_events ADD COLUMN exam_term ENUM(''MIDTERM'',''FINAL'') NULL DEFAULT NULL AFTER semester',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
SET @synk_event_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND INDEX_NAME='ix_management_event_period'),
  'ALTER TABLE tbl_management_events ADD INDEX ix_management_event_period (college_id,organization_id,ay_id,semester,exam_term,event_id)',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;

-- Normalize event and session statuses without altering source fields/timestamps.
SET @synk_event_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND COLUMN_NAME='status'
    AND COLUMN_TYPE<>'enum(''ACTIVE'',''INACTIVE'')');
SET @synk_event_ddl = IF(@synk_event_status_upgrade,
  'ALTER TABLE tbl_management_events MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
UPDATE tbl_management_events SET status=CASE WHEN status='OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
  updated_at=updated_at WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');
SET @synk_event_ddl = IF(@synk_event_status_upgrade,
  'ALTER TABLE tbl_management_events MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;

SET @synk_session_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_event_sessions' AND COLUMN_NAME='status'
    AND COLUMN_TYPE<>'enum(''ACTIVE'',''INACTIVE'')');
SET @synk_event_ddl = IF(@synk_session_status_upgrade,
  'ALTER TABLE tbl_management_event_sessions MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
UPDATE tbl_management_event_sessions SET status=CASE WHEN status='OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
  updated_at=updated_at WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');
SET @synk_event_ddl = IF(@synk_session_status_upgrade,
  'ALTER TABLE tbl_management_event_sessions MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;

-- 2. Extend the account role without replacing existing enum values/defaults.
SELECT COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT,
       CHARACTER_MAXIMUM_LENGTH
INTO @synk_role_type, @synk_role_data_type, @synk_role_nullable,
     @synk_role_default, @synk_role_charset, @synk_role_collation,
     @synk_role_comment, @synk_role_length
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @synk_management_database
  AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'role';

SET @synk_role_new_type = CASE
  WHEN @synk_role_data_type = 'enum'
       AND LOCATE(QUOTE('management'), @synk_role_type) = 0
    THEN CONCAT(LEFT(@synk_role_type, CHAR_LENGTH(@synk_role_type) - 1),
                ',', QUOTE('management'), ')')
  WHEN @synk_role_data_type IN ('char', 'varchar') AND @synk_role_length < 10
    THEN CONCAT(UPPER(@synk_role_data_type), '(50)')
  ELSE NULL
END;

SET @synk_management_ddl = IF(@synk_role_new_type IS NULL, 'DO 0', CONCAT(
  'ALTER TABLE tbl_useraccount MODIFY COLUMN role ', @synk_role_new_type,
  IF(@synk_role_charset IS NULL, '', CONCAT(' CHARACTER SET ', @synk_role_charset)),
  IF(@synk_role_collation IS NULL, '', CONCAT(' COLLATE ', @synk_role_collation)),
  IF(@synk_role_nullable = 'YES', ' NULL', ' NOT NULL'),
  IF(@synk_role_default IS NULL,
     IF(@synk_role_nullable = 'YES', ' DEFAULT NULL', ''),
     CONCAT(' DEFAULT ', QUOTE(@synk_role_default))),
  ' COMMENT ', QUOTE(COALESCE(@synk_role_comment, ''))
));
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

-- 3. Add missing account scope and Google sign-in fields.
SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'management_college_id'),
  'ALTER TABLE tbl_useraccount ADD COLUMN management_college_id INT UNSIGNED NULL DEFAULT NULL AFTER college_id',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'management_organization_id'),
  'ALTER TABLE tbl_useraccount ADD COLUMN management_organization_id INT UNSIGNED NULL DEFAULT NULL AFTER management_college_id',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'auth_provider'),
  'ALTER TABLE tbl_useraccount ADD COLUMN auth_provider ENUM(''legacy'',''google'') NOT NULL DEFAULT ''legacy''',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'google_sub'),
  'ALTER TABLE tbl_useraccount ADD COLUMN google_sub VARCHAR(64) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'google_email_verified'),
  'ALTER TABLE tbl_useraccount ADD COLUMN google_email_verified TINYINT(1) NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'last_login_at'),
  'ALTER TABLE tbl_useraccount ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND COLUMN_NAME = 'last_google_name'),
  'ALTER TABLE tbl_useraccount ADD COLUMN last_google_name VARCHAR(150) NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @synk_management_database AND TABLE_NAME = 'tbl_useraccount'
      AND INDEX_NAME = 'uk_useraccount_google_sub'),
  'ALTER TABLE tbl_useraccount ADD UNIQUE KEY uk_useraccount_google_sub (google_sub)',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_useraccount' AND CONSTRAINT_NAME = 'fk_user_management_org'),
  'ALTER TABLE tbl_useraccount ADD CONSTRAINT fk_user_management_org FOREIGN KEY (management_college_id, management_organization_id) REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

-- 4. Upgrade the earlier college-only Violations table if it exists.
SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND COLUMN_NAME = 'organization_id'),
  'ALTER TABLE tbl_management_violations ADD COLUMN organization_id INT UNSIGNED NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND COLUMN_NAME = 'ay_id'),
  'ALTER TABLE tbl_management_violations ADD COLUMN ay_id INT UNSIGNED NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND COLUMN_NAME = 'semester'),
  'ALTER TABLE tbl_management_violations ADD COLUMN semester TINYINT UNSIGNED NULL DEFAULT NULL',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND CONSTRAINT_NAME = 'fk_management_violation_org'),
  'ALTER TABLE tbl_management_violations ADD CONSTRAINT fk_management_violation_org FOREIGN KEY (college_id, organization_id) REFERENCES tbl_management_organizations (college_id, organization_id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND CONSTRAINT_NAME = 'fk_management_violation_ay'),
  'ALTER TABLE tbl_management_violations ADD CONSTRAINT fk_management_violation_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND CONSTRAINT_NAME = 'uq_management_violation_org_term'),
  'ALTER TABLE tbl_management_violations ADD CONSTRAINT uq_management_violation_org_term UNIQUE (organization_id, ay_id, semester, violation_type)',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

SET @synk_management_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @synk_management_database
      AND TABLE_NAME = 'tbl_management_violations' AND INDEX_NAME = 'uq_management_violation_type'),
  'ALTER TABLE tbl_management_violations DROP INDEX uq_management_violation_type',
  'DO 0'
);
PREPARE synk_management_stmt FROM @synk_management_ddl;
EXECUTE synk_management_stmt;
DEALLOCATE PREPARE synk_management_stmt;

-- 5. Completion summary. Catalog statuses upgraded; source values preserved.
SELECT @synk_management_database AS database_name,
       'MANAGEMENT SCHEMA INSTALLED / UPDATED' AS result;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @synk_management_database
  AND TABLE_NAME IN ('tbl_management_organizations', 'tbl_management_contributions',
                     'tbl_management_events', 'tbl_management_event_sessions',
                     'tbl_management_violations')
ORDER BY TABLE_NAME;

-- 5. Management-only period settings.
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


-- Fresh native Management payments. No imported history or shared-setting writes.
CREATE TABLE IF NOT EXISTS tbl_management_payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    allocation_mode ENUM('DISTRIBUTE','SELECTED') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_before DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    student_number VARCHAR(100) NOT NULL,
    student_name VARCHAR(500) NOT NULL,
    organization_code VARCHAR(100) NOT NULL,
    organization_name VARCHAR(255) NOT NULL,
    period_text VARCHAR(150) NOT NULL,
    recorded_by INT NOT NULL,
    recorded_by_name VARCHAR(255) NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_management_receipt_number (receipt_number),
    UNIQUE KEY uq_management_payment_request (organization_id,request_key),
    KEY ix_management_payment_account (organization_id,student_id,ay_id,semester,exam_term),
    KEY ix_management_payment_student (student_id,payment_id),
    CONSTRAINT fk_management_payment_student FOREIGN KEY (student_id) REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_payment_organization FOREIGN KEY (organization_id) REFERENCES tbl_management_organizations (organization_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_payment_items (
    payment_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    charge_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contribution_id INT UNSIGNED NULL,
    event_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    charge_amount DECIMAL(12,2) NOT NULL,
    paid_before DECIMAL(12,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (payment_item_id),
    UNIQUE KEY uq_management_payment_item (payment_id,charge_key),
    KEY ix_management_paid_contribution (contribution_id),
    KEY ix_management_paid_event (event_id),
    CONSTRAINT fk_management_payment_item FOREIGN KEY (payment_id) REFERENCES tbl_management_payments (payment_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_paid_contribution FOREIGN KEY (contribution_id) REFERENCES tbl_management_contributions (contribution_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_paid_event FOREIGN KEY (event_id) REFERENCES tbl_management_events (event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
    can_manage_exemptions TINYINT(1) NOT NULL DEFAULT 0,
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
SET @synk_officer_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_officers' AND COLUMN_NAME='can_manage_exemptions'),
  'ALTER TABLE tbl_management_officers ADD COLUMN can_manage_exemptions TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_violations',
  'DO 0');
PREPARE synk_officer_stmt FROM @synk_officer_ddl;
EXECUTE synk_officer_stmt;
DEALLOCATE PREPARE synk_officer_stmt;


-- Fresh student-specific Management violations. No legacy imports or profile writes.
CREATE TABLE IF NOT EXISTS tbl_management_student_violations (
    student_violation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    violation_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    occurred_on DATE NOT NULL,
    violation_type VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    notes VARCHAR(1000) NOT NULL DEFAULT '',
    recorded_by INT NOT NULL,
    recorded_by_name VARCHAR(255) NOT NULL,
    recorded_at DATETIME NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (student_violation_id),
    UNIQUE KEY uq_management_student_violation_request (organization_id,request_key,violation_id),
    KEY ix_management_student_violation_period (college_id,organization_id,ay_id,semester,exam_term,student_id),
    KEY ix_management_student_violation_student (student_id,student_violation_id),
    CONSTRAINT fk_management_student_violation_student FOREIGN KEY (student_id) REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_catalog FOREIGN KEY (college_id,violation_id) REFERENCES tbl_management_violations (college_id,violation_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_org FOREIGN KEY (college_id,organization_id) REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_actor FOREIGN KEY (recorded_by) REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND COLUMN_NAME='student_violation_id'),
    'ALTER TABLE tbl_management_payment_items ADD COLUMN student_violation_id INT UNSIGNED NULL AFTER event_id',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;
SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND INDEX_NAME='ix_management_paid_violation'),
    'ALTER TABLE tbl_management_payment_items ADD INDEX ix_management_paid_violation (student_violation_id)',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;
SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND CONSTRAINT_NAME='fk_management_paid_violation'),
    'ALTER TABLE tbl_management_payment_items ADD CONSTRAINT fk_management_paid_violation FOREIGN KEY (student_violation_id) REFERENCES tbl_management_student_violations (student_violation_id) ON DELETE RESTRICT',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;


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
