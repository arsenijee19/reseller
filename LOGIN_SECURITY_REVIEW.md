# Login & Account Security Review

## Previous Login Flow
- Resellers authenticated with a single reseller token verified against `resellers.token_hash`.
- Successful login created a full reseller session immediately.
- Sessions use separate HTTP-only cookies for admin and reseller, with `SameSite=Strict`, secure cookies on HTTPS, a 60-minute idle timeout, and an 8-hour absolute timeout.
- CSRF tokens protected mutating reseller/admin requests.

## Risks Addressed
- Unlimited credential guessing risk was reduced with persisted rate limiting and security audit events.
- Existing reseller accounts now require profile completion with name, email, and phone.
- Resellers can optionally change their access token after confirming the current token.
- Resellers can enable standard TOTP 2-step verification.

## 2FA Architecture
- 2FA uses standard TOTP compatible with Authenticator apps.
- If 2FA is enabled, the first factor creates only a temporary pre-auth session.
- Full reseller access is granted only after a valid TOTP or recovery code.
- TOTP attempts are rate-limited separately from token login attempts.
- Recovery codes are generated with cryptographically secure randomness, stored only as password hashes, shown once, and single-use.
- TOTP secrets are encrypted at rest with AES-256-GCM using `security.encryption_key` / `SECURITY_ENCRYPTION_KEY` when configured.

## Audit Events
- Login success/failure.
- First-factor success for 2FA users.
- 2FA success/failure.
- Recovery-code login.
- Profile update/completion.
- Reseller credential change.
- 2FA setup, enable, disable, and recovery-code regeneration.
- Admin order cancellation/reversal with order, reseller, amount, and reason metadata.

## Admin 2FA Setup
- The admin flow is explicit: open activation, verify the current password, generate the setup key, confirm the live Authenticator code, then save the recovery codes.
- Setup secrets are held encrypted as pending data until the TOTP confirmation succeeds; recovery codes are generated in the same database transaction as activation.
- Admin login uses a temporary pre-auth session until the TOTP or one-time recovery code succeeds.

## Owner Passkeys

- Owner passkeys use the browser WebAuthn API and `web-auth/webauthn-lib` 5.3.5; no localStorage or custom cryptography is used.
- Registration is authenticated, CSRF-protected and requires current admin password plus TOTP when admin 2FA is enabled.
- Login challenges are single-use, expire after 60 seconds, are bound to the admin session, and do not send the unauthenticated browser a credential ID list.
- The server validates the configured origin, RP ID hash, challenge, user verification, signature, credential ID and sign counter. Credential records contain only public key material and are revocable.
- Production requires PHP 8.2+, the Composer dependencies under `vendor/`, HTTPS, explicit `security.origin` and `security.webauthn` configuration, and the owner passkey acceptance journey.

## Secrets Handling
- Plaintext reseller tokens are never stored.
- Plaintext TOTP secrets are not logged and are returned only during setup.
- TOTP codes and recovery-code input values are not logged.
- Recovery codes are shown only immediately after generation/regeneration.
- Inventory Supplier API token remains server-side only.

## Remaining Notes
- The no-build PHP project currently provides manual Authenticator key and `otpauth://` setup link, not a locally rendered QR image.
- Active session management, trusted devices, and admin-side 2FA reset are not implemented.
- Production should set a strong `security.encryption_key` in `api/config.local.php` or `SECURITY_ENCRYPTION_KEY`.
