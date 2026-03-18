-- SuccuTrack — Authentication & Onboarding Migration
-- Run this on your live database before uploading the new PHP files.

ALTER TABLE users
  ADD COLUMN is_email_verified TINYINT(1)  NOT NULL DEFAULT 0       AFTER email,
  ADD COLUMN otp_code           VARCHAR(6)  NULL                      AFTER is_email_verified,
  ADD COLUMN otp_expires_at     DATETIME    NULL                      AFTER otp_code,
  ADD COLUMN account_status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER otp_expires_at,
  ADD COLUMN status_note        VARCHAR(255) NULL                     AFTER account_status;

-- Admin and manager accounts that already exist should be auto-approved + verified
-- so they are not locked out after the migration
UPDATE users SET is_email_verified = 1, account_status = 'approved'
WHERE role IN ('admin', 'manager');

-- Also approve any existing 'user' accounts so they are not suddenly locked out
UPDATE users SET is_email_verified = 1, account_status = 'approved'
WHERE role = 'user';
