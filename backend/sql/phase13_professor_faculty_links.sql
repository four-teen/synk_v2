CREATE TABLE IF NOT EXISTS `tbl_useraccount_faculty_links` (
  `account_faculty_link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `faculty_id` INT UNSIGNED NOT NULL,
  `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_faculty_link_id`),
  UNIQUE KEY `uniq_account_faculty_user` (`user_id`),
  UNIQUE KEY `uniq_account_faculty_faculty` (`faculty_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
