<?php

function synk_live_offering_join_sql(
    string $offeringAlias = 'o',
    string $sectionAlias = 'sec',
    string $subjectAlias = 'ps',
    string $yearSemAlias = 'pys',
    string $headerAlias = 'ph'
): string {
    return "
        INNER JOIN tbl_prospectus_header {$headerAlias}
            ON {$headerAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$headerAlias}.program_id = {$offeringAlias}.program_id
        INNER JOIN tbl_prospectus_subjects {$subjectAlias}
            ON {$subjectAlias}.ps_id = {$offeringAlias}.ps_id
        INNER JOIN tbl_prospectus_year_sem {$yearSemAlias}
            ON {$yearSemAlias}.pys_id = {$subjectAlias}.pys_id
           AND {$yearSemAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$yearSemAlias}.year_level = {$offeringAlias}.year_level
           AND {$yearSemAlias}.semester = {$offeringAlias}.semester
        INNER JOIN tbl_sections {$sectionAlias}
            ON {$sectionAlias}.section_id = {$offeringAlias}.section_id
           AND {$sectionAlias}.program_id = {$offeringAlias}.program_id
           AND {$sectionAlias}.year_level = {$offeringAlias}.year_level
           AND {$sectionAlias}.ay_id = {$offeringAlias}.ay_id
           AND {$sectionAlias}.semester = {$offeringAlias}.semester
           AND {$sectionAlias}.status = 'active'
    ";
}

function synk_section_curriculum_live_offering_join_sql(
    string $offeringAlias = 'o',
    string $sectionAlias = 'sec',
    string $sectionCurriculumAlias = 'sc',
    string $subjectAlias = 'ps',
    string $yearSemAlias = 'pys',
    string $headerAlias = 'ph'
): string {
    return "
        INNER JOIN tbl_section_curriculum {$sectionCurriculumAlias}
            ON {$sectionCurriculumAlias}.section_id = {$offeringAlias}.section_id
           AND {$sectionCurriculumAlias}.prospectus_id = {$offeringAlias}.prospectus_id
        INNER JOIN tbl_prospectus_header {$headerAlias}
            ON {$headerAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$headerAlias}.program_id = {$offeringAlias}.program_id
        INNER JOIN tbl_prospectus_subjects {$subjectAlias}
            ON {$subjectAlias}.ps_id = {$offeringAlias}.ps_id
        INNER JOIN tbl_prospectus_year_sem {$yearSemAlias}
            ON {$yearSemAlias}.pys_id = {$subjectAlias}.pys_id
           AND {$yearSemAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$yearSemAlias}.year_level = {$offeringAlias}.year_level
           AND {$yearSemAlias}.semester = {$offeringAlias}.semester
        INNER JOIN tbl_sections {$sectionAlias}
            ON {$sectionAlias}.section_id = {$offeringAlias}.section_id
           AND {$sectionAlias}.program_id = {$offeringAlias}.program_id
           AND {$sectionAlias}.year_level = {$offeringAlias}.year_level
           AND {$sectionAlias}.ay_id = {$offeringAlias}.ay_id
           AND {$sectionAlias}.semester = {$offeringAlias}.semester
           AND {$sectionAlias}.status = 'active'
    ";
}

function synk_scheduled_offering_join_sql(
    string $scheduleAlias = 'sched',
    string $offeringAlias = 'o'
): string {
    return "
        LEFT JOIN (
            SELECT DISTINCT offering_id
            FROM tbl_class_schedule
        ) {$scheduleAlias}
            ON {$scheduleAlias}.offering_id = {$offeringAlias}.offering_id
    ";
}
