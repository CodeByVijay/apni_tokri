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
<div class="at-admin-card at-firebase-guide-wrap mb-4">
    <details class="at-firebase-guide" open>
        <summary class="at-firebase-guide__summary">
            <span class="at-firebase-guide__summary-title">Firebase Setup Guide</span>
            <span class="at-firebase-guide__summary-sub">Follow this guide to connect Firebase with your Veggie Tokri website and Android application.</span>
        </summary>
        <div class="at-firebase-guide__body">
            <div class="at-firebase-guide__progress">
                <h2 class="at-firebase-guide__section-title">Setup progress</h2>
                <p class="text-sm at-muted mb-3">Green items are verified by the backend. “Manual check” means the admin must confirm outside this panel.</p>
                <div class="at-firebase-progress__grid">
                    <?php firebase_render_progress_list('Shared Firebase setup', $progress['shared'] ?? []); ?>
                    <?php if ($showWeb): ?>
                        <?php firebase_render_progress_list('Website push', $progress['website'] ?? []); ?>
                    <?php endif; ?>
                    <?php if ($showAndroid): ?>
                        <?php firebase_render_progress_list('Android WebView (native FCM)', $progress['android'] ?? []); ?>
                    <?php endif; ?>
                    <?php firebase_render_progress_list('Admin workflow', $progress['admin_workflow'] ?? []); ?>
                </div>
            </div>

            <div class="at-firebase-guide__key">
                <h2 class="at-firebase-guide__section-title">What goes where</h2>
                <table class="at-firebase-key-table text-sm">
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

            <details class="at-firebase-guide__sub" open>
                <summary class="at-firebase-guide__sub-summary">Shared Firebase project &amp; backend</summary>
                <div class="at-firebase-guide__steps">
                    <h3 class="font-bold text-base mt-2">Step 1 — Create Firebase project</h3>
                    <ol class="at-firebase-guide__ol">
                        <li>Open the <a href="<?= Security::escape($consoleUrl) ?>" target="_blank" rel="noopener noreferrer">Firebase Console</a>.</li>
                        <li>Click <strong>Create project</strong>.</li>
                        <li>Enter a name (e.g. Veggie Tokri Production, Staging, or Development).</li>
                        <li>Finish project creation (use separate Firebase projects per environment when possible).</li>
                    </ol>
                    <p class="text-sm at-muted">One Firebase project can contain both a <strong>Web</strong> app and an <strong>Android</strong> app. Veggie Tokri keeps <strong>one globally active</strong> Firebase project in Admin — you do not create separate active projects per platform.</p>

                    <h3 class="font-bold text-base mt-4">Step 2 — Project details for Admin</h3>
                    <p class="text-sm">In Firebase Console → <strong>Project settings → General</strong>, copy:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li><strong>Project ID</strong> → Admin “Firebase Project ID” (fixed after save)</li>
                        <li><strong>Project number</strong> → Admin “Sender ID / Project number” (required for Website push)</li>
                    </ul>

                    <h3 class="font-bold text-base mt-4">How both platforms reach PHP</h3>
                    <pre class="at-firebase-guide__ascii text-sm" aria-label="Architecture diagram">Firebase Project
├── Web App → Browser FID → device_push_registrations (platform=web, target_type=fid)
└── Android App → Native FCM token → device_push_registrations (platform=android, target_type=fcm_token)
        ↓
Same PHP backend (FCM HTTP v1, one active project, platform-aware send)</pre>
                    <p class="text-sm">The backend never treats a Web FID as an Android token. See <a href="<?= Security::escape($docsHttpV1) ?>" target="_blank" rel="noopener noreferrer">FCM HTTP v1 docs</a>.</p>
                </div>
            </details>

            <?php if ($showWeb): ?>
            <details class="at-firebase-guide__sub js-guide-platform-web" open>
                <summary class="at-firebase-guide__sub-summary">Website push setup</summary>
                <div class="at-firebase-guide__steps">
                    <h3 class="font-bold text-base">Step 1 — Register website app</h3>
                    <ol class="at-firebase-guide__ol">
                        <li>Firebase Console → Project Overview → <strong>Add app</strong> → Web (<code>&lt;/&gt;</code>).</li>
                        <li>Nickname suggestion: <strong>Veggie Tokri Website</strong>.</li>
                        <li>Register the app.</li>
                    </ol>

                    <h3 class="font-bold text-base mt-4">Step 2 — Copy web configuration</h3>
                    <p class="text-sm">Project settings → General → Your apps → Web app → SDK setup and configuration:</p>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>apiKey → Web API Key</li>
                        <li>appId → Web App ID</li>
                        <li>authDomain → Auth domain</li>
                        <li>storageBucket → Storage bucket</li>
                        <li>measurementId → Measurement ID (if Analytics disabled, use a placeholder such as <code>G-NOTUSED</code> in Admin — the field is required when Website push is enabled)</li>
                    </ul>

                    <h3 class="font-bold text-base mt-4">Step 3 — VAPID public key</h3>
                    <ol class="at-firebase-guide__ol">
                        <li>Project settings → <strong>Cloud Messaging</strong> → Web Push certificates.</li>
                        <li>Generate key pair if needed.</li>
                        <li>Copy the <strong>public</strong> key into Admin → VAPID public key.</li>
                    </ol>
                    <div class="at-alert at-alert--warning text-sm mt-2">Never paste the private VAPID key. Never expose Service Account JSON in the browser.</div>

                    <h3 class="font-bold text-base mt-4">Step 4 — HTTPS &amp; storefront</h3>
                    <p class="text-sm">Website push requires HTTPS (localhost is allowed for development). The site uses Firebase JS <strong>13.2.0</strong>, service worker <code>firebase-messaging-sw.js</code>, and customer FID registration — not deprecated <code>getToken()</code> as the primary web flow.</p>
                    <p class="text-sm">Customer path: log in → My Account → enable notifications → allow browser permission.</p>

                    <h3 class="font-bold text-base mt-4">Step 5 — Verify in Admin</h3>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li><a href="<?= Security::escape($pushBase) ?>/devices.php">Devices</a>: Platform <strong>Web</strong>, Target <strong>FID</strong>, Status <strong>active</strong>, Permission <strong>granted</strong>.</li>
                        <li><a href="<?= Security::escape($pushBase) ?>/send.php">Send</a>: use test device or dry-run customer send.</li>
                        <li><a href="<?= Security::escape($docsWeb) ?>" target="_blank" rel="noopener noreferrer">Firebase web messaging docs</a></li>
                    </ul>
                    <p class="text-sm at-muted mt-2">If registration fails, check: active global project, Website push enabled, push-config returns <code>enabled: true</code>, HTTPS, service worker path, and browser permission.</p>

                    <h3 class="font-bold text-base mt-4">Step 6 — Website testing checklist</h3>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li><strong>Foreground</strong> — tab focused; notification UX via in-page handler.</li>
                        <li><strong>Background</strong> — tab in background; system notification via service worker.</li>
                        <li><strong>Click</strong> — opens allowed storefront URL from payload.</li>
                        <li><strong>Unsubscribe</strong> — customer disables notifications; registration revoked server-side.</li>
                        <li><strong>Account switch</strong> — log out / different customer; prior device mapping rules apply.</li>
                        <li><strong>Permission denied</strong> — browser blocks; Admin should show permission status, not pretend delivery works.</li>
                    </ul>
                    <p class="text-sm at-muted">Use Push → Settings with <strong>Dry run ON</strong> before any live FCM test.</p>
                </div>
            </details>
            <?php endif; ?>

            <?php if ($showAndroid): ?>
            <details class="at-firebase-guide__sub js-guide-platform-android" open>
                <summary class="at-firebase-guide__sub-summary">Android WebView — native FCM setup</summary>
                <div class="at-firebase-guide__steps">
                    <p class="text-sm at-muted mb-2">The Veggie Tokri Android app shows the website in a <strong>WebView</strong>. Browser website push does <strong>not</strong> replace native Android notifications — implement native FCM separately.</p>

                    <h3 class="font-bold text-base">Step 1 — Register Android app</h3>
                    <ol class="at-firebase-guide__ol">
                        <li>Firebase Console → <strong>Add app</strong> → Android.</li>
                        <li>Package name must exactly match the app <code>applicationId</code> (e.g. <code>com.veggietokri.app</code>).</li>
                        <li>Copy Android <strong>App ID</strong> into Admin.</li>
                    </ol>

                    <h3 class="font-bold text-base mt-4">Step 2 — <code>google-services.json</code></h3>
                    <p class="text-sm">Download from Firebase and place in the Android source tree: <code>android/app/google-services.json</code>. It is for Gradle/APK builds — <strong>not</strong> uploaded to PHP and <strong>not</strong> the Service Account JSON.</p>

                    <h3 class="font-bold text-base mt-4">Step 3 — Native implementation (developer)</h3>
                    <ul class="list-disc ml-5 text-sm space-y-1">
                        <li>Google Services Gradle plugin &amp; Firebase Messaging SDK</li>
                        <li><code>FirebaseMessagingService</code>, token refresh, sync token to PHP (<code>ajax/push-register.php</code>, <code>platform=android</code>, <code>target_type=fcm_token</code>)</li>
                        <li>Notification channels, Android 13+ <code>POST_NOTIFICATIONS</code></li>
                        <li>Foreground / background / closed state handling &amp; safe click navigation (official Veggie Tokri domain only)</li>
                        <li>Avoid unrestricted WebView JavaScript bridges for push</li>
                    </ul>
                    <p class="text-sm"><a href="<?= Security::escape($docsAndroid) ?>" target="_blank" rel="noopener noreferrer">Android FCM client docs</a></p>

                    <h3 class="font-bold text-base mt-4">Step 4 — Build, install, verify</h3>
                    <ol class="at-firebase-guide__ol">
                        <li>Build debug APK with <code>google-services.json</code> in place.</li>
                        <li>Install on device; grant notification permission.</li>
                        <li>Confirm registration in <a href="<?= Security::escape($pushBase) ?>/devices.php">Admin → Devices</a> (Android, FCM token).</li>
                        <li>Test via Admin → Send while dry-run is on, then live when approved.</li>
                    </ol>
                    <p class="text-sm at-muted">Saving Firebase settings in Admin does not build an APK — native code and Gradle work are required separately.</p>
                </div>
            </details>
            <?php endif; ?>

            <details class="at-firebase-guide__sub" open>
                <summary class="at-firebase-guide__sub-summary">Service Account JSON (shared server credential)</summary>
                <div class="at-firebase-guide__steps">
                    <ol class="at-firebase-guide__ol">
                        <li>Firebase Console → Project settings → <strong>Service accounts</strong>.</li>
                        <li>Generate new private key → download JSON.</li>
                        <li>Upload in the form below (encrypted at rest on the server).</li>
                    </ol>
                    <ul class="at-firebase-guide__security text-sm space-y-1">
                        <li>Never put this file in <code>public_html</code>, assets, Git, or the Android APK.</li>
                        <li>Never expose it in frontend JavaScript.</li>
                        <li>Same project's service account can send to Web FID and Android FCM targets when platform and project config are correct.</li>
                    </ul>
                </div>
            </details>

            <details class="at-firebase-guide__sub">
                <summary class="at-firebase-guide__sub-summary">Admin configuration workflow</summary>
                <div class="at-firebase-guide__steps">
                    <ol class="at-firebase-guide__ol">
                        <li>Create Firebase project (Console).</li>
                        <li>Select Website, Android, or both below.</li>
                        <li>Enter general information &amp; platform fields.</li>
                        <li>Upload Service Account JSON → <strong>Save</strong>.</li>
                        <li><a href="<?= Security::escape($adminBase) ?>/index.php">Firebase → Projects</a> → <strong>Test</strong> credentials.</li>
                        <li><strong>Activate</strong> project (one global active project).</li>
                        <li><a href="<?= Security::escape($pushBase) ?>/settings.php">Push → Settings</a>: enable push; keep <strong>Dry run ON</strong> while testing.</li>
                        <li>Verify devices, then send controlled tests.</li>
                    </ol>
                    <ul class="list-disc ml-5 text-sm space-y-1 mt-2">
                        <li><strong>Push OFF</strong> — no sending.</li>
                        <li><strong>Push ON + Dry run ON</strong> — logs only, no live FCM.</li>
                        <li><strong>Push ON + Dry run OFF</strong> — real customer delivery possible.</li>
                    </ul>
                    <p class="text-sm at-muted mt-2">Activation does not grant notification permission on customer devices.</p>
                </div>
            </details>
        </div>
    </details>
</div>
