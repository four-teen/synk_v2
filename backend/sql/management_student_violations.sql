-- Fresh student-specific Management violations. No legacy imports or profile writes.
CREATE TABLE IF NOT EXISTS tbl_management_student_violations (
    student_violation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    violation_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    occurred_on DATE NOT NULL,
    violation_type VARCHAR(150) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    notes VARCHAR(1000) NOT NULL DEFAULT '',
    recorded_by INT NOT NULL,
    recorded_by_name VARCHAR(255) NOT NULL,
    recorded_at DATETIME NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (student_violation_id),
    UNIQUE KEY uq_management_student_violation_request (organization_id,request_key,violation_id),
    KEY ix_management_student_violation_period (college_id,organization_id,ay_id,semester,exam_term,student_id),
    KEY ix_management_student_violation_student (student_id,student_violation_id),
    CONSTRAINT fk_management_student_violation_student FOREIGN KEY (student_id) REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_catalog FOREIGN KEY (college_id,violation_id) REFERENCES tbl_management_violations (college_id,violation_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_org FOREIGN KEY (college_id,organization_id) REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_ay FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_student_violation_actor FOREIGN KEY (recorded_by) REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND COLUMN_NAME='student_violation_id'),
    'ALTER TABLE tbl_management_payment_items ADD COLUMN student_violation_id INT UNSIGNED NULL AFTER event_id',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;
SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND INDEX_NAME='ix_management_paid_violation'),
    'ALTER TABLE tbl_management_payment_items ADD INDEX ix_management_paid_violation (student_violation_id)',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;
SET @synk_student_violation_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
        AND TABLE_NAME='tbl_management_payment_items' AND CONSTRAINT_NAME='fk_management_paid_violation'),
    'ALTER TABLE tbl_management_payment_items ADD CONSTRAINT fk_management_paid_violation FOREIGN KEY (student_violation_id) REFERENCES tbl_management_student_violations (student_violation_id) ON DELETE RESTRICT',
    'DO 0');
PREPARE synk_student_violation_stmt FROM @synk_student_violation_ddl;
EXECUTE synk_student_violation_stmt;
DEALLOCATE PREPARE synk_student_violation_stmt;
