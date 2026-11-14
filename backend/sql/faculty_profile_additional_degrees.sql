-- Additive profile storage. Existing primary education fields stay unchanged.
CREATE TABLE IF NOT EXISTS tbl_faculty_profile_additional_degrees (
  owner_type ENUM('account','faculty') NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  degrees_data LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_type,owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
