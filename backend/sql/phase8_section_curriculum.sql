-- Phase 8: Section Curriculum Ownership
-- Create this table manually before using mixed-curriculum section assignment.

CREATE TABLE tbl_section_curriculum (
  section_curriculum_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id INT(10) UNSIGNED NOT NULL,
  prospectus_id INT(10) UNSIGNED NOT NULL,
  assigned_by INT(10) UNSIGNED DEFAULT NULL,
  date_assigned DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (section_curriculum_id),
  UNIQUE KEY uq_section_curriculum_section (section_id),
  KEY idx_section_curriculum_prospectus (prospectus_id),
  CONSTRAINT fk_section_curriculum_section
    FOREIGN KEY (section_id) REFERENCES tbl_sections (section_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_section_curriculum_prospectus
    FOREIGN KEY (prospectus_id) REFERENCES tbl_prospectus_header (prospectus_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
