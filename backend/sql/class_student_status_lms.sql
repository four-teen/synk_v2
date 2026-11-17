-- Run only in the separate LMS database. Status is local to a professor's class and term.
CREATE TABLE IF NOT EXISTS tbl_lms_class_student_statuses (
  status_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status ENUM('enrolled','dropped') NOT NULL DEFAULT 'enrolled',
  reason VARCHAR(500) NOT NULL DEFAULT '',
  version INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by_user_id INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (status_id),
  UNIQUE KEY uq_lms_class_student_status (faculty_id,offering_id,ay_id,semester,student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_class_student_status_logs (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  previous_status ENUM('enrolled','dropped') NOT NULL,
  status ENUM('enrolled','dropped') NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  version INT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  KEY idx_lms_student_status_log (status_id,log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
