-- Table schema for the separate executive analytics access module.
-- Default role records and hashed access codes are seeded by backend/executive_analytics_helper.php.
CREATE TABLE IF NOT EXISTS `tbl_executive_access_codes` (
    `access_code_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key` VARCHAR(50) NOT NULL,
    `role_label` VARCHAR(120) NOT NULL,
    `access_code_hash` VARCHAR(255) NOT NULL,
    `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`access_code_id`),
    UNIQUE KEY `uniq_exec_role_key` (`role_key`),
    KEY `idx_exec_status_order` (`status`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
