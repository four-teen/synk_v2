-- feature/69 live upgrade: 24 new HR PDS tables; no ALTER TABLE statements.
-- Includes the current working-tree additions, compared with main at 44868c4.
-- In phpMyAdmin, select the LIVE MAIN APPLICATION database before importing.
-- This is the database containing tbl_faculty and tbl_useraccount, not the LMS database.
-- Existing parent keys must be indexed, InnoDB, and have these matching types:
--   tbl_faculty.faculty_id: INT UNSIGNED
--   tbl_useraccount.user_id: INT (signed)
-- Definitions are copied from hr_faculty_pds.sql followed by hr_faculty_pds_remaining.sql.
-- CREATE TABLE IF NOT EXISTS permits reruns but does not repair differing existing schemas.
-- Existing records are preserved. No seed data, imports, updates, deletes or drops.
-- Assessment transfers require no LMS schema change in this branch.
-- DDL commits independently; no transaction is used to imply rollback protection.

SELECT DATABASE() AS selected_main_database, VERSION() AS database_version;
SELECT t.TABLE_NAME, t.ENGINE, c.COLUMN_NAME, c.COLUMN_TYPE, c.COLUMN_KEY
FROM information_schema.TABLES t
JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
WHERE t.TABLE_SCHEMA=DATABASE()
  AND ((t.TABLE_NAME='tbl_faculty' AND c.COLUMN_NAME='faculty_id')
    OR (t.TABLE_NAME='tbl_useraccount' AND c.COLUMN_NAME='user_id'))
ORDER BY t.TABLE_NAME;

SET NAMES utf8mb4;

-- New tables in dependency order:
-- tbl_hr_faculty_profiles
-- tbl_hr_faculty_pds_personal
-- tbl_hr_faculty_pds_contact
-- tbl_hr_faculty_pds_family
-- tbl_hr_faculty_pds_spouses
-- tbl_hr_faculty_pds_children
-- tbl_hr_faculty_pds_education
-- tbl_hr_faculty_pds_education_records
-- tbl_hr_faculty_pds_eligibility
-- tbl_hr_faculty_pds_eligibilities_records
-- tbl_hr_faculty_pds_work
-- tbl_hr_faculty_pds_work_records
-- tbl_hr_faculty_pds_learning
-- tbl_hr_faculty_pds_trainings_records
-- tbl_hr_faculty_pds_voluntary
-- tbl_hr_faculty_pds_voluntary_records
-- tbl_hr_faculty_pds_other
-- tbl_hr_faculty_pds_skills_records
-- tbl_hr_faculty_pds_recognition_records
-- tbl_hr_faculty_pds_memberships_records
-- tbl_hr_faculty_pds_references_records
-- tbl_hr_faculty_pds_declarations
-- tbl_hr_faculty_pds_certification
-- tbl_hr_faculty_pds_attachments

-- HR-owned PDS data. Shared profiles, accounts, academic settings and LMS are unchanged.
CREATE TABLE IF NOT EXISTS tbl_hr_faculty_profiles (
  hr_profile_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NULL,
  account_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (hr_profile_id),
  UNIQUE KEY uq_hr_profile_faculty (faculty_id),
  UNIQUE KEY uq_hr_profile_account (account_user_id),
  CONSTRAINT fk_hr_profile_faculty FOREIGN KEY (faculty_id) REFERENCES tbl_faculty (faculty_id) ON DELETE RESTRICT,
  CONSTRAINT fk_hr_profile_account FOREIGN KEY (account_user_id) REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_personal (
  hr_profile_id INT UNSIGNED NOT NULL,
  surname VARCHAR(100) NOT NULL DEFAULT '',
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  middle_name VARCHAR(100) NOT NULL DEFAULT '',
  name_extension VARCHAR(30) NOT NULL DEFAULT '',
  date_of_birth DATE NULL,
  place_of_birth VARCHAR(250) NOT NULL DEFAULT '',
  sex_at_birth VARCHAR(10) NOT NULL DEFAULT '',
  civil_status VARCHAR(20) NOT NULL DEFAULT '',
  civil_status_other VARCHAR(100) NOT NULL DEFAULT '',
  height DECIMAL(4,2) NULL,
  weight DECIMAL(6,2) NULL,
  blood_type VARCHAR(10) NOT NULL DEFAULT '',
  citizenship VARCHAR(30) NOT NULL DEFAULT '',
  citizenship_basis VARCHAR(30) NOT NULL DEFAULT '',
  citizenship_country VARCHAR(100) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_personal_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_contact (
  hr_profile_id INT UNSIGNED NOT NULL,
  residential_house VARCHAR(250) NOT NULL DEFAULT '',
  residential_street VARCHAR(250) NOT NULL DEFAULT '',
  residential_village VARCHAR(250) NOT NULL DEFAULT '',
  residential_barangay VARCHAR(250) NOT NULL DEFAULT '',
  residential_city VARCHAR(250) NOT NULL DEFAULT '',
  residential_province VARCHAR(250) NOT NULL DEFAULT '',
  residential_zip VARCHAR(20) NOT NULL DEFAULT '',
  same_address TINYINT(1) NOT NULL DEFAULT 0,
  permanent_house VARCHAR(250) NOT NULL DEFAULT '',
  permanent_street VARCHAR(250) NOT NULL DEFAULT '',
  permanent_village VARCHAR(250) NOT NULL DEFAULT '',
  permanent_barangay VARCHAR(250) NOT NULL DEFAULT '',
  permanent_city VARCHAR(250) NOT NULL DEFAULT '',
  permanent_province VARCHAR(250) NOT NULL DEFAULT '',
  permanent_zip VARCHAR(20) NOT NULL DEFAULT '',
  telephone VARCHAR(50) NOT NULL DEFAULT '',
  mobile VARCHAR(50) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL DEFAULT '',
  umid VARCHAR(64) NOT NULL DEFAULT '',
  pagibig VARCHAR(64) NOT NULL DEFAULT '',
  philhealth VARCHAR(64) NOT NULL DEFAULT '',
  philsys_pcn VARCHAR(64) NOT NULL DEFAULT '',
  tin VARCHAR(64) NOT NULL DEFAULT '',
  agency_employee_number VARCHAR(64) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_contact_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_family (
  hr_profile_id INT UNSIGNED NOT NULL,
  spouse_applies VARCHAR(20) NOT NULL DEFAULT '',
  children_apply VARCHAR(10) NOT NULL DEFAULT '',
  father_surname VARCHAR(100) NOT NULL DEFAULT '',
  father_first_name VARCHAR(100) NOT NULL DEFAULT '',
  father_middle_name VARCHAR(100) NOT NULL DEFAULT '',
  father_name_extension VARCHAR(30) NOT NULL DEFAULT '',
  mother_surname VARCHAR(100) NOT NULL DEFAULT '',
  mother_first_name VARCHAR(100) NOT NULL DEFAULT '',
  mother_middle_name VARCHAR(100) NOT NULL DEFAULT '',
  mother_name_extension VARCHAR(30) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_family_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_spouses (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  surname VARCHAR(100) NOT NULL DEFAULT '',
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  middle_name VARCHAR(100) NOT NULL DEFAULT '',
  name_extension VARCHAR(30) NOT NULL DEFAULT '',
  occupation VARCHAR(250) NOT NULL DEFAULT '',
  employer VARCHAR(300) NOT NULL DEFAULT '',
  business_address VARCHAR(500) NOT NULL DEFAULT '',
  telephone VARCHAR(50) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_spouse_family FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_family (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_children (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  full_name VARCHAR(250) NOT NULL DEFAULT '',
  date_of_birth DATE NULL,
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_child_family FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_family (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_education (
  hr_profile_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (hr_profile_id),
  CONSTRAINT fk_hr_pds_education_profile FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_profiles (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_hr_faculty_pds_education_records (
  hr_profile_id INT UNSIGNED NOT NULL,
  row_order SMALLINT UNSIGNED NOT NULL,
  level VARCHAR(40) NOT NULL DEFAULT '',
  institution VARCHAR(300) NOT NULL DEFAULT '',
  degree VARCHAR(500) NOT NULL DEFAULT '',
  year_from SMALLINT UNSIGNED NULL,
  year_to SMALLINT UNSIGNED NULL,
  units VARCHAR(250) NOT NULL DEFAULT '',
  year_graduated SMALLINT UNSIGNED NULL,
  honors VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT '',
  address VARCHAR(500) NOT NULL DEFAULT '',
  specialization VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (hr_profile_id,row_order),
  CONSTRAINT fk_hr_pds_education_records FOREIGN KEY (hr_profile_id) REFERENCES tbl_hr_faculty_pds_education (hr_profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `date_from` DATE NULL,
  `current` TINYINT(1) NOT NULL DEFAULT 0,
  `date_to` DATE NULL,
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

-- Verification: expect all 24 HR tables, each using InnoDB.
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
  'tbl_hr_faculty_profiles',
  'tbl_hr_faculty_pds_personal',
  'tbl_hr_faculty_pds_contact',
  'tbl_hr_faculty_pds_family',
  'tbl_hr_faculty_pds_spouses',
  'tbl_hr_faculty_pds_children',
  'tbl_hr_faculty_pds_education',
  'tbl_hr_faculty_pds_education_records',
  'tbl_hr_faculty_pds_eligibility',
  'tbl_hr_faculty_pds_eligibilities_records',
  'tbl_hr_faculty_pds_work',
  'tbl_hr_faculty_pds_work_records',
  'tbl_hr_faculty_pds_learning',
  'tbl_hr_faculty_pds_trainings_records',
  'tbl_hr_faculty_pds_voluntary',
  'tbl_hr_faculty_pds_voluntary_records',
  'tbl_hr_faculty_pds_other',
  'tbl_hr_faculty_pds_skills_records',
  'tbl_hr_faculty_pds_recognition_records',
  'tbl_hr_faculty_pds_memberships_records',
  'tbl_hr_faculty_pds_references_records',
  'tbl_hr_faculty_pds_declarations',
  'tbl_hr_faculty_pds_certification',
  'tbl_hr_faculty_pds_attachments'
)
ORDER BY TABLE_NAME;
