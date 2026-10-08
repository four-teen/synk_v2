-- Shared suggestions contributed by saved faculty profiles.
-- Profile text and account ownership remain in tbl_faculty_profiles.
CREATE TABLE IF NOT EXISTS tbl_faculty_education_options (
  education_option_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  option_type ENUM('degree', 'institution', 'address', 'specialization') NOT NULL,
  value VARCHAR(500) NOT NULL,
  value_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (education_option_id),
  UNIQUE KEY uniq_faculty_education_value (option_type, value_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
