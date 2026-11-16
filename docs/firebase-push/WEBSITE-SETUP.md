# Website push setup

## Prerequisites

- HTTPS (required for service workers except localhost).
- Migration 1.39.0 applied.
- Active Firebase project with Web App ID, API key, VAPID public key.

## Flow

1. Customer opens **My account** → **Enable notifications** (user gesture).
2. `push-notifications.js` loads config from `ajax/push-config.php`.
3. Registers `firebase-messaging-sw.js` at site root scope `/`.
4. `getToken({ vapidKey, serviceWorkerRegistration })` (Firebase JS 11 compat).
5. Token POSTed to `ajax/push-register.php` with CSRF header.

## Foreground / background

- Foreground: `onMessage` shows `Notification` API.
- Background: SW `onBackgroundMessage` → `showNotification`.
- Click: SW `notificationclick` opens validated `click_url` from data payload.

## Project switch

When admin activates another Firebase project, clients must re-fetch config (`configVersion` changes) and obtain a new token.
