-- Add private admin notes to reseller accounts.
-- Run in cPanel/phpMyAdmin. This does not affect reseller-visible notes.

ALTER TABLE resellers
  ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL;
