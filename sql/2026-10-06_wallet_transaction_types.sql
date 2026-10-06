-- Allow admins to classify manual wallet entries with explicit transaction types.
-- This widens a legacy ENUM or short text column without changing existing values.
ALTER TABLE wallet_transactions
  MODIFY COLUMN type VARCHAR(40) NOT NULL DEFAULT 'ORDER';
