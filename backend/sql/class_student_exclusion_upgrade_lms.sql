-- Separate LMS database only. Preserves all existing statuses, notes and audit rows.
ALTER TABLE tbl_lms_class_student_statuses
  MODIFY COLUMN status ENUM('enrolled','dropped','excluded') NOT NULL DEFAULT 'enrolled';
ALTER TABLE tbl_lms_class_student_status_logs
  MODIFY COLUMN previous_status ENUM('enrolled','dropped','excluded') NOT NULL,
  MODIFY COLUMN status ENUM('enrolled','dropped','excluded') NOT NULL;
