-- Training portfolio entries owned by a professor's faculty profile.
CREATE TABLE IF NOT EXISTS tbl_faculty_profile_trainings (
  training_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_type ENUM('account','faculty') NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  training_title VARCHAR(500) NOT NULL,
  agency VARCHAR(300) NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  venue VARCHAR(300) NOT NULL,
  mode ENUM('Face to face','Online') NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (training_id),
  KEY training_owner (owner_type, owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
