# PlayWorld Reseller Android

This is a separate Android shell for the existing reseller portal. It does not
move or modify the PHP application, APIs, database, or cPanel deployment.

## Behavior

- First use shows a native activation screen for the administrator-issued,
  12-character one-time code. A code expires unused after 24 hours and is
  consumed atomically by its first successful activation.
- Activation does not ask for a 2-step code; the administrator-issued one-time
  code is the second factor.
- After activation the app stores only a random per-device credential, encrypted
  with an AES-GCM key held by Android Keystore. It never stores the reseller's
  login token. The portal session is refreshed in the background on app resume
  and at most every 45 minutes while in use.
- Opens `https://reseller.psigre.rs/` in Android System WebView only after the
  server has authenticated the saved device credential.
- Admins can issue/revoke codes and inspect/revoke devices in the reseller edit
  drawer. Revocation blocks the next device session and any subsequent reseller
  API request made by that app session.
- Explicit app logout revokes that device enrollment; uninstalling the app
  removes its local credential. A new install therefore needs a new code.
- The same API contract supports iOS: `POST /api/device_auth.php?action=activate`
  with `{code, device_id, device_token, platform, device_name}`
  returns a portal session cookie. The client generates a cryptographically
  random 32-byte device token and saves it securely before activation; the API
  stores only its hash and never returns the token. `session` and `logout` accept
  `{device_id, device_token}`. An iOS client should keep the token in Keychain,
  pair it with a non-backed-up install marker so reinstall requests a new code,
  and use the returned cookie in its WebKit cookie store.
- Restricts in-app navigation to the reseller portal; external links open in the
  device browser. The admin page is not opened inside the reseller app.
- Shows a retry screen if the portal cannot be reached.
- If activation has an ambiguous network/server failure, explains that only the
  encrypted device credential was saved (not the one-time activation code),
  includes any server diagnostic reference, and lets the user retry session
  validation without exposing credentials.
- Requires an internet connection. This first version does not provide offline
  ordering or offline account access.
- Website changes appear in the app when the portal is updated; native shell
  changes require a new APK.

## Server setup for device activation

Before issuing codes, run `sql/2026-10-09_reseller_app_devices.sql` once against
the portal database. It only creates two new InnoDB tables and does not modify
existing reseller, order, balance, or session data. Deploy the PHP/API and
admin-panel changes together with the migration. Codes are shown only once to
the admin, stored as SHA-256 hashes, and expire after 24 hours if unused.

## Build a test APK

The GitHub Actions workflow `Android test APK` builds a debug APK, enables KVM,
installs and launches it on an Android emulator, checks for a fatal Android crash
and confirms that the app process stays alive, then uploads it as a workflow
artifact. This catches startup crashes that a successful compile alone cannot
detect. Debug APKs are for internal testing only and are not the final reseller
distribution package.

To build locally, install Android Studio with Android SDK 36 and a JDK 17, then
open `mobile/android` in Android Studio and run the `assembleDebug` task.

## Before distributing a release APK

Create a dedicated Android release signing key and keep its private key and
passwords outside Git in a protected backup. The same key must sign every future
release, or Android will not install updates over the existing app. Configure
the release signing values through a protected build environment before creating
the first reseller-facing APK. Never distribute a debug build as the production
app.

## Admin devices

An administrator can generate a 15-minute one-time code in Admin → Sigurnost →
"Admin aplikacija na telefonu". Entering it in the app (same activation screen)
registers the phone as an admin device (`admin_app_devices`, up to 5) and opens
`/admin.html` in the WebView with an admin session. Device sessions do not grant
admin step-up, so critical admin actions still ask for the admin password (and
2-step code when enabled). Devices can be revoked from the same admin panel.

## Session rules and app lock

- Every authenticated API request re-checks the account: a deactivated reseller or admin, a changed
  token/password, or a revoked app device ends the session immediately (`401` with `session_ended`).
  Inside the app the WebView hands that to `PlayWorldNative.sessionExpired()`, which re-validates the
  device and shows the activation screen when access is gone. Coming back to the app after 20 s does
  the same check.
- A token change (by the admin or by the reseller) revokes the reseller's app devices; the reseller
  device that performed the change keeps its session. An admin password change does the same for
  admin devices.
- Admin devices expire after 30 days without use.
- Admin devices are always locked behind the phone's PIN / pattern / fingerprint (re-asked after 60 s in
  the background). Resellers can switch the lock on in My Profile. Screenshots are allowed by default and
  can be disabled per phone in the same place (admin: Security tab).
- `tests/e2e/run.sh` starts a throw-away MariaDB and PHP server and checks all of the above end to end
  (needs `mariadb-server`, `php-mysql`, `python3`).
