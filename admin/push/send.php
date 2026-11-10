<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

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
$pageDescription = 'Dry-run is default on non-production unless push.dry_run=0 in settings.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<?php if ($message !== ''): ?><div class="at-alert at-alert--success mb-4"><?= Security::escape($message) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="at-alert at-alert--error mb-4"><?= Security::escape($e) ?></div><?php endforeach; ?>

<div class="grid lg:grid-cols-2 gap-6 max-w-5xl">
    <form method="post" class="at-card">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="test_device">
            <h3 class="font-bold">Test device</h3>
            <select name="platform" class="at-input"><option value="web">Web</option><option value="android">Android</option></select>
            <input class="at-input" name="fcm_token" placeholder="Web: Firebase Installation ID (FID) / Android: FCM token" required>
            <input class="at-input" name="title" placeholder="Title">
            <input class="at-input" name="body" placeholder="Body">
            <button class="at-btn at-btn--primary" type="submit">Send test</button>
        </div>
    </form>
    <form method="post" class="at-card" onsubmit="return confirm('Send to all active devices for this customer?');">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="customer">
            <h3 class="font-bold">Customer</h3>
            <input class="at-input" name="customer_id" type="number" min="1" placeholder="Customer ID" required>
            <input class="at-input" name="title" placeholder="Title" required>
            <textarea class="at-input" name="body" rows="2" placeholder="Body" required></textarea>
            <button class="at-btn at-btn--primary" type="submit">Send</button>
        </div>
    </form>
</div>

<?php admin_layout_end(); ?>
