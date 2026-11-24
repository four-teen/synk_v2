-- Apply in the main Synk database after employee-accounts.sql.
SET @kawani_permissions_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tbl_hr_employee_accounts' AND COLUMN_NAME = 'user_permissions'),
    'ALTER TABLE tbl_hr_employee_accounts ADD COLUMN user_permissions TEXT NULL AFTER role_code',
    'DO 0'
);
PREPARE kawani_permissions_stmt FROM @kawani_permissions_ddl;
EXECUTE kawani_permissions_stmt;
DEALLOCATE PREPARE kawani_permissions_stmt;

SET @kawani_secondary_role_ddl = IF(
    NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tbl_hr_employee_accounts' AND COLUMN_NAME = 'secondary_role_code'),
    'ALTER TABLE tbl_hr_employee_accounts ADD COLUMN secondary_role_code ENUM(''hris_user'') NULL AFTER role_code',
    'DO 0'
);
PREPARE kawani_secondary_role_stmt FROM @kawani_secondary_role_ddl;
EXECUTE kawani_secondary_role_stmt;
DEALLOCATE PREPARE kawani_secondary_role_stmt;

-- Record the access current Users already have. Preserve every explicit assignment on rerun.
UPDATE tbl_hr_employee_accounts
SET user_permissions = '["dashboard","employee_profiles","employee_manage","employee_delete","pds_tracker","position_catalog"]'
WHERE role_code = 'hris_user' AND user_permissions IS NULL;
