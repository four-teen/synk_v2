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
