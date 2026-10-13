-- Designations are faculty appointments for an academic term, independent of
-- college-list membership. Names and units are retained if the catalog changes.
CREATE TABLE IF NOT EXISTS tbl_faculty_term_designations (
  faculty_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  designation_id INT UNSIGNED NULL,
  designation_name VARCHAR(120) NOT NULL DEFAULT '',
  designation_units DECIMAL(6,2) NOT NULL DEFAULT 0,
  record_source VARCHAR(16) NOT NULL DEFAULT 'legacy',
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (faculty_id, ay_id, semester),
  KEY idx_term_designation (ay_id, semester),
  CONSTRAINT fk_term_designation_faculty FOREIGN KEY (faculty_id)
    REFERENCES tbl_faculty (faculty_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve the currently known values for recorded terms. The old schema did
-- not record historical changes, so these are baselines, not recovered history.
INSERT INTO tbl_faculty_term_designations
  (faculty_id, ay_id, semester, designation_id, designation_name, designation_units)
SELECT f.faculty_id, t.ay_id, t.semester, f.designation_id,
       COALESCE(d.designation_name, ''), COALESCE(d.designation_units, 0)
FROM tbl_faculty f
CROSS JOIN (
  SELECT ay_id, semester FROM tbl_college_faculty WHERE ay_id > 0 AND semester BETWEEN 1 AND 3
  UNION SELECT ay_id, semester FROM tbl_faculty_workload_sched WHERE ay_id > 0 AND semester BETWEEN 1 AND 3
  UNION SELECT current_ay_id, current_semester FROM tbl_academic_settings WHERE status = 'active'
) t
LEFT JOIN tbl_designation d ON d.designation_id = f.designation_id
WHERE t.ay_id > 0 AND t.semester BETWEEN 1 AND 3
ON DUPLICATE KEY UPDATE faculty_id = VALUES(faculty_id);
