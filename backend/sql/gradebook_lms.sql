-- Separate LMS database only. No core, schedule, workload or Management changes.
CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_periods (
  book_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  grading_period ENUM('midterm','final') NOT NULL,
  scheme_id INT UNSIGNED NOT NULL,
  scheme_version INT UNSIGNED NOT NULL,
  scheme_snapshot_json MEDIUMTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (book_id),
  UNIQUE KEY uq_lms_gradebook_period (faculty_id,offering_id,ay_id,semester,grading_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student grade release is independent for each professor/class/academic term.
-- Existing and new classes stay hidden until their professor explicitly shows them.
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

CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_items (
  item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id INT UNSIGNED NOT NULL,
  component_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  entry_kind ENUM('score','attendance','lms') NOT NULL,
  assessment_id INT UNSIGNED NULL,
  max_points DECIMAL(10,2) NOT NULL,
  recorded_date DATE NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_lms_gradebook_assessment (book_id,assessment_id),
  KEY idx_lms_gradebook_component (book_id,status,component_id),
  CONSTRAINT fk_lms_gradebook_item_book FOREIGN KEY (book_id) REFERENCES tbl_lms_gradebook_periods (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_scores (
  score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  points DECIMAL(10,2) NOT NULL,
  remark VARCHAR(1000) NOT NULL DEFAULT '',
  actor_user_id INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (score_id),
  UNIQUE KEY uq_lms_gradebook_score (item_id,student_id),
  CONSTRAINT fk_lms_gradebook_score_item FOREIGN KEY (item_id) REFERENCES tbl_lms_gradebook_items (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_gradebook_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL,
  details_json MEDIUMTEXT NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  KEY idx_lms_gradebook_log (book_id,version),
  CONSTRAINT fk_lms_gradebook_log_book FOREIGN KEY (book_id) REFERENCES tbl_lms_gradebook_periods (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
