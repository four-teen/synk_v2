-- Contribution status only. Original source fields and timestamps are preserved.
-- OPEN becomes ACTIVE; DRAFT, CLOSED and ARCHIVED become INACTIVE.
SET @synk_contribution_status_upgrade = EXISTS (
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_management_contributions'
    AND COLUMN_NAME = 'status' AND COLUMN_TYPE <> 'enum(''ACTIVE'',''INACTIVE'')'
);
SET @synk_contribution_status_ddl = IF(@synk_contribution_status_upgrade,
  'ALTER TABLE tbl_management_contributions MODIFY COLUMN status ENUM(''DRAFT'',''OPEN'',''CLOSED'',''ARCHIVED'',''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_contribution_status_stmt FROM @synk_contribution_status_ddl;
EXECUTE synk_contribution_status_stmt;
DEALLOCATE PREPARE synk_contribution_status_stmt;

UPDATE tbl_management_contributions
SET status = CASE WHEN status = 'OPEN' THEN 'ACTIVE' ELSE 'INACTIVE' END,
    updated_at = updated_at
WHERE status IN ('DRAFT','OPEN','CLOSED','ARCHIVED');

SET @synk_contribution_status_ddl = IF(@synk_contribution_status_upgrade,
  'ALTER TABLE tbl_management_contributions MODIFY COLUMN status ENUM(''ACTIVE'',''INACTIVE'') NOT NULL DEFAULT ''ACTIVE''',
  'DO 0');
PREPARE synk_contribution_status_stmt FROM @synk_contribution_status_ddl;
EXECUTE synk_contribution_status_stmt;
DEALLOCATE PREPARE synk_contribution_status_stmt;
