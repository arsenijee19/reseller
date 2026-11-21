-- Fast reseller token login: an indexed lookup instead of checking the token against every account.
-- Additive only. The portal adds these columns itself on the first login; run this once in phpMyAdmin
-- only if the DB user has no ALTER rights. Existing accounts fill the lookup on their next login.
ALTER TABLE resellers
  ADD COLUMN token_lookup CHAR(64) NULL,
  ADD COLUMN token_lookup_fp CHAR(16) NULL,
  ADD KEY idx_resellers_token_lookup (token_lookup);
