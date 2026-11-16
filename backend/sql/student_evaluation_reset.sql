-- Additive operation history only. Never clears evaluations or changes settings.
CREATE TABLE IF NOT EXISTS tbl_student_evaluation_reset_history (
  reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_key CHAR(64) NOT NULL,
  campus_id INT UNSIGNED NOT NULL,
  college_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  student_count INT UNSIGNED NOT NULL,
  submitted_count INT UNSIGNED NOT NULL,
  draft_count INT UNSIGNED NOT NULL,
  answer_count INT UNSIGNED NOT NULL,
  snapshot_count INT UNSIGNED NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  actor_name VARCHAR(255) NOT NULL,
  reset_at DATETIME NOT NULL,
  PRIMARY KEY (reset_id),
  UNIQUE KEY uq_student_evaluation_reset_operation (operation_key),
  KEY ix_student_evaluation_reset_scope (college_id,ay_id,semester,reset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
