-- Manually recorded research in the professor faculty profile.
CREATE TABLE IF NOT EXISTS tbl_faculty_profile_researches (
  research_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_type ENUM('account','faculty') NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  research_title VARCHAR(500) NOT NULL,
  research_scope ENUM('Local','International') NOT NULL,
  funding_type ENUM('Funded','Personal') NOT NULL,
  conference_name VARCHAR(300) NOT NULL DEFAULT '',
  conference_date DATE NULL,
  publisher VARCHAR(300) NOT NULL DEFAULT '',
  publisher_link VARCHAR(2048) NOT NULL DEFAULT '',
  scopus_link VARCHAR(2048) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (research_id),
  KEY research_owner (owner_type, owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
