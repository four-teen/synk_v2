-- Run only against the separate LMS database using install_assessments_lms.php.
-- Core academic, workload, student and Management tables are never altered.
CREATE TABLE IF NOT EXISTS tbl_lms_assessments (
  assessment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  faculty_id INT UNSIGNED NOT NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  grading_period ENUM('midterm','final') NOT NULL,
  assessment_type ENUM('quiz','exam','assignment') NOT NULL,
  title VARCHAR(200) NOT NULL,
  instructions MEDIUMTEXT NOT NULL,
  topic VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
  total_points DECIMAL(8,2) NOT NULL DEFAULT 0,
  opens_at DATETIME NULL,
  due_at DATETIME NULL,
  time_limit_minutes SMALLINT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (assessment_id),
  KEY idx_lms_assessment_class (faculty_id,offering_id,ay_id,semester,grading_period,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_assessment_questions (
  question_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id INT UNSIGNED NOT NULL,
  question_type ENUM('multiple_choice','true_false','short_answer','essay') NOT NULL,
  prompt TEXT NOT NULL,
  points DECIMAL(8,2) NOT NULL,
  options_json LONGTEXT NULL,
  answer_key_json LONGTEXT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (question_id),
  KEY idx_lms_question_order (assessment_id,sort_order),
  CONSTRAINT fk_lms_question_assessment FOREIGN KEY (assessment_id)
    REFERENCES tbl_lms_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_assessment_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  details_json TEXT NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (log_id),
  KEY idx_lms_log_assessment (assessment_id,log_id),
  CONSTRAINT fk_lms_log_assessment FOREIGN KEY (assessment_id)
    REFERENCES tbl_lms_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
