<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pdo->exec(
    "INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
     VALUES ('WELCOME10', 'Test fixture', 'percent', 10, 100, 1, 1)
     ON DUPLICATE KEY UPDATE discount_type = 'percent', discount_value = 10, min_cart_value = 100,
     is_active = 1, stackable_with_offers = 1, ends_at = NULL"
);
$fail = 0;

function assertOk(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'OK' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

$lines = [
    ['qty' => 2, 'unit_price' => 200.0, 'product_id' => 1, 'category_id' => 1, 'variant_id' => 1],
];
$offer = PromotionService::bestAutomaticOffer($lines, null);
assertOk(is_array($offer) && isset($offer['discount']), 'Offer engine returns discount');

$r = CouponService::validate('WELCOME10', 400.0, null, $lines, 50.0, false);
assertOk(!$r['ok'], 'Non-stackable coupon blocked when offer discount present');

$r2 = CouponService::validate('WELCOME10', 400.0, null, $lines, 0.0, false);
assertOk($r2['ok'] && (float) ($r2['discount'] ?? 0) > 0, 'Valid coupon applies without offer');

$dup = PaymentService::storeWebhookEvent('test_promo', 'evt_stack_' . time(), '{}');
// use separate event id for coupon webhook table - actually use payment_webhook_events
$eid = 'promo_test_' . Security::randomToken(3);
assertOk(PaymentService::storeWebhookEvent('internal', $eid, '{}'), 'Idempotency store');
assertOk(!PaymentService::storeWebhookEvent('internal', $eid, '{}'), 'Duplicate idempotency rejected');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 10 promotion checks.\n";
exit(0);
