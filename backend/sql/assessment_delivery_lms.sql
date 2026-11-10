-- Separate LMS database only. One attempt per student per published assessment.
CREATE TABLE IF NOT EXISTS tbl_lms_assessment_attempts (
  attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  answers_json MEDIUMTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL,
  deadline_at DATETIME NULL,
  submitted_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_lms_assessment_student (assessment_id,student_id),
  KEY idx_lms_attempt_student (student_id,assessment_id),
  CONSTRAINT fk_lms_attempt_assessment FOREIGN KEY (assessment_id)
    REFERENCES tbl_lms_assessments (assessment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
