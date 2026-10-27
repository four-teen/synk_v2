-- Management event periods. Existing events retain an unknown exam term for
-- explicit review. Session periods are inherited from their parent event.
SET @synk_event_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND COLUMN_NAME='exam_term'),
  'ALTER TABLE tbl_management_events ADD COLUMN exam_term ENUM(''MIDTERM'',''FINAL'') NULL DEFAULT NULL AFTER semester',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
SET @synk_event_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND INDEX_NAME='ix_management_event_period'),
  'ALTER TABLE tbl_management_events ADD INDEX ix_management_event_period (college_id,organization_id,ay_id,semester,exam_term,event_id)',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;

-- Normalize event and session statuses without altering source fields/timestamps.
SET @synk_event_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_events' AND COLUMN_NAME='status'
    AND COLUMN_TYPE<>'enum(''ACTIVE'',''INACTIVE'')');
SET @synk_event_ddl = IF(@synk_event_status_upgrade,
  'ALTER TABLE tbl_management_events MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
UPDATE tbl_management_events SET status=CASE WHEN status='OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
  updated_at=updated_at WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');
SET @synk_event_ddl = IF(@synk_event_status_upgrade,
  'ALTER TABLE tbl_management_events MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;

SET @synk_session_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='tbl_management_event_sessions' AND COLUMN_NAME='status'
    AND COLUMN_TYPE<>'enum(''ACTIVE'',''INACTIVE'')');
SET @synk_event_ddl = IF(@synk_session_status_upgrade,
  'ALTER TABLE tbl_management_event_sessions MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
UPDATE tbl_management_event_sessions SET status=CASE WHEN status='OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
  updated_at=updated_at WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');
SET @synk_event_ddl = IF(@synk_session_status_upgrade,
  'ALTER TABLE tbl_management_event_sessions MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_event_stmt FROM @synk_event_ddl;
EXECUTE synk_event_stmt;
DEALLOCATE PREPARE synk_event_stmt;
