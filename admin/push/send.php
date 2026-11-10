<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $mode = (string) ($_POST['mode'] ?? 'test_device');
    if ($mode === 'test_device') {
        $result = PushNotificationService::sendAdminTest(
            (string) ($_POST['platform'] ?? 'web'),
            (string) ($_POST['fcm_token'] ?? ''),
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['body'] ?? '')
        );
        if (!empty($result['ok'])) {
            $message = (string) ($result['message'] ?? 'Sent.');
        } else {
            $errors[] = (string) ($result['message'] ?? 'Failed.');
        }
    } elseif ($mode === 'customer') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $result = PushNotificationService::sendToCustomer(
            $customerId,
            'announcement.general.push',
            [
                'title' => (string) ($_POST['title'] ?? 'Announcement'),
                'body' => (string) ($_POST['body'] ?? ''),
            ],
            'push:admin:manual:customer:' . $customerId . ':' . time(),
            'admin.manual',
            []
        );
        $message = !empty($result['ok']) ? 'Queued/sent to customer devices.' : 'Could not send.';
        if (!empty($result['dry_run'])) {
            $message .= ' (dry-run — no FCM call)';
        }
    }
}

admin_layout_start(['pageTitle' => 'Send push', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Send push notification';
$pageDescription = 'Dry-run is default on non-production unless push.dry_run=0 in settings. Manual sends remaining today: ' . (int) PushSettings::manualSendRemainingToday() . '.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('send');
?>

<?php if ($message !== ''): ?><div class="at-alert at-alert--success mb-4"><?= Security::escape($message) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="at-alert at-alert--error mb-4"><?= Security::escape($e) ?></div><?php endforeach; ?>

<div class="grid lg:grid-cols-2 gap-6 max-w-5xl mx-auto">
    <form method="post" class="at-admin-card">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="test_device">
            <h3 class="font-bold">Test device</h3>
            <p class="text-xs at-muted">Sends one message to a single registration token. Uses the <strong>active</strong> Firebase project. Counts toward your daily manual send limit.</p>
            <div>
                <label class="at-label" for="test-platform">Platform</label>
                <select id="test-platform" name="platform" class="at-input at-focusable">
                    <option value="web">Web</option>
                    <option value="android">Android</option>
                </select>
                <p class="text-xs at-muted mt-1"><strong>Web</strong> expects a Firebase Installation ID (FID). <strong>Android</strong> expects an FCM registration token.</p>
            </div>
            <div>
                <label class="at-label" for="test-fcm_token">Device target</label>
                <input class="at-input at-focusable" id="test-fcm_token" name="fcm_token" required
                       placeholder="Web e.g. c8mXyZ123… (FID) · Android e.g. dK7aP9… (long FCM token)">
                <p class="text-xs at-muted mt-1">Copy from Device registrations (token prefix) or from browser dev tools after storefront register.</p>
            </div>
            <div>
                <label class="at-label" for="test-title">Notification title</label>
                <input class="at-input at-focusable" id="test-title" name="title"
                       placeholder="e.g. Test from Veggie Tokri Admin">
            </div>
            <div>
                <label class="at-label" for="test-body">Notification body</label>
                <input class="at-input at-focusable" id="test-body" name="body"
                       placeholder="e.g. If you see this, FCM delivery works for this device.">
            </div>
            <p class="text-xs at-muted">Click URL and image are not configurable in this form; the test uses the storefront homepage URL.</p>
            <button class="at-btn at-btn--primary" type="submit">Send test</button>
        </div>
    </form>
    <form method="post" class="at-admin-card" onsubmit="return confirm('Send to all active devices for this customer?');">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="customer">
            <h3 class="font-bold">Customer broadcast</h3>
            <p class="text-xs at-muted">Sends the <code>announcement.general.push</code> template to every <strong>active</strong> device for the customer on the active Firebase project (Web + Android).</p>
            <div>
                <label class="at-label" for="customer_id">Customer ID</label>
                <input class="at-input at-focusable" id="customer_id" name="customer_id" type="number" min="1" required
                       placeholder="e.g. 42">
                <p class="text-xs at-muted mt-1">Numeric ID from Admin → Customers (not mobile number).</p>
            </div>
            <div>
                <label class="at-label" for="cust-title">Notification title</label>
                <input class="at-input at-focusable" id="cust-title" name="title" required
                       placeholder="e.g. Fresh arrivals this week">
            </div>
            <div>
                <label class="at-label" for="cust-body">Notification body</label>
                <textarea class="at-input at-focusable" id="cust-body" name="body" rows="2" required
                          placeholder="e.g. New seasonal vegetables are available — order before 6 PM for tomorrow delivery."></textarea>
            </div>
            <p class="text-xs at-muted">Destination URL comes from the template (<code>announcement.general.push</code>), currently <code>/</code> unless you edit it under Push templates.</p>
            <button class="at-btn at-btn--primary" type="submit">Send</button>
        </div>
    </form>
</div>

<?php admin_layout_end(); ?>
