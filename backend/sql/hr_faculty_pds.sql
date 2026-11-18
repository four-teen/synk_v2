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
