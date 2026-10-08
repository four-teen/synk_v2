CREATE TABLE tbl_program_schedule_set (
  schedule_set_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  college_id INT(10) UNSIGNED NOT NULL,
  program_id INT(10) UNSIGNED NOT NULL,
  ay_id INT(10) UNSIGNED NOT NULL,
  semester TINYINT(3) UNSIGNED NOT NULL,
  set_name VARCHAR(120) NOT NULL,
  remarks VARCHAR(255) DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (schedule_set_id),
  UNIQUE KEY uq_program_schedule_set_name (college_id, program_id, ay_id, semester, set_name),
  KEY idx_program_schedule_scope (college_id, program_id, ay_id, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tbl_program_schedule_set_row (
  schedule_set_row_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_set_id INT(10) UNSIGNED NOT NULL,
  offering_id INT(10) UNSIGNED NOT NULL,
  program_id INT(10) UNSIGNED NOT NULL,
  ps_id INT(10) UNSIGNED NOT NULL,
  section_id INT(10) UNSIGNED NOT NULL,
  schedule_type VARCHAR(10) NOT NULL DEFAULT 'LEC',
  block_order SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
  room_id INT(10) UNSIGNED NOT NULL,
  days_json TEXT NOT NULL,
  time_start TIME NOT NULL,
  time_end TIME NOT NULL,
  subject_code_snapshot VARCHAR(50) NOT NULL DEFAULT '',
  subject_description_snapshot VARCHAR(255) NOT NULL DEFAULT '',
  section_name_snapshot VARCHAR(120) NOT NULL DEFAULT '',
  room_label_snapshot VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (schedule_set_row_id),
  UNIQUE KEY uq_program_schedule_set_row_block (schedule_set_id, offering_id, schedule_type, block_order),
  KEY idx_program_schedule_set_row_schedule_set (schedule_set_id),
  KEY idx_program_schedule_set_row_offering (offering_id),
  CONSTRAINT fk_program_schedule_set_row_set
    FOREIGN KEY (schedule_set_id) REFERENCES tbl_program_schedule_set (schedule_set_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_program_schedule_set_workload_row (
  schedule_set_workload_row_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_set_id INT(10) UNSIGNED NOT NULL,
  schedule_set_row_id INT(10) UNSIGNED NOT NULL,
  offering_id INT(10) UNSIGNED NOT NULL,
  program_id INT(10) UNSIGNED NOT NULL,
  schedule_type VARCHAR(10) NOT NULL DEFAULT 'LEC',
  block_order SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
  faculty_id INT(10) UNSIGNED NOT NULL,
  faculty_name_snapshot VARCHAR(180) NOT NULL DEFAULT '',
  subject_code_snapshot VARCHAR(50) NOT NULL DEFAULT '',
  section_name_snapshot VARCHAR(120) NOT NULL DEFAULT '',
  date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (schedule_set_workload_row_id),
  UNIQUE KEY uq_pss_workload_row_faculty (schedule_set_id, schedule_set_row_id, faculty_id),
  KEY idx_pss_workload_set (schedule_set_id),
  KEY idx_pss_workload_set_row (schedule_set_row_id),
  KEY idx_pss_workload_faculty (faculty_id),
  CONSTRAINT fk_pss_workload_set
    FOREIGN KEY (schedule_set_id) REFERENCES tbl_program_schedule_set (schedule_set_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
