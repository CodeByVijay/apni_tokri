<?php

declare(strict_types=1);

/**
 * Test delivery address — result summary (presentation only).
 *
 * @var array<string, mixed> $test from DeliveryAdminUi::testCalculation()
 */
if (!isset($test) || !is_array($test)) {
    return;
}

if ($test['errors'] !== []): ?>
    <div class="at-alert at-alert--error mt-4" role="alert">
        <ul class="list-disc pl-5 text-sm mb-0"><?php foreach ($test['errors'] as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php return; endif;

$ok = !empty($test['serviceable']);
$pin = (string) ($test['pin'] ?? '');
?>
<div class="at-delivery-test-result <?= $ok ? 'at-delivery-test-result--ok' : 'at-delivery-test-result--no' ?>" role="status">
    <div class="at-delivery-test-result__head">
        <p class="at-delivery-test-result__status mb-0"><?= $ok ? 'Delivery available' : 'Delivery unavailable' ?></p>
        <p class="at-delivery-test-result__pin mb-0">Pincode <?= Security::escape($pin) ?></p>
    </div>

    <?php if (!$ok): ?>
        <p class="at-delivery-test-result__reason mb-0"><?= Security::escape((string) ($test['message'] ?? 'Delivery is not available for this address.')) ?></p>
        <?php foreach ($test['notes'] as $n): ?>
            <p class="at-delivery-test-result__notes-line mb-0"><?= Security::escape($n) ?></p>
        <?php endforeach; ?>
    <?php else:
        $feeForOrder = isset($test['fee_for_amount']) ? (float) $test['fee_for_amount'] : null;
        $amountEntered = isset($test['amount']) ? (float) $test['amount'] : null;
        if ($feeForOrder !== null) {
            $feeHeadline = $feeForOrder > 0 ? DeliveryAdminUi::inr($feeForOrder) : 'Free delivery';
            $feeSub = 'For a ' . DeliveryAdminUi::inr($amountEntered ?? 0) . ' order';
        } else {
            $feeHeadline = DeliveryAdminUi::inr($test['flat_fee'] ?? 0);
            $feeSub = 'Standard delivery charge (enter an order amount above for an exact quote)';
        }
        ?>
        <div class="at-delivery-test-result__fee-block">
            <p class="at-delivery-test-result__fee mb-0"><?= Security::escape($feeHeadline) ?></p>
            <p class="at-delivery-test-result__fee-sub mb-0"><?= Security::escape($feeSub) ?></p>
        </div>

        <dl class="at-delivery-test-result__rows">
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Delivery area</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape((string) ($test['zone_name'] ?? '')) ?></dd>
            </div>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Matching method</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape((string) ($test['method'] ?? '')) ?></dd>
            </div>
            <?php if (($test['distance_km'] ?? null) !== null): ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Distance</dt>
                <dd class="at-delivery-test-result__value">About <?= Security::escape(DeliveryAdminUi::km($test['distance_km'])) ?> (straight-line)</dd>
            </div>
            <?php endif; ?>
            <?php if ($amountEntered !== null): ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Order amount</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape(DeliveryAdminUi::inr($amountEntered)) ?></dd>
            </div>
            <?php endif; ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Free delivery from</dt>
                <dd class="at-delivery-test-result__value">Orders of <?= Security::escape(DeliveryAdminUi::inr($test['free_above'] ?? 0)) ?> or more</dd>
            </div>
            <?php if (isset($test['min_order_effective']) && $test['min_order_effective'] !== null): ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Minimum order</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape(DeliveryAdminUi::inr($test['min_order_effective'])) ?><?= !empty($test['min_order_zone']) ? ' (this delivery area)' : ' (global marketing setting)' ?></dd>
            </div>
            <?php endif; ?>
            <?php if (trim((string) ($test['eta'] ?? '')) !== ''): ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Expected delivery time</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape((string) $test['eta']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (isset($test['max_weight']) && $test['max_weight'] !== null): ?>
            <div class="at-delivery-test-result__row">
                <dt class="at-delivery-test-result__label">Weight limit</dt>
                <dd class="at-delivery-test-result__value"><?= Security::escape((string) $test['max_weight']) ?> kg per order</dd>
            </div>
            <?php endif; ?>
        </dl>

        <?php if ($test['notes'] !== []): ?>
            <div class="at-delivery-test-result__notes">
                <?php foreach ($test['notes'] as $n): ?>
                    <p class="mb-0"><?= Security::escape($n) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<details class="at-delivery-test-result__tech text-xs mt-3">
    <summary class="cursor-pointer at-muted">Technical details (for support)</summary>
    <pre class="at-delivery-test-result__pre"><?= Security::escape(json_encode($test['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</details>
