-- Permanent affiliations and lifecycle status; scheduler rosters remain term-based.
ALTER TABLE tbl_faculty
  MODIFY COLUMN status ENUM('active','inactive','resigned','retired','on_leave','other') NULL DEFAULT 'active',
  ADD COLUMN campus_id INT(10) UNSIGNED NULL,
  ADD COLUMN college_id INT(10) UNSIGNED NULL,
  ADD COLUMN remarks TEXT NULL,
  ADD COLUMN record_revision INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN record_updated_at DATETIME NULL,
  ADD COLUMN record_updated_by INT NULL;
