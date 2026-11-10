-- Run this ENTIRE file in the main Synk database in phpMyAdmin.
-- Adds the two new evaluation tables; no existing table needs ALTER TABLE.
-- Student data is limited to ccsi@sksu.edu.ph and the three local enrollments below.
-- Existing live academic catalogs, faculty, workloads and schedules are read only.
-- IDs are resolved on live; local IDs and account passwords are not imported.
-- FROM DUAL is required for table-less conditional SELECTs on older MariaDB.
-- Set @synk_ccsi_apply = 0 before running for a rollback-only preview.

SET NAMES utf8mb4;
SET @synk_ccsi_apply = COALESCE(@synk_ccsi_apply,1);
SET @synk_ccsi_email = 'ccsi@sksu.edu.ph';
SET @synk_ccsi_number = '999990000001';
SET @synk_ccsi_ay_label = '2026-2027';
SET @synk_ccsi_semester = 1;
SET @synk_ccsi_reason = 'this is a test account';

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_student_exclusions (
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    excluded TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(1000) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (college_id,ay_id,semester,student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_student_exclusion_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    previous_value TEXT NOT NULL,
    new_value TEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_evaluation_student_audit (college_id,ay_id,semester,audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TEMPORARY TABLE IF EXISTS synk_ccsi_seed;
CREATE TEMPORARY TABLE synk_ccsi_seed (
    seed_id INT NOT NULL PRIMARY KEY,
    subject_code VARCHAR(50) NOT NULL,
    descriptive_title VARCHAR(255) NOT NULL,
    year_level TINYINT NOT NULL,
    section_text VARCHAR(150) NOT NULL,
    faculty_first_name VARCHAR(150) NOT NULL,
    faculty_middle_name VARCHAR(150) NOT NULL,
    faculty_last_name VARCHAR(150) NOT NULL,
    room_text VARCHAR(255) NOT NULL,
    schedule_text VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO synk_ccsi_seed VALUES
(1,'CPC 001','Data Structures and Algorithm',2,'BSCS 2A',
 'CECILIA','ESLAVA','GENER',
 'CCS 304 - BSCS 1; CCS 309 - BSCS LABORATORY',
 'LEC M, W 13:00-14:00; LAB T, Th 09:00-10:30'),
(2,'CS 114','DISCRETE STRUCTURES 2',2,'BSCS 2A',
 'KYRENE','LANCITA','DIZON',
 'CCS 305 - BSCS 2','LEC M, W 07:30-09:00'),
(3,'CS 111','Fundamentals of Programming',1,'BSCS 1A',
 'ELBREN','OSORIO','ANTONIO',
 'CCS 309 - BSCS LABORATORY | CCS 304 - BSCS 1',
 'LAB Tue, Thu 7:30 AM - 9:00 AM; LEC Mon, Wed 9:00 AM - 10:00 AM');

START TRANSACTION;

SELECT COUNT(*),MIN(ay_id) INTO @synk_ccsi_ay_count,@synk_ccsi_ay
FROM tbl_academic_years WHERE ay=@synk_ccsi_ay_label COLLATE utf8mb4_general_ci;
SELECT COUNT(*),MIN(c.campus_id),MIN(c.college_id)
INTO @synk_ccsi_college_count,@synk_ccsi_campus,@synk_ccsi_college
FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
WHERE cp.campus_code='ISU' AND c.college_code='CCS'
  AND cp.status='active' AND c.status='active';
SELECT COUNT(*),MIN(program_id) INTO @synk_ccsi_program_count,@synk_ccsi_program
FROM tbl_program WHERE college_id=@synk_ccsi_college AND program_code='BSCS' AND status='active';

-- Use the existing active assigned Dean for the exclusion audit.
SELECT COUNT(*),MIN(u.user_id) INTO @synk_ccsi_dean_count,@synk_ccsi_dean
FROM tbl_useraccount u
WHERE u.college_dean_college_id=@synk_ccsi_college AND u.status='active'
  AND (u.role='college_dean' OR EXISTS (
      SELECT 1 FROM tbl_useraccount_roles ur
      WHERE ur.user_id=u.user_id AND ur.role='college_dean' AND ur.status='active'
  ));

-- Serialize against normal Dean exclusion writes for the same college.
SELECT college_id AS locked_college_id FROM tbl_college
WHERE college_id=@synk_ccsi_college AND @synk_ccsi_college_count=1 FOR UPDATE;

DROP TEMPORARY TABLE IF EXISTS synk_ccsi_matches;
CREATE TEMPORARY TABLE synk_ccsi_matches AS
SELECT DISTINCT seed.seed_id,po.offering_id,po.section_id,sm.sub_id AS subject_id,f.faculty_id
FROM synk_ccsi_seed seed
JOIN tbl_subject_masterlist sm ON TRIM(CONVERT(sm.sub_code USING utf8mb4)) COLLATE utf8mb4_general_ci=seed.subject_code
JOIN tbl_prospectus_subjects ps ON ps.sub_id=sm.sub_id
JOIN tbl_prospectus_offering po ON po.ps_id=ps.ps_id
  AND po.program_id=@synk_ccsi_program AND po.ay_id=@synk_ccsi_ay
  AND po.semester=@synk_ccsi_semester AND po.year_level=seed.year_level AND po.status='active'
JOIN tbl_sections sec ON sec.section_id=po.section_id AND sec.program_id=po.program_id
  AND sec.ay_id=po.ay_id AND sec.semester=po.semester
  AND TRIM(CONVERT(sec.full_section USING utf8mb4)) COLLATE utf8mb4_general_ci=seed.section_text AND sec.status='active'
JOIN tbl_faculty f ON TRIM(CONVERT(f.first_name USING utf8mb4)) COLLATE utf8mb4_general_ci=seed.faculty_first_name
  AND TRIM(CONVERT(COALESCE(f.middle_name,'') USING utf8mb4)) COLLATE utf8mb4_general_ci=seed.faculty_middle_name
  AND TRIM(CONVERT(f.last_name USING utf8mb4)) COLLATE utf8mb4_general_ci=seed.faculty_last_name AND TRIM(COALESCE(f.ext_name,''))=''
WHERE EXISTS (
    SELECT 1 FROM tbl_class_schedule cs
    JOIN tbl_faculty_workload_sched fw ON fw.schedule_id=cs.schedule_id
    WHERE cs.offering_id=po.offering_id AND fw.faculty_id=f.faculty_id
      AND fw.ay_id=@synk_ccsi_ay AND fw.semester=@synk_ccsi_semester
);

SELECT COUNT(*),MIN(student_id) INTO @synk_ccsi_student_count,@synk_ccsi_student
FROM tbl_student_management WHERE LOWER(TRIM(email_address))=@synk_ccsi_email COLLATE utf8mb4_general_ci;
SELECT COUNT(*) INTO @synk_ccsi_number_conflicts FROM tbl_student_management
WHERE CONVERT(student_number USING utf8mb4) COLLATE utf8mb4_general_ci=@synk_ccsi_number
  AND LOWER(TRIM(email_address))<>@synk_ccsi_email COLLATE utf8mb4_general_ci;
SELECT COUNT(*) INTO @synk_ccsi_matched_subjects FROM (
    SELECT seed_id FROM synk_ccsi_matches GROUP BY seed_id HAVING COUNT(*)=1
) unique_matches;

SET @synk_ccsi_ready = @synk_ccsi_ay_count=1 AND @synk_ccsi_college_count=1
  AND @synk_ccsi_program_count=1 AND @synk_ccsi_dean_count=1
  AND @synk_ccsi_student_count<=1 AND @synk_ccsi_matched_subjects=3
  AND (@synk_ccsi_student_count=1 OR @synk_ccsi_number_conflicts=0);

-- Reuse the live student by email. Create only this student if it does not exist.
-- An existing live student profile is never overwritten.
INSERT INTO tbl_student_management
    (ay_id,semester,source_sheet_name,source_file_name,year_level,student_number,
     last_name,first_name,middle_name,suffix_name,email_address,program_id,source_row_number)
SELECT @synk_ccsi_ay,@synk_ccsi_semester,'TEST ACCOUNT','CCSI test account setup',2,
       @synk_ccsi_number,'ACCOUNT','TEST','','',@synk_ccsi_email,@synk_ccsi_program,0
FROM DUAL
WHERE @synk_ccsi_ready AND @synk_ccsi_student_count=0;

SELECT COUNT(*),MIN(student_id) INTO @synk_ccsi_student_count,@synk_ccsi_student
FROM tbl_student_management WHERE LOWER(TRIM(email_address))=@synk_ccsi_email COLLATE utf8mb4_general_ci;
SET @synk_ccsi_ready = @synk_ccsi_ready AND @synk_ccsi_student_count=1;

-- Only add missing active enrollments for this email; existing rows are preserved.
-- Generated active_student_id/active_offering_id columns are intentionally omitted.
INSERT INTO tbl_student_management_enrolled_subjects
    (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,
     year_level,section_id,section_text,offering_id,subject_id,subject_code,
     descriptive_title,student_id,faculty_id,room_id,room_text,schedule_text,
     source_row_number,uploaded_by,is_active)
SELECT 'ccsi-evaluation-live-20261111','CCSI test account setup',
       @synk_ccsi_campus,@synk_ccsi_college,@synk_ccsi_program,@synk_ccsi_ay,@synk_ccsi_semester,
       seed.year_level,m.section_id,seed.section_text,m.offering_id,m.subject_id,
       seed.subject_code,seed.descriptive_title,@synk_ccsi_student,m.faculty_id,0,
       seed.room_text,seed.schedule_text,seed.seed_id,NULL,1
FROM synk_ccsi_seed seed JOIN synk_ccsi_matches m ON m.seed_id=seed.seed_id
WHERE @synk_ccsi_ready AND NOT EXISTS (
    SELECT 1 FROM tbl_student_management_enrolled_subjects es
    WHERE es.student_id=@synk_ccsi_student AND es.offering_id=m.offering_id AND es.is_active=1
);

SELECT COUNT(*),COALESCE(MAX(excluded),0),COALESCE(MAX(reason),''),COALESCE(MAX(version),0)
INTO @synk_ccsi_old_count,@synk_ccsi_old_excluded,@synk_ccsi_old_reason,@synk_ccsi_old_version
FROM tbl_college_evaluation_student_exclusions
WHERE college_id=@synk_ccsi_college AND ay_id=@synk_ccsi_ay
  AND semester=@synk_ccsi_semester AND student_id=@synk_ccsi_student;
SELECT COALESCE(MAX(audit_id),0) INTO @synk_ccsi_last_audit
FROM tbl_college_evaluation_student_exclusion_audit;
SET @synk_ccsi_change = @synk_ccsi_ready AND
    (@synk_ccsi_old_count=0 OR @synk_ccsi_old_excluded<>1 OR @synk_ccsi_old_reason<>@synk_ccsi_reason COLLATE utf8mb4_general_ci);

-- Audit and rule update commit together. Re-running an identical rule is a no-op.
INSERT INTO tbl_college_evaluation_student_exclusion_audit
    (college_id,ay_id,semester,student_id,actor_user_id,previous_value,new_value)
SELECT @synk_ccsi_college,@synk_ccsi_ay,@synk_ccsi_semester,@synk_ccsi_student,@synk_ccsi_dean,
       JSON_OBJECT('excluded',@synk_ccsi_old_excluded,'reason',@synk_ccsi_old_reason,'version',@synk_ccsi_old_version),
       JSON_OBJECT('excluded',1,'reason',@synk_ccsi_reason,'version',@synk_ccsi_old_version+1)
FROM DUAL
WHERE @synk_ccsi_change;

INSERT INTO tbl_college_evaluation_student_exclusions
    (college_id,ay_id,semester,student_id,excluded,reason,version,updated_by)
SELECT @synk_ccsi_college,@synk_ccsi_ay,@synk_ccsi_semester,@synk_ccsi_student,
       1,@synk_ccsi_reason,@synk_ccsi_old_version+1,@synk_ccsi_dean
FROM DUAL
WHERE @synk_ccsi_change
ON DUPLICATE KEY UPDATE excluded=VALUES(excluded),reason=VALUES(reason),
    version=VALUES(version),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP;

-- Check all three memberships, the exclusion and its audit before committing.
SELECT COUNT(DISTINCT m.seed_id) INTO @synk_ccsi_verified_subjects
FROM synk_ccsi_matches m
JOIN tbl_student_management_enrolled_subjects es
  ON es.student_id=@synk_ccsi_student AND es.offering_id=m.offering_id
  AND es.subject_id=m.subject_id AND es.faculty_id=m.faculty_id AND es.section_id=m.section_id
  AND es.campus_id=@synk_ccsi_campus AND es.college_id=@synk_ccsi_college
  AND es.program_id=@synk_ccsi_program AND es.ay_id=@synk_ccsi_ay
  AND es.semester=@synk_ccsi_semester AND es.is_active=1;
SELECT COUNT(*) INTO @synk_ccsi_verified_rule FROM tbl_college_evaluation_student_exclusions
WHERE college_id=@synk_ccsi_college AND ay_id=@synk_ccsi_ay AND semester=@synk_ccsi_semester
  AND student_id=@synk_ccsi_student AND excluded=1 AND reason=@synk_ccsi_reason COLLATE utf8mb4_general_ci
  AND version=@synk_ccsi_old_version+IF(@synk_ccsi_change,1,0);
SELECT COUNT(*) INTO @synk_ccsi_verified_audit FROM tbl_college_evaluation_student_exclusion_audit
WHERE audit_id>@synk_ccsi_last_audit AND college_id=@synk_ccsi_college
  AND ay_id=@synk_ccsi_ay AND semester=@synk_ccsi_semester AND student_id=@synk_ccsi_student
  AND actor_user_id=@synk_ccsi_dean;
SET @synk_ccsi_validated = @synk_ccsi_ready AND @synk_ccsi_verified_subjects=3
  AND @synk_ccsi_verified_rule=1 AND @synk_ccsi_verified_audit=IF(@synk_ccsi_change,1,0);
SET @synk_ccsi_commit = @synk_ccsi_validated AND @synk_ccsi_apply=1;
SET @synk_ccsi_finish_sql = IF(@synk_ccsi_commit,'COMMIT','ROLLBACK');
PREPARE synk_ccsi_finish FROM @synk_ccsi_finish_sql;
EXECUTE synk_ccsi_finish;
DEALLOCATE PREPARE synk_ccsi_finish;

SELECT CASE WHEN @synk_ccsi_commit THEN 'APPLIED'
            WHEN @synk_ccsi_validated THEN 'VALIDATED - rolled back (preview)'
            ELSE 'NOT APPLIED - missing, ambiguous or conflicting live records; see checks below'
       END AS result,
       @synk_ccsi_email AS student_email,@synk_ccsi_student AS live_student_id,
       @synk_ccsi_ay_label AS academic_year,@synk_ccsi_semester AS semester,
       @synk_ccsi_ay_count AS matching_years,@synk_ccsi_college_count AS matching_colleges,
       @synk_ccsi_program_count AS matching_programs,@synk_ccsi_dean_count AS matching_deans,
       @synk_ccsi_student_count AS matching_students,@synk_ccsi_matched_subjects AS uniquely_matched_subjects,
       @synk_ccsi_verified_subjects AS verified_enrollments;
SELECT seed.subject_code,seed.descriptive_title,seed.section_text,
       CONCAT_WS(' ',seed.faculty_first_name,seed.faculty_middle_name,seed.faculty_last_name) AS teacher,
       COUNT(m.seed_id) AS live_matches
FROM synk_ccsi_seed seed LEFT JOIN synk_ccsi_matches m ON m.seed_id=seed.seed_id
GROUP BY seed.seed_id,seed.subject_code,seed.descriptive_title,seed.section_text,
         seed.faculty_first_name,seed.faculty_middle_name,seed.faculty_last_name
ORDER BY seed.seed_id;

DROP TEMPORARY TABLE IF EXISTS synk_ccsi_matches;
DROP TEMPORARY TABLE IF EXISTS synk_ccsi_seed;
SET @synk_ccsi_apply = NULL;
