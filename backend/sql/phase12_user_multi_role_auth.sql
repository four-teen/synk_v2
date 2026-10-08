CREATE TABLE IF NOT EXISTS `tbl_useraccount_roles` (
  `account_role_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_role_id`),
  UNIQUE KEY `uniq_user_role` (`user_id`, `role`),
  KEY `idx_user_status_primary` (`user_id`, `status`, `is_primary`),
  KEY `idx_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tbl_useraccount_roles` (`user_id`, `role`, `is_primary`, `status`)
SELECT
  ua.`user_id`,
  LOWER(TRIM(ua.`role`)) AS `role`,
  1 AS `is_primary`,
  CASE
    WHEN LOWER(TRIM(COALESCE(ua.`status`, 'inactive'))) = 'active' THEN 'active'
    ELSE 'inactive'
  END AS `status`
FROM `tbl_useraccount` ua
WHERE LOWER(TRIM(COALESCE(ua.`role`, ''))) IN ('admin', 'scheduler', 'professor')
ON DUPLICATE KEY UPDATE
  `status` = VALUES(`status`),
  `is_primary` = CASE
    WHEN VALUES(`is_primary`) = 1 THEN 1
    ELSE `tbl_useraccount_roles`.`is_primary`
  END;
