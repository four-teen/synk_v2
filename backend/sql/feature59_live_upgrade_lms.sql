-- feature/59 database delta from main (including the current working tree).
-- Run in the separate LMS database configured by backend/db_config.local_lms.php.
-- Select that database in phpMyAdmin before importing this file.
-- Adds two tables only. Existing grade, academic, student and Management tables are unchanged.
-- No seed/backfill is needed: absent visibility records mean all student grades stay hidden.

CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_visibility (
  visibility_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  midterm_visible TINYINT UNSIGNED NOT NULL DEFAULT 0,
  final_visible TINYINT UNSIGNED NOT NULL DEFAULT 0,
  overall_visible TINYINT UNSIGNED NOT NULL DEFAULT 0,
  version INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (visibility_id),
  UNIQUE KEY uq_lms_gradebook_visibility (faculty_id,offering_id,ay_id,semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_visibility_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visibility_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  grade_part ENUM('midterm','final','overall') NOT NULL,
  was_visible TINYINT UNSIGNED NOT NULL,
  is_visible TINYINT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  UNIQUE KEY uq_lms_gradebook_visibility_log (visibility_id,version),
  CONSTRAINT fk_lms_gradebook_visibility_log FOREIGN KEY (visibility_id) REFERENCES tbl_lms_gradebook_visibility (visibility_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify that both visibility tables are present after import.
SHOW TABLES LIKE 'tbl_lms_gradebook_visibility%';
