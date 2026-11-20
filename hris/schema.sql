-- Install in the main Synk database, after tbl_useraccount exists.
-- Role rows are keyed by the existing Synk account user_id and scoped to Kawani.
CREATE TABLE IF NOT EXISTS tbl_hr_user_roles (
    user_id INT NOT NULL,
    role_code VARCHAR(40) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_code),
    CONSTRAINT fk_hr_user_roles_account FOREIGN KEY (user_id)
        REFERENCES tbl_useraccount (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
