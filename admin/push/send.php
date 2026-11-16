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
        if (!empty($result['ok'])) {
            $message = 'Queued/sent to customer devices.';
            if (!empty($result['dry_run'])) {
                $message .= ' (dry-run — no FCM call)';
            }
        } else {
            $errors[] = (string) ($result['message'] ?? 'Could not send.');
        }
    } elseif ($mode === 'customer_coupon') {
        $customerId = (int) ($_POST['coupon_customer_id'] ?? 0);
        $couponId = (int) ($_POST['coupon_id'] ?? 0);
        $clickOverride = trim((string) ($_POST['coupon_click_url'] ?? ''));
        $result = PushCouponNotificationService::sendPromotionalCouponToCustomer(
            $customerId,
            $couponId,
            $clickOverride !== '' ? $clickOverride : null
        );
        if (!empty($result['ok'])) {
            $message = 'Promotional coupon notification queued/sent.';
            if (!empty($result['dry_run'])) {
                $message .= ' (dry-run — no FCM call)';
            }
        } else {
            $errors[] = (string) ($result['message'] ?? 'Could not send coupon notification.');
        }
    }
}

$couponPickList = [];
if (CommerceGateway::useDatabase() && PushSchema::tableReady('coupons')) {
    Install::ensureConnection();
    $couponPickList = Database::pdo()->query(
        'SELECT id, code, internal_name, is_active, ends_at FROM coupons ORDER BY id DESC LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

admin_layout_start(['pageTitle' => 'Send push', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Send push notification';
$pageDescription = 'Dry-run is default on non-production unless push.dry_run=0 in settings. Manual sends remaining today: ' . (int) PushSettings::manualSendRemainingToday() . '.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('send');
?>

<?php if ($message !== ''): ?><div class="at-alert at-alert--success mb-4"><?= Security::escape($message) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="at-alert at-alert--error mb-4"><?= Security::escape($e) ?></div><?php endforeach; ?>

<div class="grid lg:grid-cols-2 xl:grid-cols-3 gap-6 max-w-6xl mx-auto">
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
    <form method="post" class="at-admin-card" onsubmit="return confirm('Send promotional coupon push to all active devices for this customer?');">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="mode" value="customer_coupon">
            <h3 class="font-bold">Promotional coupon</h3>
            <p class="text-xs at-muted">Sends the <code>coupon.promotional.push</code> template. Title, body, and placeholders are filled from the selected coupon and customer records. Expired or ineligible coupons are rejected before send.</p>
            <div>
                <label class="at-label" for="coupon_customer_id">Customer ID</label>
                <input class="at-input at-focusable" id="coupon_customer_id" name="coupon_customer_id" type="number" min="1" required
                       placeholder="e.g. 42">
            </div>
            <div>
                <label class="at-label" for="coupon_id">Coupon</label>
                <select id="coupon_id" name="coupon_id" class="at-input at-focusable" required>
                    <option value="">— Select coupon —</option>
                    <?php foreach ($couponPickList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>">
                            <?= Security::escape((string) $c['code']) ?>
                            <?php if (!empty($c['internal_name'])): ?>
                                (<?= Security::escape((string) $c['internal_name']) ?>)
                            <?php endif; ?>
                            <?php if (empty($c['is_active'])): ?> · inactive<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs at-muted mt-1">Availability and expiry are validated at send time (not only from this list).</p>
            </div>
            <div>
                <label class="at-label" for="coupon_click_url">Click URL (optional)</label>
                <input class="at-input at-focusable" id="coupon_click_url" name="coupon_click_url"
                       placeholder="Default: /cart.php">
                <p class="text-xs at-muted mt-1">Relative storefront path or same-host URL. Leave blank for cart.</p>
            </div>
            <p class="text-xs at-muted">Variables: <code>{customer_name}</code>, <code>{coupon_code}</code>, <code>{discount_value}</code>, <code>{minimum_order}</code>, <code>{expiry_date}</code>, <code>{click_url}</code></p>
            <button class="at-btn at-btn--primary" type="submit">Send coupon push</button>
        </div>
    </form>
</div>

<?php admin_layout_end(); ?>
