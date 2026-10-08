-- ============================================================================
-- Phase 4: Google Authentication Preparation
-- ============================================================================
-- Purpose:
-- 1) Keep tbl_useraccount as Synk's authorization/allowlist table.
-- 2) Support Google Sign-In without changing module roles or other tables.
-- 3) Preserve legacy password rows during the transition period.
-- ============================================================================
-- Run once after taking a database backup and reviewing the live schema.

ALTER TABLE tbl_useraccount
  MODIFY password VARCHAR(255) NULL,
  ADD COLUMN auth_provider ENUM('legacy', 'google') NOT NULL DEFAULT 'legacy' AFTER password,
  ADD COLUMN google_sub VARCHAR(64) NULL AFTER auth_provider,
  ADD COLUMN google_email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER google_sub,
  ADD COLUMN last_login_at DATETIME NULL AFTER google_email_verified,
  ADD COLUMN last_google_name VARCHAR(150) NULL AFTER last_login_at,
  ADD UNIQUE KEY uk_useraccount_google_sub (google_sub),
  ADD KEY idx_useraccount_role_status (role, status),
  ADD KEY idx_useraccount_college_status (college_id, status),
  ADD KEY idx_useraccount_auth_provider (auth_provider);

UPDATE tbl_useraccount
SET auth_provider = 'legacy'
WHERE auth_provider IS NULL OR auth_provider = '';
