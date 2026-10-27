-- Management-only contribution periods. Repeatable; existing rows keep an
-- unknown term until explicitly reviewed. No source values are inferred.
SET @synk_contribution_database = DATABASE();
SET @synk_contribution_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @synk_contribution_database
      AND TABLE_NAME = 'tbl_management_contributions' AND COLUMN_NAME = 'exam_term'),
  'ALTER TABLE tbl_management_contributions ADD COLUMN exam_term ENUM(''MIDTERM'',''FINAL'') NULL DEFAULT NULL AFTER semester',
  'DO 0'
);
PREPARE synk_contribution_stmt FROM @synk_contribution_ddl;
EXECUTE synk_contribution_stmt;
DEALLOCATE PREPARE synk_contribution_stmt;

SET @synk_contribution_ddl = IF(
  NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @synk_contribution_database
      AND TABLE_NAME = 'tbl_management_contributions' AND INDEX_NAME = 'ix_management_contribution_period'),
  'ALTER TABLE tbl_management_contributions ADD INDEX ix_management_contribution_period (college_id, organization_id, ay_id, semester, exam_term, contribution_id)',
  'DO 0'
);
PREPARE synk_contribution_stmt FROM @synk_contribution_ddl;
EXECUTE synk_contribution_stmt;
DEALLOCATE PREPARE synk_contribution_stmt;
