-- Capture the system academic term on new sign-in events. Follow with phase33
-- to recover legacy events within the known current-term period.
SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND COLUMN_NAME='ay_id'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD COLUMN ay_id INT UNSIGNED NULL DEFAULT NULL');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;

SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND COLUMN_NAME='semester'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD COLUMN semester TINYINT UNSIGNED NULL DEFAULT NULL');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;

SET @synk_login_term_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_student_login_activity' AND INDEX_NAME='idx_student_login_activity_term'),
    'SELECT 1', 'ALTER TABLE tbl_student_login_activity ADD KEY idx_student_login_activity_term (student_id,ay_id,semester,outcome)');
PREPARE synk_login_term_stmt FROM @synk_login_term_sql;
EXECUTE synk_login_term_stmt;
DEALLOCATE PREPARE synk_login_term_stmt;
