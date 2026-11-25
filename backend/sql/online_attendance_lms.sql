-- Run in the separate LMS database after gradebook_lms.sql.
-- Attendance opening, closing, recording, and audit timestamps are stored in UTC.
CREATE TABLE IF NOT EXISTS tbl_lms_online_attendance_periods (
  attendance_period_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  grading_period ENUM('midterm','final') NOT NULL,
  gradebook_item_id INT UNSIGNED NULL,
  applied_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  applied_at DATETIME NULL,
  PRIMARY KEY (attendance_period_id),
  UNIQUE KEY uq_lms_online_attendance_scope (faculty_id,offering_id,ay_id,semester,grading_period),
  UNIQUE KEY uq_lms_online_attendance_grade (gradebook_item_id),
  KEY ix_lms_online_attendance_term (ay_id,semester,offering_id,faculty_id),
  CONSTRAINT fk_lms_online_attendance_grade FOREIGN KEY (gradebook_item_id)
    REFERENCES tbl_lms_gradebook_items (item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_online_attendance_sessions (
  session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendance_period_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  opens_at DATETIME NOT NULL,
  closes_at DATETIME NOT NULL,
  cancelled_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (session_id),
  UNIQUE KEY uq_lms_online_attendance_request (attendance_period_id,request_key),
  KEY ix_lms_online_attendance_window (attendance_period_id,opens_at,session_id),
  CONSTRAINT fk_lms_online_attendance_period FOREIGN KEY (attendance_period_id)
    REFERENCES tbl_lms_online_attendance_periods (attendance_period_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_online_attendance_roster (
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  student_number VARCHAR(100) NOT NULL,
  student_name VARCHAR(500) NOT NULL,
  checked_in_at DATETIME NULL,
  override_status ENUM('present','absent') NULL,
  override_reason VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (session_id,student_id),
  CONSTRAINT fk_lms_online_attendance_roster FOREIGN KEY (session_id)
    REFERENCES tbl_lms_online_attendance_sessions (session_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_online_attendance_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  action ENUM('scheduled','rescheduled','corrected','cancelled') NOT NULL,
  details_json MEDIUMTEXT NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  KEY ix_lms_online_attendance_log (session_id,log_id),
  CONSTRAINT fk_lms_online_attendance_log FOREIGN KEY (session_id)
    REFERENCES tbl_lms_online_attendance_sessions (session_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
