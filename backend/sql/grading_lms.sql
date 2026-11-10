-- Install only in the separate LMS database with install_grading_lms.php.
CREATE TABLE IF NOT EXISTS tbl_lms_grading_schemes (
  scheme_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  grading_period ENUM('midterm','final') NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (scheme_id),
  UNIQUE KEY uq_lms_grading_class (faculty_id,offering_id,ay_id,semester,grading_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_grading_components (
  component_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scheme_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  weight_bps SMALLINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (component_id),
  KEY idx_lms_grading_components (scheme_id,status,sort_order),
  CONSTRAINT fk_lms_grading_component_scheme FOREIGN KEY (scheme_id)
    REFERENCES tbl_lms_grading_schemes (scheme_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_grading_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scheme_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  details_json MEDIUMTEXT NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  KEY idx_lms_grading_log (scheme_id,version),
  CONSTRAINT fk_lms_grading_log_scheme FOREIGN KEY (scheme_id)
    REFERENCES tbl_lms_grading_schemes (scheme_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
