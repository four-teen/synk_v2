-- Kawani approval and Google identity for shared employee records.
CREATE TABLE IF NOT EXISTS tbl_hr_employee_accounts (
    account_id INT NOT NULL AUTO_INCREMENT,
    faculty_id INT UNSIGNED NULL,
    synk_user_id INT NULL,
    legacy_user_id INT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    google_sub VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    role_code ENUM('hris_admin', 'hris_user', 'conflict') NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_hr_employee_account_faculty (faculty_id),
    UNIQUE KEY uq_hr_employee_account_synk (synk_user_id),
    UNIQUE KEY uq_hr_employee_account_legacy (legacy_user_id),
    UNIQUE KEY uq_hr_employee_account_email (email),
    UNIQUE KEY uq_hr_employee_account_google (google_sub),
    KEY idx_hr_employee_account_role (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry forward existing Kawani grants. Retain the original reference so reruns preserve later HR decisions.
INSERT INTO tbl_hr_employee_accounts (faculty_id, synk_user_id, legacy_user_id, email, google_sub, role_code)
SELECT l.faculty_id, u.user_id, u.user_id, LOWER(TRIM(u.email)), NULLIF(TRIM(u.google_sub), ''), r.role_code
FROM tbl_useraccount u
JOIN (
    SELECT user_id, CASE WHEN COUNT(*) = 1 THEN MIN(role_code) ELSE 'conflict' END AS role_code
    FROM tbl_hr_user_roles
    WHERE status = 'active' AND role_code IN ('hris_admin', 'hris_user')
    GROUP BY user_id
) r ON r.user_id = u.user_id
JOIN (
    SELECT CONVERT(LOWER(TRIM(a.email)) USING ascii) COLLATE ascii_general_ci AS email
    FROM tbl_useraccount a JOIN tbl_hr_user_roles g ON g.user_id = a.user_id
    WHERE g.status = 'active' AND g.role_code IN ('hris_admin', 'hris_user')
        AND CHAR_LENGTH(TRIM(a.email)) BETWEEN 1 AND 254 AND TRIM(a.email) REGEXP '^[!-~]+$'
    GROUP BY CONVERT(LOWER(TRIM(a.email)) USING ascii) COLLATE ascii_general_ci
    HAVING COUNT(DISTINCT a.user_id) = 1
) approved ON approved.email = CONVERT(LOWER(TRIM(u.email)) USING ascii) COLLATE ascii_general_ci
LEFT JOIN tbl_useraccount_faculty_links l ON l.user_id = u.user_id
ON DUPLICATE KEY UPDATE account_id = tbl_hr_employee_accounts.account_id;

-- Preserve reviewer attribution for employees with a Kawani-only account.
SET @kawani_status_reviewer_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_hr_faculty_statuses')
    AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tbl_hr_faculty_statuses' AND COLUMN_NAME = 'updated_by_hr_account'),
    'ALTER TABLE tbl_hr_faculty_statuses
        MODIFY COLUMN updated_by INT NULL,
        ADD COLUMN updated_by_hr_account INT NULL AFTER updated_by,
        ADD CONSTRAINT fk_hr_faculty_status_hr_account FOREIGN KEY (updated_by_hr_account)
            REFERENCES tbl_hr_employee_accounts (account_id) ON DELETE RESTRICT',
    'DO 0'
);
PREPARE kawani_status_reviewer_stmt FROM @kawani_status_reviewer_ddl;
EXECUTE kawani_status_reviewer_stmt;
DEALLOCATE PREPARE kawani_status_reviewer_stmt;
