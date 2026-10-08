-- Phase 7: allow one scheduler account to manage multiple colleges.
-- The legacy tbl_useraccount.college_id remains as the default college for login/session compatibility.

CREATE TABLE IF NOT EXISTS tbl_user_college_access (
    access_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(10) UNSIGNED NOT NULL,
    college_id INT(10) UNSIGNED NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (access_id),
    UNIQUE KEY uk_user_college (user_id, college_id),
    KEY idx_user_status_default (user_id, status, is_default),
    KEY idx_college_status (college_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
