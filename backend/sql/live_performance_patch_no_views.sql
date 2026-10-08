-- Live performance patch for scheduler/offering/workload pages.
-- The current runtime does not require any SQL views.
-- Do not import or recreate `vw_faculty_workload`.

DELIMITER $$

DROP PROCEDURE IF EXISTS synk_add_index_if_missing$$
CREATE PROCEDURE synk_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @synk_ddl = p_ddl;
        PREPARE synk_stmt FROM @synk_ddl;
        EXECUTE synk_stmt;
        DEALLOCATE PREPARE synk_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS synk_apply_live_performance_patch$$
CREATE PROCEDURE synk_apply_live_performance_patch()
BEGIN
    CALL synk_add_index_if_missing(
        'tbl_prospectus_offering',
        'idx_po_term_ps_section',
        'ALTER TABLE tbl_prospectus_offering ADD INDEX idx_po_term_ps_section (prospectus_id, ay_id, semester, ps_id, section_id)'
    );
    CALL synk_add_index_if_missing(
        'tbl_prospectus_offering',
        'idx_po_term_program',
        'ALTER TABLE tbl_prospectus_offering ADD INDEX idx_po_term_program (ay_id, semester, program_id)'
    );
    CALL synk_add_index_if_missing(
        'tbl_prospectus_offering',
        'idx_po_term_section',
        'ALTER TABLE tbl_prospectus_offering ADD INDEX idx_po_term_section (ay_id, semester, section_id)'
    );

    CALL synk_add_index_if_missing(
        'tbl_prospectus_year_sem',
        'idx_pys_prospectus_sem_year',
        'ALTER TABLE tbl_prospectus_year_sem ADD INDEX idx_pys_prospectus_sem_year (prospectus_id, semester, year_level, pys_id)'
    );
    CALL synk_add_index_if_missing(
        'tbl_prospectus_subjects',
        'idx_ps_pys_sort_sub',
        'ALTER TABLE tbl_prospectus_subjects ADD INDEX idx_ps_pys_sort_sub (pys_id, sort_order, sub_id)'
    );
    CALL synk_add_index_if_missing(
        'tbl_sections',
        'idx_sections_program_term_level_status',
        'ALTER TABLE tbl_sections ADD INDEX idx_sections_program_term_level_status (program_id, ay_id, semester, year_level, status)'
    );
    CALL synk_add_index_if_missing(
        'tbl_program',
        'idx_program_college_status',
        'ALTER TABLE tbl_program ADD INDEX idx_program_college_status (college_id, status, program_id)'
    );

    CALL synk_add_index_if_missing(
        'tbl_class_schedule',
        'idx_cs_room_time',
        'ALTER TABLE tbl_class_schedule ADD INDEX idx_cs_room_time (room_id, time_start, time_end)'
    );
    CALL synk_add_index_if_missing(
        'tbl_class_schedule',
        'idx_cs_offering_time',
        'ALTER TABLE tbl_class_schedule ADD INDEX idx_cs_offering_time (offering_id, time_start)'
    );

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_class_schedule'
          AND column_name = 'schedule_type'
    ) THEN
        CALL synk_add_index_if_missing(
            'tbl_class_schedule',
            'idx_cs_offering_type',
            'ALTER TABLE tbl_class_schedule ADD INDEX idx_cs_offering_type (offering_id, schedule_type)'
        );
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_class_schedule'
          AND column_name = 'schedule_group_id'
    ) THEN
        CALL synk_add_index_if_missing(
            'tbl_class_schedule',
            'idx_cs_group_schedule',
            'ALTER TABLE tbl_class_schedule ADD INDEX idx_cs_group_schedule (schedule_group_id, schedule_id)'
        );
    END IF;

    CALL synk_add_index_if_missing(
        'tbl_faculty_workload_sched',
        'idx_fws_term_faculty',
        'ALTER TABLE tbl_faculty_workload_sched ADD INDEX idx_fws_term_faculty (ay_id, semester, faculty_id)'
    );
    CALL synk_add_index_if_missing(
        'tbl_faculty_workload_sched',
        'idx_fws_schedule_term',
        'ALTER TABLE tbl_faculty_workload_sched ADD INDEX idx_fws_schedule_term (schedule_id, ay_id, semester)'
    );

    CALL synk_add_index_if_missing(
        'tbl_college_faculty',
        'idx_cf_college_status',
        'ALTER TABLE tbl_college_faculty ADD INDEX idx_cf_college_status (college_id, status)'
    );

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_college_faculty'
          AND column_name = 'ay_id'
    ) AND EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_college_faculty'
          AND column_name = 'semester'
    ) THEN
        CALL synk_add_index_if_missing(
            'tbl_college_faculty',
            'idx_cf_college_term_status_faculty',
            'ALTER TABLE tbl_college_faculty ADD INDEX idx_cf_college_term_status_faculty (college_id, ay_id, semester, status, faculty_id)'
        );
        CALL synk_add_index_if_missing(
            'tbl_college_faculty',
            'idx_cf_faculty_term_status',
            'ALTER TABLE tbl_college_faculty ADD INDEX idx_cf_faculty_term_status (faculty_id, ay_id, semester, status)'
        );
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_faculty'
          AND column_name = 'status'
    ) THEN
        CALL synk_add_index_if_missing(
            'tbl_faculty',
            'idx_faculty_status_name',
            'ALTER TABLE tbl_faculty ADD INDEX idx_faculty_status_name (status, last_name, first_name)'
        );
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tbl_designation'
          AND column_name = 'status'
    ) THEN
        CALL synk_add_index_if_missing(
            'tbl_designation',
            'idx_designation_status_name',
            'ALTER TABLE tbl_designation ADD INDEX idx_designation_status_name (status, designation_name)'
        );
    END IF;
END$$

DELIMITER ;

CALL synk_apply_live_performance_patch();

DROP PROCEDURE IF EXISTS synk_apply_live_performance_patch;
DROP PROCEDURE IF EXISTS synk_add_index_if_missing;
