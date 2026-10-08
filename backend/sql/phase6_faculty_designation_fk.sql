-- ======================================================================
-- Phase 6: Faculty → Designation relationship
-- ======================================================================
-- Adds a faculty designation reference so each faculty record stores one
-- selected designation from tbl_designation.

ALTER TABLE tbl_faculty
  ADD COLUMN designation_id int(10) UNSIGNED NULL DEFAULT NULL AFTER ext_name,
  ADD INDEX idx_faculty_designation (designation_id);

ALTER TABLE tbl_faculty
  ADD CONSTRAINT fk_faculty_designation
  FOREIGN KEY (designation_id)
  REFERENCES tbl_designation (designation_id)
  ON UPDATE CASCADE
  ON DELETE SET NULL;

