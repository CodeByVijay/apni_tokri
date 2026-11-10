<?php

declare(strict_types=1);

/** @var int $projectId */
/** @var array{web: bool, android: bool} $platforms */
/** @var array<string, list<array{id: string, label: string, status: string, detail: string}>> $progress */
/** @var string $adminBase */

$pushBase = url_base() . '/admin/push';
$showWeb = !empty($platforms['web']);
$showAndroid = !empty($platforms['android']);
$consoleUrl = 'https://console.firebase.google.com/';
$docsWeb = 'https://firebase.google.com/docs/cloud-messaging/js/client';
$docsAndroid = 'https://firebase.google.com/docs/cloud-messaging/android/client';
$docsHttpV1 = 'https://firebase.google.com/docs/cloud-messaging/send-message';
?>
<div class="at-admin-card at-firebase-setup-guide">
    <details class="at-firebase-setup-guide__panel" id="firebase-setup-guide-panel">
        <summary class="at-firebase-setup-guide__header">
            <div class="at-firebase-setup-guide__header-text">
                <span class="at-firebase-setup-guide__title">Firebase Setup Guide</span>
                <span class="at-firebase-setup-guide__subtitle">Follow this guide to configure Website and Android push notifications.</span>
            </div>
            <span class="at-firebase-setup-guide__toggle" aria-hidden="true">
                <span class="at-firebase-setup-guide__toggle-label js-guide-toggle-label">View Setup Guide</span>
                <span class="at-firebase-setup-guide__chevron"></span>
            </span>
        </summary>
        <div class="at-firebase-setup-guide__content">
            <section class="at-firebase-setup-guide__progress" aria-labelledby="firebase-guide-progress-heading">
                <h2 class="at-firebase-setup-guide__section-title" id="firebase-guide-progress-heading">Setup progress</h2>
                <p class="at-firebase-setup-guide__lede">Backend-verified steps show <strong>Complete</strong> or <strong>Not yet</strong>. External steps show <strong>Manual check</strong>.</p>
                <div class="at-firebase-setup-guide__progress-grid">
                    <?php firebase_render_progress_list('Shared Firebase setup', $progress['shared'] ?? []); ?>
                    <?php if ($showWeb): ?>
                        <?php firebase_render_progress_list('Website Push setup', $progress['website'] ?? []); ?>
                    <?php endif; ?>
                    <?php if ($showAndroid): ?>
                        <?php firebase_render_progress_list('Android Native Push setup', $progress['android'] ?? []); ?>
                    <?php endif; ?>
                    <?php firebase_render_progress_list('Admin configuration', $progress['admin_workflow'] ?? []); ?>
                </div>
            </section>

            <details class="at-firebase-setup-guide__doc">
                <summary class="at-firebase-setup-guide__doc-summary">1. What goes where</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <table class="at-firebase-setup-guide__table">
                        <thead><tr><th>Item</th><th>Purpose</th></tr></thead>
                        <tbody>
                            <tr><td>Firebase Project</td><td>One project in Firebase Console (Web + Android apps inside it)</td></tr>
                            <tr><td>Web App config</td><td>Website push (browser)</td></tr>
                            <tr><td>Android App config</td><td>Native Android push (WebView app)</td></tr>
                            <tr><td>VAPID public key</td><td>Website push only</td></tr>
                            <tr><td><code>google-services.json</code></td><td>Android Gradle build — not uploaded to PHP</td></tr>
                            <tr><td>Service Account JSON</td><td>Private PHP backend (FCM HTTP v1) — encrypted server-side</td></tr>
                            <tr><td>Web FID (Firebase Installation ID)</td><td>Website device registration (<code>target_type=fid</code>)</td></tr>
                            <tr><td>Android FCM token</td><td>Native token (<code>target_type=fcm_token</code>)</td></tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="at-firebase-setup-guide__doc">
                <summary class="at-firebase-setup-guide__doc-summary">2. Shared Firebase project and backend</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <h3 class="at-firebase-setup-guide__step-title">Step 1 — Create Firebase project</h3>
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Open the <a href="<?= Security::escape($consoleUrl) ?>" target="_blank" rel="noopener noreferrer">Firebase Console</a>.</li>
                        <li>Click <strong>Create project</strong>.</li>
                        <li>Enter a name (e.g. Veggie Tokri Production, Staging, or Development).</li>
                        <li>Finish project creation (use separate Firebase projects per environment when possible).</li>
                    </ol>
                    <p class="at-firebase-setup-guide__note">One Firebase project can contain both a <strong>Web</strong> app and an <strong>Android</strong> app. Veggie Tokri keeps <strong>one globally active</strong> Firebase project in Admin.</p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 2 — Project details for Admin</h3>
                    <p>In Firebase Console → <strong>Project settings → General</strong>, copy:</p>
                    <ul class="at-firebase-setup-guide__ul">
                        <li><strong>Project ID</strong> → Admin “Firebase Project ID” (fixed after save)</li>
                        <li><strong>Project number</strong> → Admin “Sender ID / Project number” (required for Website push)</li>
                    </ul>

                    <h3 class="at-firebase-setup-guide__step-title">How both platforms reach PHP</h3>
                    <pre class="at-firebase-setup-guide__pre" aria-label="Architecture diagram">Firebase Project
├── Web App → Browser FID → device_push_registrations (platform=web, target_type=fid)
└── Android App → Native FCM token → device_push_registrations (platform=android, target_type=fcm_token)
        ↓
Same PHP backend (FCM HTTP v1, one active project, platform-aware send)</pre>
                    <p>The backend never treats a Web FID as an Android token. See <a href="<?= Security::escape($docsHttpV1) ?>" target="_blank" rel="noopener noreferrer">FCM HTTP v1 docs</a>.</p>
                </div>
            </details>

            <?php if ($showWeb): ?>
            <details class="at-firebase-setup-guide__doc js-guide-platform-web">
                <summary class="at-firebase-setup-guide__doc-summary">3. Website push setup</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <h3 class="at-firebase-setup-guide__step-title">Step 1 — Register website app</h3>
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Firebase Console → Project Overview → <strong>Add app</strong> → Web (<code>&lt;/&gt;</code>).</li>
                        <li>Nickname suggestion: <strong>Veggie Tokri Website</strong>.</li>
                        <li>Register the app.</li>
                    </ol>

                    <h3 class="at-firebase-setup-guide__step-title">Step 2 — Copy web configuration</h3>
                    <p>Project settings → General → Your apps → Web app → SDK setup and configuration:</p>
                    <ul class="at-firebase-setup-guide__ul">
                        <li>apiKey → Web API Key</li>
                        <li>appId → Web App ID</li>
                        <li>authDomain → Auth domain</li>
                        <li>storageBucket → Storage bucket</li>
                        <li>measurementId → Measurement ID (if Analytics disabled, use a placeholder such as <code>G-NOTUSED</code> in Admin)</li>
                    </ul>

                    <h3 class="at-firebase-setup-guide__step-title">Step 3 — VAPID public key</h3>
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Project settings → <strong>Cloud Messaging</strong> → Web Push certificates.</li>
                        <li>Generate key pair if needed.</li>
                        <li>Copy the <strong>public</strong> key into Admin → VAPID public key.</li>
                    </ol>
                    <p class="at-alert at-alert--warning at-firebase-setup-guide__warn">Never paste the private VAPID key. Never expose Service Account JSON in the browser.</p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 4 — HTTPS &amp; storefront</h3>
                    <p>Website push requires HTTPS (localhost is allowed for development). The site uses Firebase JS <strong>13.2.0</strong>, service worker <code>firebase-messaging-sw.js</code>, and customer FID registration — not deprecated <code>getToken()</code> as the primary web flow.</p>
                    <p>Customer path: log in → My Account → enable notifications → allow browser permission.</p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 5 — Verify in Admin</h3>
                    <ul class="at-firebase-setup-guide__ul">
                        <li><a href="<?= Security::escape($pushBase) ?>/devices.php">Devices</a>: Platform <strong>Web</strong>, Target <strong>FID</strong>, Status <strong>active</strong>, Permission <strong>granted</strong>.</li>
                        <li><a href="<?= Security::escape($pushBase) ?>/send.php">Send</a>: use test device or dry-run customer send.</li>
                        <li><a href="<?= Security::escape($docsWeb) ?>" target="_blank" rel="noopener noreferrer">Firebase web messaging docs</a></li>
                    </ul>

                    <h3 class="at-firebase-setup-guide__step-title">Step 6 — Website testing checklist</h3>
                    <ul class="at-firebase-setup-guide__ul">
                        <li><strong>Foreground</strong> — tab focused; notification UX via in-page handler.</li>
                        <li><strong>Background</strong> — tab in background; system notification via service worker.</li>
                        <li><strong>Click</strong> — opens allowed storefront URL from payload.</li>
                        <li><strong>Unsubscribe</strong> — customer disables notifications; registration revoked server-side.</li>
                        <li><strong>Account switch</strong> — log out / different customer; prior device mapping rules apply.</li>
                        <li><strong>Permission denied</strong> — browser blocks; Admin should show permission status.</li>
                    </ul>
                    <p class="at-firebase-setup-guide__note">Use Push → Settings with <strong>Dry run ON</strong> before any live FCM test.</p>
                </div>
            </details>
            <?php endif; ?>

            <?php if ($showAndroid): ?>
            <details class="at-firebase-setup-guide__doc js-guide-platform-android">
                <summary class="at-firebase-setup-guide__doc-summary">4. Android WebView — native FCM setup</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <p class="at-firebase-setup-guide__note">The Veggie Tokri Android app shows the website in a <strong>WebView</strong>. Browser website push does <strong>not</strong> replace native Android notifications.</p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 1 — Register Android app</h3>
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Firebase Console → <strong>Add app</strong> → Android.</li>
                        <li>Package name must exactly match the app <code>applicationId</code> (e.g. <code>com.veggietokri.app</code>).</li>
                        <li>Copy Android <strong>App ID</strong> into Admin.</li>
                    </ol>

                    <h3 class="at-firebase-setup-guide__step-title">Step 2 — <code>google-services.json</code></h3>
                    <p>Download from Firebase and place in the Android source tree: <code>android/app/google-services.json</code>. It is for Gradle/APK builds — <strong>not</strong> uploaded to PHP.</p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 3 — Native implementation (developer)</h3>
                    <ul class="at-firebase-setup-guide__ul">
                        <li>Google Services Gradle plugin &amp; Firebase Messaging SDK</li>
                        <li><code>FirebaseMessagingService</code>, token refresh, sync token to PHP (<code>ajax/push-register.php</code>, <code>platform=android</code>)</li>
                        <li>Notification channels, Android 13+ <code>POST_NOTIFICATIONS</code></li>
                        <li>Foreground / background / closed handling &amp; safe click navigation</li>
                    </ul>
                    <p><a href="<?= Security::escape($docsAndroid) ?>" target="_blank" rel="noopener noreferrer">Android FCM client docs</a></p>

                    <h3 class="at-firebase-setup-guide__step-title">Step 4 — Build, install, verify</h3>
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Build debug APK with <code>google-services.json</code> in place.</li>
                        <li>Install on device; grant notification permission.</li>
                        <li>Confirm registration in <a href="<?= Security::escape($pushBase) ?>/devices.php">Admin → Devices</a> (Android, FCM token).</li>
                        <li>Test via Admin → Send while dry-run is on.</li>
                    </ol>
                    <p class="at-firebase-setup-guide__note">Admin Panel configuration alone cannot build an APK — native code and Gradle work are required.</p>
                </div>
            </details>
            <?php endif; ?>

            <details class="at-firebase-setup-guide__doc">
                <summary class="at-firebase-setup-guide__doc-summary">5. Service Account JSON</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Firebase Console → Project settings → <strong>Service accounts</strong>.</li>
                        <li>Generate new private key → download JSON.</li>
                        <li>Upload using the <strong>Service account credentials</strong> section in the form above.</li>
                    </ol>
                    <ul class="at-firebase-setup-guide__ul">
                        <li>Never put this file in <code>public_html</code>, assets, Git, or the Android APK.</li>
                        <li>Never expose it in frontend JavaScript.</li>
                        <li>Same project service account can send to Web FID and Android FCM when configured correctly.</li>
                    </ul>
                </div>
            </details>

            <details class="at-firebase-setup-guide__doc">
                <summary class="at-firebase-setup-guide__doc-summary">6. Admin configuration workflow</summary>
                <div class="at-firebase-setup-guide__doc-body">
                    <ol class="at-firebase-setup-guide__ol">
                        <li>Create Firebase project (Console).</li>
                        <li>Select Website, Android, or both in the form above.</li>
                        <li>Enter general information &amp; platform fields.</li>
                        <li>Upload Service Account JSON → <strong>Save</strong>.</li>
                        <li><a href="<?= Security::escape($adminBase) ?>/index.php">Firebase → Projects</a> → <strong>Test</strong> credentials.</li>
                        <li><strong>Activate</strong> project (one global active project).</li>
                        <li><a href="<?= Security::escape($pushBase) ?>/settings.php">Push → Settings</a>: enable push; keep <strong>Dry run ON</strong> while testing.</li>
                    </ol>
                    <ul class="at-firebase-setup-guide__ul">
                        <li><strong>Push OFF</strong> — no sending.</li>
                        <li><strong>Push ON + Dry run ON</strong> — logs only, no live FCM.</li>
                        <li><strong>Push ON + Dry run OFF</strong> — real customer delivery possible.</li>
                    </ul>
                </div>
            </details>
        </div>
    </details>
</div>
