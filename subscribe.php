<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    $_SESSION['checkout_return'] = url_base() . '/subscribe.php?' . ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$planId = isset($_GET['plan']) ? (int) $_GET['plan'] : 0;
$error = '';
$base = url_base();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $planId = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    $slotSlug = isset($_POST['delivery_slot']) && is_string($_POST['delivery_slot']) ? trim($_POST['delivery_slot']) : '';
    $customerId = CustomerAuth::id();
    if ($customerId === null) {
        header('Location: ' . $base . '/login.php', true, 302);
        exit;
    }
    $result = SubscriptionCheckoutService::beginPurchase($planId, $customerId, $slotSlug);
    if (!$result['ok']) {
        $error = $result['message'] ?? 'Could not start subscription.';
    } else {
        header('Location: ' . ($result['redirect'] ?? $base . '/subscriptions.php'), true, 302);
        exit;
    }
}

$planRow = null;
$card = null;
if ($planId > 0 && Install::isSchemaReady() && CommerceGateway::useDatabase()) {
    $planRow = SubscriptionPlanRepository::find($planId);
    if ($planRow !== null && !empty($planRow['is_active'])) {
        $card = SubscriptionCheckoutService::publicPlanCard($planRow);
    }
}

if ($card === null) {
    layout_public_start(['pageTitle' => 'Subscribe — Apni Tokri', 'activeNav' => 'home']);
    $emptyTitle = 'Plan not available';
    $emptyMessage = 'This subscription plan is inactive or does not exist.';
    $emptyActionHref = $base . '/subscriptions.php';
    $emptyActionLabel = 'All plans';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$skipPolicy = json_decode((string) ($planRow['skip_pause_policy_json'] ?? '{}'), true) ?: [];
$price = (float) $card['price'];

layout_public_start(['pageTitle' => 'Subscribe — ' . $card['name'] . ' — Apni Tokri', 'activeNav' => 'home']);
?>
<div class="at-container py-8 md:py-12 max-w-xl">
    <a href="<?= Security::escape($base) ?>/subscriptions.php" class="text-sm font-semibold text-[var(--at-green)]">← All plans</a>
    <h1 class="at-h1 mt-4"><?= Security::escape($card['name']) ?></h1>
    <?php if ($card['description'] !== ''): ?>
        <p class="at-muted mt-2"><?= Security::escape($card['description']) ?></p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="at-alert at-alert--error mt-4" role="alert"><?= Security::escape($error) ?></div>
    <?php endif; ?>

    <div class="at-card mt-6">
        <div class="at-card__body grid gap-4">
            <dl class="at-summary-list">
                <div><dt>Plan price (INR)</dt><dd class="text-xl font-extrabold"><?= Security::escape(catalogue_format_price($price)) ?></dd></div>
                <div><dt>Calendar duration</dt><dd><?= (int) $card['calendar_duration_days'] ?> days</dd></div>
                <div><dt>Deliveries included</dt><dd><?= (int) $card['delivery_count'] ?> · <?= Security::escape($card['frequency_label']) ?></dd></div>
                <div><dt>Daily basket allowance</dt><dd><?= Security::escape(catalogue_format_price((float) $card['daily_allowance_inr'])) ?></dd></div>
                <div><dt>Unused allowance</dt><dd><?= Security::escape($card['unused_allowance_policy'] === 'carry_forward' ? 'Carries forward within rules' : 'Expires each delivery day') ?></dd></div>
                <?php if (!empty($skipPolicy['max_skips'])): ?>
                    <div><dt>Skips allowed</dt><dd>Up to <?= (int) $skipPolicy['max_skips'] ?> (cutoff <?= (int) ($skipPolicy['skip_cutoff_hours'] ?? 18) ?>h before delivery)</dd></div>
                <?php endif; ?>
                <?php if (!empty($skipPolicy['pause_allowed'])): ?>
                    <div><dt>Pause</dt><dd>Available — extends your calendar end date</dd></div>
                <?php endif; ?>
            </dl>

            <div class="at-alert at-alert--info text-sm">
                <?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?>
                <span>100% prepaid. Amount is calculated on our server at checkout. Activation and your delivery calendar are created only after successful payment verification.</span>
            </div>

            <form method="post" action="">
                <?= Csrf::field() ?>
                <input type="hidden" name="plan_id" value="<?= (int) $planId ?>">
                <?php if (DeliveryConfig::slotsEnabled()): ?>
                    <?php $slots = DeliverySlotService::availableForCheckout(); ?>
                    <div class="mt-4">
                        <label class="at-label" for="delivery_slot">Preferred delivery slot</label>
                        <select id="delivery_slot" name="delivery_slot" class="at-input at-focusable" required>
                            <option value="">Choose a slot</option>
                            <?php foreach ($slots as $s): ?>
                                <option value="<?= Security::escape((string) $s['slug']) ?>">
                                    <?= Security::escape((string) $s['label'] . ' · ' . ($s['window'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs at-muted mt-1">Applied to your delivery calendar. You can change per day before cutoff unless a slot becomes full.</p>
                    </div>
                <?php endif; ?>
                <button type="submit" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block mt-4"><?= at_icon('lock', 'w-5 h-5') ?> Continue to payment</button>
            </form>
        </div>
    </div>
</div>
<?php layout_public_end(); ?>
