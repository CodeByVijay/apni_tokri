# Android WebView + native FCM

## Why native FCM

Web Push inside WebView does **not** deliver reliable Android notifications. The wrapper must use Firebase Android SDK and `FirebaseMessagingService`.

## Scaffold location

`android-webview/` — incomplete Gradle wrapper; **APK build not executed** in this task environment.

## Recommended stack

- Firebase BoM (Android) — align with [Android FCM client docs](https://firebase.google.com/docs/cloud-messaging/android/client)
- `POST_NOTIFICATIONS` runtime permission (Android 13+)
- Default notification channel `orders`

## Token sync

POST to `https://<storefront>/ajax/push-register.php`:

```json
{ "platform": "android", "token": "<fcm>", "permission": "granted" }
```

Requires customer session cookie + `X-CSRF-Token` (implement in `PushRegistrationSync`).

## Navigation

Use `click_url` in FCM `data` map; validate host matches `R.string.allowed_web_host` before `WebView.loadUrl()`.

## Project change

Changing `google-services.json` / Firebase project in APK requires **rebuild and reinstall**. Backend project switch alone is insufficient for already-installed APKs.
