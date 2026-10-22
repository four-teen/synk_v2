-- Select the application database before running this script.

-- Combined student activity migrations: phase27, phase31, phase32 and phase33.

-- Existing tables and columns are retained; this script can be rerun.

-- One row per student sign-in outcome. No page visits, sessions or OAuth tokens.
-- Existing grouped tbl_student_login_issues diagnostics remain unchanged.
CREATE TABLE IF NOT EXISTS tbl_student_login_activity (
    activity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NULL DEFAULT NULL,
    submitted_email VARCHAR(255) NOT NULL DEFAULT '',
    outcome ENUM('success','failure') NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    identity_verified TINYINT UNSIGNED NOT NULL DEFAULT 0,
    auth_method VARCHAR(32) NOT NULL DEFAULT 'google',
    ip_address VARCHAR(45) CHARACTER SET ascii NOT NULL DEFAULT '',
    user_agent VARCHAR(512) NOT NULL DEFAULT '',
    browser VARCHAR(40) NOT NULL DEFAULT '',
    operating_system VARCHAR(40) NOT NULL DEFAULT '',
    device_type VARCHAR(16) NOT NULL DEFAULT '',
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id),
    KEY idx_student_login_activity_history (student_id,occurred_at,activity_id),
    KEY idx_student_login_activity_outcome (student_id,outcome,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Live presence is separate from successful sign-in history.
-- The random presence key is not a PHP session ID, cookie or OAuth token.
CREATE TABLE IF NOT EXISTS tbl_student_portal_sessions (
    presence_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    signed_in_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    ended_at DATETIME NULL DEFAULT NULL,
    end_reason VARCHAR(24) NOT NULL DEFAULT '',
    PRIMARY KEY (presence_key),
    KEY idx_student_presence (student_id,ended_at,last_activity_at,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Capture the system academic term on new sign-in events. Follow with phase33
-- to recover legacy events within the known current-term period.
SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND COLUMN_NAME='ay_id'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD COLUMN ay_id INT UNSIGNED NULL DEFAULT NULL');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;

SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND COLUMN_NAME='semester'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD COLUMN semester TINYINT UNSIGNED NULL DEFAULT NULL');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;

SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND INDEX_NAME='idx_student_login_activity_term'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD KEY idx_student_login_activity_term (student_id,ay_id,semester,outcome)');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;


-- Recover legacy events from the period for which the current term is known.
-- Academic-settings saves use database-local NOW(); login events use UTC_TIMESTAMP().
-- Convert the settings boundary to UTC before comparing. A later settings save
-- makes recovery conservative. Never infer terms from student profiles.
UPDATE tbl_student_login_activity a
JOIN (
    SELECT s.current_ay_id AS ay_id, s.current_semester AS semester,
        TIMESTAMPADD(SECOND, TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()), s.date_updated) AS known_since_utc
    FROM tbl_academic_settings s
    JOIN tbl_academic_years ay ON ay.ay_id=s.current_ay_id
    WHERE s.status='active' AND s.current_semester BETWEEN 1 AND 3
        AND s.date_updated>'1970-01-01 00:00:00'
    ORDER BY s.setting_id
    LIMIT 1
) term ON a.occurred_at>=term.known_since_utc AND a.occurred_at<=UTC_TIMESTAMP()
SET a.ay_id=term.ay_id, a.semester=term.semester
WHERE a.ay_id IS NULL AND a.semester IS NULL;


SELECT ROW_COUNT() AS recovered_login_events;