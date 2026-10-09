# PlayWorld Reseller Android

This is a separate Android shell for the existing reseller portal. It does not
move or modify the PHP application, APIs, database, or cPanel deployment.

## Behavior

- Opens `https://reseller.psigre.rs/` in Android System WebView.
- Uses the existing HTTPS origin, API, login session, and server-side database.
- Restricts in-app navigation to the reseller portal; external links open in the
  device browser. The admin page is not opened inside the reseller app.
- Shows a retry screen if the portal cannot be reached.
- Requires an internet connection. This first version does not provide offline
  ordering or offline account access.
- Website changes appear in the app when the portal is updated; native shell
  changes require a new APK.

## Build a test APK

The GitHub Actions workflow `Android test APK` builds a debug APK, installs and
launches it on an Android emulator, checks that the app process stays alive, and
uploads it as a workflow artifact. This catches startup crashes that a successful
compile alone cannot detect. Debug APKs are for internal testing only and are not
the final reseller distribution package.

To build locally, install Android Studio with Android SDK 36 and a JDK 17, then
open `mobile/android` in Android Studio and run the `assembleDebug` task.

## Before distributing a release APK

Create a dedicated Android release signing key and keep its private key and
passwords outside Git in a protected backup. The same key must sign every future
release, or Android will not install updates over the existing app. Configure
the release signing values through a protected build environment before creating
the first reseller-facing APK. Never distribute a debug build as the production
app.
