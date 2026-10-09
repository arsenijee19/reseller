-- Store an admin-assigned name on every new one-time activation code.
-- Existing codes and registered devices remain valid and unchanged.

ALTER TABLE reseller_device_activation_codes
  ADD COLUMN IF NOT EXISTS device_label VARCHAR(120) NULL AFTER code_hash;
