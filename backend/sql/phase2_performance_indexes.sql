-- Phase 2 Performance Indexes
-- Apply on the online database after uploading the latest codebase.
-- These indexes are intended for the slow scheduler/offering/prospectus pages.
-- For a safer import on mixed live schemas, use backend/sql/live_performance_patch_no_views.sql.
-- Note: some columns (e.g., ay_id/semester in tbl_sections) must exist in your DB.

ALTER TABLE tbl_prospectus_offering
  ADD INDEX idx_po_term_ps_section (prospectus_id, ay_id, semester, ps_id, section_id),
  ADD INDEX idx_po_term_program (ay_id, semester, program_id),
  ADD INDEX idx_po_term_section (ay_id, semester, section_id);

ALTER TABLE tbl_prospectus_year_sem
  ADD INDEX idx_pys_prospectus_sem_year (prospectus_id, semester, year_level, pys_id);

ALTER TABLE tbl_prospectus_subjects
  ADD INDEX idx_ps_pys_sort_sub (pys_id, sort_order, sub_id);

ALTER TABLE tbl_sections
  ADD INDEX idx_sections_program_term_level_status (program_id, ay_id, semester, year_level, status);

ALTER TABLE tbl_class_schedule
  ADD INDEX idx_cs_offering_type (offering_id, schedule_type),
  ADD INDEX idx_cs_room_time (room_id, time_start, time_end),
  ADD INDEX idx_cs_offering_time (offering_id, time_start);

ALTER TABLE tbl_program
  ADD INDEX idx_program_college_status (college_id, status, program_id);

ALTER TABLE tbl_faculty_workload
  ADD INDEX idx_fw_faculty_term_time (faculty_id, ay, semester, time_start, time_end),
  ADD INDEX idx_fw_room_term_time (room_id, ay, semester, time_start, time_end),
  ADD INDEX idx_fw_section_term_time (section_id, ay, semester, time_start, time_end);

ALTER TABLE tbl_rooms
  ADD INDEX idx_rooms_college_status_code (college_id, status, room_code);

ALTER TABLE tbl_college_faculty
  ADD INDEX idx_cf_college_status (college_id, status);

-- Used by workload and room-utilization joins.
ALTER TABLE tbl_faculty_workload_sched
  ADD INDEX idx_fws_term_faculty (ay_id, semester, faculty_id),
  ADD INDEX idx_fws_schedule_term (schedule_id, ay_id, semester);
