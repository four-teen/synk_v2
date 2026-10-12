-- Profiles can be started for a faculty record before a login account is linked.
-- Existing account-owned profiles remain the authoritative profile when present.
CREATE TABLE IF NOT EXISTS tbl_faculty_record_profiles (
  faculty_id INT UNSIGNED NOT NULL,
  profile_data LONGTEXT NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NOT NULL,
  PRIMARY KEY (faculty_id),
  CONSTRAINT fk_faculty_record_profile_faculty FOREIGN KEY (faculty_id)
    REFERENCES tbl_faculty (faculty_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
