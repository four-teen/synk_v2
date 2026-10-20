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
