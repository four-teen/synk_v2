-- Run this ENTIRE file in the separate LMS database, never the main Synk database.
-- Existing attempts are preserved on their original version before the first edit.
-- Safe to rerun. No student, academic, or Management tables are modified.
SET @synk_lms_snapshot_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_lms_assessment_attempts'
      AND COLUMN_NAME='assessment_snapshot_json'
);
SET @synk_lms_snapshot_sql = IF(@synk_lms_snapshot_column=0,
    'ALTER TABLE tbl_lms_assessment_attempts ADD COLUMN assessment_snapshot_json MEDIUMTEXT NULL AFTER answers_json',
    'SELECT ''Assessment version storage is already ready'' AS result');
PREPARE synk_lms_snapshot_upgrade FROM @synk_lms_snapshot_sql;
EXECUTE synk_lms_snapshot_upgrade;
DEALLOCATE PREPARE synk_lms_snapshot_upgrade;
SET @synk_lms_snapshot_column = NULL;
SET @synk_lms_snapshot_sql = NULL;
