CREATE TABLE `tbl_offering_enrollee_counts` (
  `offering_enrollee_id` int(11) NOT NULL AUTO_INCREMENT,
  `offering_id` int(11) NOT NULL,
  `total_enrollees` int(11) NOT NULL DEFAULT 0,
  `source_kind` varchar(30) NOT NULL DEFAULT 'manual_dummy',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `updated_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`offering_enrollee_id`),
  UNIQUE KEY `uq_offering_enrollee_counts_offering` (`offering_id`),
  KEY `idx_offering_enrollee_counts_total` (`total_enrollees`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
