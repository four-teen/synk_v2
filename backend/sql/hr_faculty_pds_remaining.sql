-- Remaining faculty HR PDS sections. Additive storage only.
CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_eligibility (
  hr_profile_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_eligibility_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_eligibilities_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(500) NOT NULL DEFAULT '',
  `rating` VARCHAR(100) NOT NULL DEFAULT '',
  `examination_date` DATE NULL,
  `examination_place` VARCHAR(500) NOT NULL DEFAULT '',
  `license_number` VARCHAR(100) NOT NULL DEFAULT '',
  `valid_until` DATE NULL,
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_eligibilities_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_eligibility (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_work (
  hr_profile_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_work_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_work_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `position` VARCHAR(500) NOT NULL DEFAULT '',
  `employer` VARCHAR(500) NOT NULL DEFAULT '',
  `date_from` VARCHAR(10) NULL,
  `current` TINYINT(1) NOT NULL DEFAULT 0,
  `date_to` VARCHAR(10) NULL,
  `salary` DECIMAL(12,2) NULL,
  `pay_grade` VARCHAR(100) NOT NULL DEFAULT '',
  `appointment_status` VARCHAR(150) NOT NULL DEFAULT '',
  `government_service` VARCHAR(30) NOT NULL DEFAULT '',
  `office_unit` VARCHAR(300) NOT NULL DEFAULT '',
  `supervisor` VARCHAR(250) NOT NULL DEFAULT '',
  `duties` TEXT NOT NULL,
  `accomplishments` TEXT NOT NULL,
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_work_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_work (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_learning (
  hr_profile_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_learning_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_trainings_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `training_title` VARCHAR(500) NOT NULL DEFAULT '',
  `date_from` DATE NULL,
  `date_to` DATE NULL,
  `hours` DECIMAL(12,2) NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT '',
  `type_other` VARCHAR(250) NOT NULL DEFAULT '',
  `agency` VARCHAR(500) NOT NULL DEFAULT '',
  `venue` VARCHAR(500) NOT NULL DEFAULT '',
  `mode` VARCHAR(30) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_trainings_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_learning (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_voluntary (
  hr_profile_id INT UNSIGNED NOT NULL,
  `voluntary_applies` VARCHAR(30) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_voluntary_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_voluntary_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `organization` VARCHAR(500) NOT NULL DEFAULT '',
  `address` VARCHAR(500) NOT NULL DEFAULT '',
  `date_from` DATE NULL,
  `date_to` DATE NULL,
  `hours` DECIMAL(12,2) NULL,
  `role` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_voluntary_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_voluntary (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_other (
  hr_profile_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_other_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_skills_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_skills_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_other (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_recognition_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_recognition_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_other (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_memberships_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_memberships_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_other (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_references_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(250) NOT NULL DEFAULT '',
  `address` VARCHAR(500) NOT NULL DEFAULT '',
  `contact` VARCHAR(300) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_references_section FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_other (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_declarations (
  hr_profile_id INT UNSIGNED NOT NULL,
  `q34a` VARCHAR(30) NOT NULL DEFAULT '',
  `q34a_details` TEXT NOT NULL,
  `q34b` VARCHAR(30) NOT NULL DEFAULT '',
  `q34b_details` TEXT NOT NULL,
  `q35a` VARCHAR(30) NOT NULL DEFAULT '',
  `q35a_details` TEXT NOT NULL,
  `q35b` VARCHAR(30) NOT NULL DEFAULT '',
  `q35b_details` TEXT NOT NULL,
  `q36` VARCHAR(30) NOT NULL DEFAULT '',
  `q36_details` TEXT NOT NULL,
  `q37` VARCHAR(30) NOT NULL DEFAULT '',
  `q37_details` TEXT NOT NULL,
  `q38a` VARCHAR(30) NOT NULL DEFAULT '',
  `q38a_details` TEXT NOT NULL,
  `q38b` VARCHAR(30) NOT NULL DEFAULT '',
  `q38b_details` TEXT NOT NULL,
  `q39` VARCHAR(30) NOT NULL DEFAULT '',
  `q40a` VARCHAR(30) NOT NULL DEFAULT '',
  `q40b` VARCHAR(30) NOT NULL DEFAULT '',
  `q40c` VARCHAR(30) NOT NULL DEFAULT '',
  `case_date` DATE NULL,
  `case_status` VARCHAR(500) NOT NULL DEFAULT '',
  `resident_country` VARCHAR(100) NOT NULL DEFAULT '',
  `indigenous_group` VARCHAR(250) NOT NULL DEFAULT '',
  `q40b_id` VARCHAR(100) NOT NULL DEFAULT '',
  `q40c_id` VARCHAR(100) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_declarations_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_certification (
  hr_profile_id INT UNSIGNED NOT NULL,
  `photo_taken_date` DATE NULL,
  `certification_id_type` VARCHAR(150) NOT NULL DEFAULT '',
  `certification_id_number` VARCHAR(100) NOT NULL DEFAULT '',
  `certification_id_date` DATE NULL,
  `certification_id_place` VARCHAR(300) NOT NULL DEFAULT '',
  `date_accomplished` DATE NULL,
  `oath_date` DATE NULL,
  `oath_officer` VARCHAR(250) NOT NULL DEFAULT '',
  `declaration_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `signature_date_1` DATE NULL,
  `signature_date_2` DATE NULL,
  `signature_date_3` DATE NULL,
  `signature_date_4` DATE NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_certification_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_attachments (
  hr_profile_id INT UNSIGNED NOT NULL,
  slot VARCHAR(40) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  content MEDIUMBLOB NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id,slot),
  CONSTRAINT fk_hr_pds_attachment_certification FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_certification (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
