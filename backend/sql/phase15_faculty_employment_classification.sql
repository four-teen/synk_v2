-- ======================================================================
-- Phase 15: Faculty employment classification
-- ======================================================================
-- Adds the faculty-level employment classification used by the scheduler
-- faculty assignment management page.

ALTER TABLE tbl_faculty
  ADD COLUMN employment_classification ENUM(
    'permanent',
    'temporary',
    'contract_of_service',
    'part_time'
  ) NULL DEFAULT NULL AFTER ext_name;
