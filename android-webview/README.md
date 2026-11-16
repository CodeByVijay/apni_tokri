# Veggie Tokri Android WebView (FCM scaffold)

This folder is a **reference skeleton** for the Android WebView wrapper. It is not wired into CI in this repository.

- Minimum SDK: **24** (adjust in `app/build.gradle.kts`)
- Target / compile SDK: **35**
- Native **Firebase Cloud Messaging** is required; Web Push inside WebView does **not** replace Android notifications.

## Build (local)

1. Open `android-webview/` in Android Studio.
2. Add `app/google-services.json` from the Firebase console (**do not commit**).
3. Sync Gradle and run **Build → Build Bundle(s) / APK(s) → Build APK(s)** (debug only).

See `docs/firebase-push/ANDROID-SETUP.md` for full integration steps.
