# Android `android-webview/` — build gap analysis

**Verdict:** Not a complete buildable Android project. **Debug APK has not been built.**

## Present

| Item | Path |
|------|------|
| Partial `AndroidManifest.xml` | `app/src/main/AndroidManifest.xml` |
| Kotlin stubs | `app/src/main/java/com/veggietokri/app/push/*` |
| README | `android-webview/README.md` |

## Missing (required for Gradle build)

| Item | Notes |
|------|--------|
| `settings.gradle.kts` | Module inclusion |
| Root `build.gradle.kts` | AGP version, repositories |
| `app/build.gradle.kts` | `applicationId`, compileSdk, dependencies |
| `gradle/wrapper/*` | Wrapper jar + properties |
| `app/google-services.json` | Firebase Android config (never commit) |
| Google Services plugin | `com.google.gms.google-services` |
| Firebase BoM | e.g. `com.google.firebase:firebase-bom` per [Android FCM client](https://firebase.google.com/docs/cloud-messaging/android/client) |
| `MainActivity.kt` | Referenced in manifest, file absent |
| `R.string.app_name`, `R.string.allowed_web_host` | Referenced in Kotlin, no `res/` |
| WebView shell | No `WebView` activity implementation |
| `PushRegistrationSync` | Placeholder only |

## Conclusion

Android native FCM is **documented + scaffolded only** until the above are added and `./gradlew assembleDebug` succeeds.
