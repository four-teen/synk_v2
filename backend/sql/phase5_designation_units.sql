-- ============================================================================
-- Phase 5: Designation + Units master data
-- ============================================================================
-- Purpose:
-- 1) Add a dedicated designation table used for role/position management.
-- 2) Keep designation names and their corresponding unit values.
-- ============================================================================
-- Run after reviewing current schema and before using the new admin page.

CREATE TABLE IF NOT EXISTS `tbl_designation` (
  `designation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `designation_name` varchar(120) NOT NULL,
  `designation_units` decimal(6,2) NOT NULL DEFAULT 0.00,
  `status` enum('active', 'inactive') NOT NULL DEFAULT 'active',
  `date_created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`designation_id`),
  UNIQUE KEY `uk_designation_name` (`designation_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

