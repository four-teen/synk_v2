-- feature/66: main application database schema changes, including working-tree changes.
-- Select the LIVE MAIN/PROFILE database in phpMyAdmin before running this file.
-- Target: MariaDB 10.4, as recorded in docs/live-schema-comparison-2026-10-27.md.
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax; do not run this file on MySQL 5.7.
-- Adds training storage and five optional research publication fields only.
-- Existing research values are preserved; new publication fields default to ''.
-- Table creation and column additions can be rerun after partial execution.
-- No seed data, history imports, record updates, deletes, or table drops.

-- Confirm the selected database and server version before running the DDL below.
SELECT DATABASE() AS selected_database, VERSION() AS database_version;

-- 1. Research storage was introduced previously. Create it only if it is absent.
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
  author_role VARCHAR(20) NOT NULL DEFAULT '',
  indexing VARCHAR(300) NOT NULL DEFAULT '',
  issn_number VARCHAR(32) NOT NULL DEFAULT '',
  volume VARCHAR(50) NOT NULL DEFAULT '',
  issue VARCHAR(50) NOT NULL DEFAULT '',
  publisher_link VARCHAR(2048) NOT NULL DEFAULT '',
  scopus_link VARCHAR(2048) NOT NULL DEFAULT '',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (research_id),
  KEY research_owner (owner_type, owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Upgrade an existing research table without changing its original fields.
ALTER TABLE `tbl_faculty_profile_researches`
  ADD COLUMN IF NOT EXISTS `author_role` VARCHAR(20) NOT NULL DEFAULT '' AFTER `publisher`,
  ADD COLUMN IF NOT EXISTS `indexing` VARCHAR(300) NOT NULL DEFAULT '' AFTER `author_role`,
  ADD COLUMN IF NOT EXISTS `issn_number` VARCHAR(32) NOT NULL DEFAULT '' AFTER `indexing`,
  ADD COLUMN IF NOT EXISTS `volume` VARCHAR(50) NOT NULL DEFAULT '' AFTER `issn_number`,
  ADD COLUMN IF NOT EXISTS `issue` VARCHAR(50) NOT NULL DEFAULT '' AFTER `volume`;

-- 3. New professor training portfolio storage.
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

-- 4. Read-only verification: expect the five new research columns and training table.
SHOW COLUMNS FROM `tbl_faculty_profile_researches`
  WHERE Field IN ('author_role', 'indexing', 'issn_number', 'volume', 'issue');
SHOW CREATE TABLE `tbl_faculty_profile_trainings`;
