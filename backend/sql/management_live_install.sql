-- SYNK MANAGEMENT: complete schema installation / upgrade.
-- Select the LIVE SYNK database in phpMyAdmin before running the entire file.
-- Tested on MySQL 5.7.25; no stored routines or DELIMITER commands required.
-- Existing Synk tbl_college, tbl_academic_years and tbl_useraccount are required.
-- This installs schema only. It does not import catalog rows, assign accounts,
-- change the current academic term, or create payments.
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
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    audience_mode ENUM('ALL','SELECTED','UNSPECIFIED') NOT NULL DEFAULT 'UNSPECIFIED',
    status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
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
    status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
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
    event_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    contribution_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    audience_mode ENUM('ALL','SELECTED','UNSPECIFIED') NOT NULL DEFAULT 'UNSPECIFIED',
    status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
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

-- 5. Completion summary. No existing business rows were changed.
SELECT @synk_management_database AS database_name,
       'MANAGEMENT SCHEMA INSTALLED / UPDATED' AS result;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @synk_management_database
  AND TABLE_NAME IN ('tbl_management_organizations', 'tbl_management_contributions',
                     'tbl_management_events', 'tbl_management_event_sessions',
                     'tbl_management_violations')
ORDER BY TABLE_NAME;
