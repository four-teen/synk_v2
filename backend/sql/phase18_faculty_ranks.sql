-- Faculty rank reference data supplied for profile management.
CREATE TABLE IF NOT EXISTS `tbl_faculty_ranks` (
  `overall_level` TINYINT UNSIGNED NOT NULL,
  `academic_rank` VARCHAR(100) NOT NULL,
  `salary_grade` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`overall_level`),
  UNIQUE KEY `uniq_faculty_academic_rank` (`academic_rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tbl_faculty_ranks` (`overall_level`, `academic_rank`, `salary_grade`) VALUES
  (1, 'Instructor I', 12),
  (2, 'Instructor II', 13),
  (3, 'Instructor III', 14),
  (4, 'Assistant Professor I', 15),
  (5, 'Assistant Professor II', 16),
  (6, 'Assistant Professor III', 17),
  (7, 'Assistant Professor IV', 18),
  (8, 'Associate Professor I', 19),
  (9, 'Associate Professor II', 20),
  (10, 'Associate Professor III', 21),
  (11, 'Associate Professor IV', 22),
  (12, 'Associate Professor V', 23),
  (13, 'Professor I', 24),
  (14, 'Professor II', 25),
  (15, 'Professor III', 26),
  (16, 'Professor IV', 27),
  (17, 'Professor V', 28),
  (18, 'Professor VI', 29)
ON DUPLICATE KEY UPDATE
  `academic_rank` = VALUES(`academic_rank`),
  `salary_grade` = VALUES(`salary_grade`);
