-- "Uplatio sam" with amount + mandatory receipt, approved from the admin panel.
-- Additive only. The portal also adds these columns on its own if they are missing,
-- so running this is optional; run it once in phpMyAdmin if the DB user lacks ALTER rights.
-- (amount_rsd / review_* already exist if the Telegram migration from 2026-10-06 was run;
--  skip those lines in that case.)

ALTER TABLE payment_notice_requests
  ADD COLUMN wallet_transaction_id BIGINT UNSIGNED NULL,
  ADD COLUMN proof_file VARCHAR(120) NULL,
  ADD COLUMN proof_mime VARCHAR(60) NULL,
  ADD COLUMN proof_size INT UNSIGNED NULL,
  ADD COLUMN proof_name VARCHAR(255) NULL;
