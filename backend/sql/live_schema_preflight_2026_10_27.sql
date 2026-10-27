-- READ ONLY schema check. Select the intended live Synk database first.
SELECT DATABASE() AS selected_database, VERSION() AS database_version;

-- Existing Management catalogs. The supplied dump has these three older status
-- enums, no exam_term columns, and no current-period indexes.
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('tbl_management_contributions','tbl_management_events','tbl_management_event_sessions')
  AND COLUMN_NAME IN ('status','exam_term')
ORDER BY TABLE_NAME,ORDINAL_POSITION;

SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('tbl_management_contributions','tbl_management_events')
  AND INDEX_NAME IN ('ix_management_contribution_period','ix_management_event_period')
ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;

-- These six tables are present locally and absent from the supplied dump.
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'tbl_management_period_settings','tbl_management_attendance_events',
    'tbl_management_attendance_roster','tbl_management_attendance_logs',
    'tbl_management_student_qr_tokens','tbl_management_attendance_audit'
  )
ORDER BY TABLE_NAME;
