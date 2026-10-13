-- Student sign-in diagnostics only. No scheduling, workload or roster changes.
CREATE TABLE IF NOT EXISTS tbl_student_login_issues (
  issue_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  submitted_email VARCHAR(255) NOT NULL,
  google_email VARCHAR(255) NOT NULL DEFAULT '',
  google_name VARCHAR(255) NOT NULL DEFAULT '',
  failure_code VARCHAR(64) NOT NULL,
  identity_verified TINYINT UNSIGNED NOT NULL DEFAULT 0,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  review_status ENUM('open','reviewed') NOT NULL DEFAULT 'open',
  review_note VARCHAR(1000) NOT NULL DEFAULT '',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (issue_id),
  UNIQUE KEY uk_student_login_issue (issue_key),
  KEY idx_student_login_review (review_status,last_seen),
  KEY idx_student_login_email (submitted_email(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
