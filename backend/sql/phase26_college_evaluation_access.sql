-- Select the live Synk database before running this entire script.
-- Requires the existing evaluation, enrollment and college tables.
-- Safe to rerun. Preserves responses, scores, submission status and access settings.

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_settings (
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    student_open TINYINT UNSIGNED NOT NULL DEFAULT 0,
    faculty_released TINYINT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (college_id, ay_id, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    control_name VARCHAR(32) NOT NULL,
    previous_value TINYINT UNSIGNED NOT NULL,
    new_value TINYINT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_evaluation_audit_term (college_id, ay_id, semester, audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add only missing columns; do not relabel existing instrument versions.
SET @synk_eval_columns = CONCAT_WS(', ',
    IF((SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name='tbl_student_faculty_evaluations'
          AND column_name='college_id')=0,
        'ADD COLUMN college_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER faculty_id',
        NULL),
    IF((SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name='tbl_student_faculty_evaluations'
          AND column_name='instrument_version')=0,
        'ADD COLUMN instrument_version VARCHAR(64) NOT NULL DEFAULT ''legacy-20'' AFTER comment_text',
        NULL)
);
SET @synk_eval_sql = IF(@synk_eval_columns='', 'SELECT 1 AS columns_already_present',
    CONCAT('ALTER TABLE tbl_student_faculty_evaluations ', @synk_eval_columns));
PREPARE synk_eval_migration FROM @synk_eval_sql;
EXECUTE synk_eval_migration;
DEALLOCATE PREPARE synk_eval_migration;

-- Preserve historical ownership and timestamps. Leave ambiguous membership at 0.
UPDATE tbl_student_faculty_evaluations e
JOIN (
    SELECT es.student_id, es.faculty_id, es.ay_id, es.semester,
           MIN(es.college_id) AS college_id
    FROM tbl_student_management_enrolled_subjects es
    WHERE es.college_id>0
      AND EXISTS (
          SELECT 1 FROM tbl_college c
          WHERE c.college_id=es.college_id AND c.campus_id=es.campus_id
      )
    GROUP BY es.student_id, es.faculty_id, es.ay_id, es.semester
    HAVING COUNT(DISTINCT es.college_id)=1
) owner ON owner.student_id=e.student_id AND owner.faculty_id=e.faculty_id
       AND owner.ay_id=e.ay_id AND owner.semester=e.semester
LEFT JOIN tbl_student_faculty_evaluations assigned
       ON assigned.student_id=e.student_id AND assigned.faculty_id=e.faculty_id
      AND assigned.ay_id=e.ay_id AND assigned.semester=e.semester
      AND assigned.college_id=owner.college_id
SET e.college_id=owner.college_id, e.updated_at=e.updated_at
WHERE e.college_id=0 AND assigned.evaluation_id IS NULL;

-- Replace the old uniqueness rule with a college-specific rule.
SET @synk_eval_old_key = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='tbl_student_faculty_evaluations'
      AND index_name='uniq_student_faculty_term'
);
SET @synk_eval_new_key = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='tbl_student_faculty_evaluations'
      AND index_name='uniq_student_faculty_college_term'
);
SET @synk_eval_sql = IF(@synk_eval_new_key>0,
    IF(@synk_eval_old_key>0,
        'ALTER TABLE tbl_student_faculty_evaluations DROP INDEX uniq_student_faculty_term',
        'SELECT 1 AS college_key_already_present'),
    CONCAT('ALTER TABLE tbl_student_faculty_evaluations ',
        IF(@synk_eval_old_key>0, 'DROP INDEX uniq_student_faculty_term, ', ''),
        'ADD UNIQUE KEY uniq_student_faculty_college_term (student_id,faculty_id,college_id,ay_id,semester)')
);
PREPARE synk_eval_migration FROM @synk_eval_sql;
EXECUTE synk_eval_migration;
DEALLOCATE PREPARE synk_eval_migration;

-- A nonzero count needs ownership review; the original responses remain stored.
SELECT COUNT(*) AS historical_responses_needing_college_review
FROM tbl_student_faculty_evaluations WHERE college_id=0;
