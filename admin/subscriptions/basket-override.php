<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$errors = [];
$success = '';

$subNumber = isset($_GET['sub']) && is_string($_GET['sub']) ? trim($_GET['sub']) : '';
$deliveryDate = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $subId = (int) ($_POST['subscription_id'] ?? 0);
    $dayId = (int) ($_POST['day_id'] ?? 0);
    $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? trim($_POST['reason']) : '';
    $lines = [];
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $lines[] = [
                'variant_id' => (int) ($it['variant_id'] ?? 0),
                'qty' => (float) ($it['qty'] ?? 0),
            ];
        }
    }
    $adminId = AdminAuth::id();
    if ($adminId === null) {
        $errors[] = 'Admin session required.';
    } else {
        $result = SubscriptionLifecycleService::adminOverrideBasket($adminId, $subId, $dayId, $lines, $reason);
        if ($result['ok']) {
            admin_flash_set('Basket updated. Customer has been notified.', 'success');
            header('Location: ' . $base . '/basket-override.php?sub=' . rawurlencode((string) ($_POST['subscription_number'] ?? '')) . '&date=' . rawurlencode((string) ($_POST['delivery_date'] ?? '')), true, 302);
            exit;
        }
        $errors[] = $result['message'] ?? 'Override failed.';
    }
}

$sub = null;
$day = null;
$basket = null;
$items = [];
$variants = InventoryService::variantsForSelect();

if ($subNumber !== '' && Install::isSchemaReady()) {
    $sub = CustomerSubscriptionRepository::findByNumber($subNumber);
    if ($sub !== null && $deliveryDate !== '') {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_delivery_days WHERE customer_subscription_id = ? AND delivery_date = ? LIMIT 1'
        );
        $stmt->execute([(int) $sub['id'], $deliveryDate]);
        $day = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($day !== null) {
            $basket = SubscriptionBasketRepository::basketByDayId((int) $day['id']);
            if ($basket !== null) {
                $items = SubscriptionBasketRepository::basketItems((int) $basket['id']);
            }
        }
    }
}

admin_layout_start(['pageTitle' => 'Basket override', 'activeAdminNav' => 'subscriptions']);
admin_flash_render();
$pageHeading = 'Admin basket override';
$pageDescription = 'Explicit override with reason, audit trail and customer notification. Never silent.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<?php if ($errors !== []): ?>
    <div class="at-alert at-alert--error"><?= Security::escape(implode(' ', $errors)) ?></div>
<?php endif; ?>

<form method="get" class="flex flex-wrap gap-3 items-end mb-6">
    <div><label class="at-label">Subscription number</label><input class="at-input at-focusable" name="sub" value="<?= Security::escape($subNumber) ?>" required></div>
    <div><label class="at-label">Delivery date</label><input type="date" class="at-input at-focusable" name="date" value="<?= Security::escape($deliveryDate) ?>" required></div>
    <button type="submit" class="at-btn at-btn--secondary at-focusable">Load</button>
</form>

<?php if ($sub === null && $subNumber !== ''): ?>
    <div class="at-alert at-alert--warning">Subscription not found.</div>
<?php elseif ($day === null && $sub !== null && $deliveryDate !== ''): ?>
    <div class="at-alert at-alert--warning">No delivery day on this date.</div>
<?php elseif ($sub !== null && $day !== null): ?>
    <div class="at-card mb-4">
        <div class="at-card__body text-sm">
            <p><strong>Customer subscription:</strong> <?= Security::escape($subNumber) ?></p>
            <p><strong>Day status:</strong> <?= Security::escape((string) $day['status']) ?> · <strong>Basket:</strong> <?= Security::escape($basket ? (string) $basket['status'] : 'none') ?></p>
            <?php if ($basket && (string) $basket['status'] === 'confirmed'): ?>
                <p class="at-muted mt-2">Current confirmed basket (will be replaced after override):</p>
                <ul class="mt-2">
                    <?php foreach ($items as $it): ?>
                        <li><?= Security::escape($it['product_name'] . ' — ' . $it['qty'] . ' @ ' . catalogue_format_price((float) $it['unit_price_inr'])) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" class="at-card">
        <div class="at-card__body grid gap-4 max-w-3xl">
            <?= Csrf::field() ?>
            <input type="hidden" name="subscription_id" value="<?= (int) $sub['id'] ?>">
            <input type="hidden" name="day_id" value="<?= (int) $day['id'] ?>">
            <input type="hidden" name="subscription_number" value="<?= Security::escape($subNumber) ?>">
            <input type="hidden" name="delivery_date" value="<?= Security::escape($deliveryDate) ?>">
            <div>
                <label class="at-label">Mandatory reason (shown to customer)</label>
                <textarea name="reason" class="at-input at-focusable w-full" rows="2" required></textarea>
            </div>
            <h3 class="at-h3">New basket lines</h3>
            <?php
            $rows = $items !== [] ? $items : [['variant_id' => '', 'qty' => 1]];
            foreach ($rows as $i => $it):
                ?>
                <div class="grid sm:grid-cols-3 gap-2">
                    <select class="at-input at-focusable" name="items[<?= (int) $i ?>][variant_id]">
                        <option value="">—</option>
                        <?php foreach ($variants as $v): ?>
                            <option value="<?= (int) $v['id'] ?>" <?= (int) ($it['variant_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>>
                                <?= Security::escape($v['product_name'] . ' — stock ' . $v['stock_qty']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="0.001" min="0.001" class="at-input at-focusable" name="items[<?= (int) $i ?>][qty]" value="<?= Security::escape((string) ($it['qty'] ?? '1')) ?>">
                </div>
            <?php endforeach; ?>
            <button type="submit" class="at-btn at-btn--primary at-focusable">Apply override &amp; notify customer</button>
        </div>
    </form>
<?php endif; ?>
<p class="mt-4 text-sm"><a class="underline" href="<?= Security::escape($base) ?>/customer-baskets.php">Customer baskets</a></p>
<?php admin_layout_end(); ?>
