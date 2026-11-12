<?php

declare(strict_types=1);

/**
 * Admin assisted order — quote (delivery, coupon, custom discount).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-quote.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function aqOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800222201']);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9800222201']);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800222201', 'Quote Customer', 'quote@example.invalid']);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custId, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'Rcv', '9800222201']);
$addrId = (int) $pdo->lastInsertId();
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);

$base = [
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 2]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
];

$q1 = AdminAssistedOrderService::quote($base);
aqOk(!empty($q1['ok']), 'baseline quote');
$fee1 = (float) ($q1['quote']['delivery_fee'] ?? -1);
aqOk($fee1 >= 0, 'delivery fee present');

$baseOff = $base;
$baseOff['apply_delivery_charges'] = '0';
$qOff = AdminAssistedOrderService::quote($baseOff);
aqOk(!empty($qOff['ok']) && (float) ($qOff['quote']['delivery_fee'] ?? 1) === 0.0, 'delivery charges off');

$baseFree = $base;
$baseFree['free_delivery'] = '1';
$baseFree['free_delivery_reason'] = '';
$qFreeBad = AdminAssistedOrderService::quote($baseFree);
aqOk(empty($qFreeBad['ok']), 'free delivery requires reason');

$baseFree['free_delivery_reason'] = 'Goodwill';
$qFree = AdminAssistedOrderService::quote($baseFree);
aqOk(!empty($qFree['ok']) && (float) ($qFree['quote']['delivery_fee'] ?? 1) === 0.0, 'free delivery override');
aqOk((float) ($qFree['quote']['calculated_delivery_fee'] ?? 0) >= 0, 'stores calculated fee');

$wrongAddr = $base;
$wrongAddr['address_id'] = 999999;
$qBad = AdminAssistedOrderService::quote($wrongAddr);
aqOk(empty($qBad['ok']), 'invalid address rejected');

$custom = $base;
$custom['custom_discount_enabled'] = '1';
$custom['custom_discount_type'] = 'flat';
$custom['custom_discount_value'] = '5';
$custom['custom_discount_reason'] = 'Support';
$qc = AdminAssistedOrderService::quote($custom);
aqOk(!empty($qc['ok']) && (float) ($qc['quote']['custom_discount'] ?? 0) === 5.0, 'flat custom discount');

$custom['custom_discount_reason'] = '';
$qcBad = AdminAssistedOrderService::quote($custom);
aqOk(empty($qcBad['ok']), 'custom discount requires reason');

$q1b = AdminAssistedOrderService::quote($base);
$gt1 = (float) ($q1b['quote']['grand_total'] ?? 0);
$base['lines'] = json_encode([['variant_id' => $varId, 'quantity' => 5]], JSON_THROW_ON_ERROR);
$q2 = AdminAssistedOrderService::quote($base);
aqOk(!empty($q2['ok']) && (float) ($q2['quote']['grand_total'] ?? 0) !== $gt1, 'cart change recalculates quote');

$pdo->prepare('DELETE FROM coupons WHERE code = ?')->execute(['ATQUOTE10']);
$pdo->prepare(
    'INSERT INTO coupons (code, discount_type, discount_value, min_cart_value, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY))'
)->execute(['ATQUOTE10', 'fixed', 10, 0]);

$withCoupon = $base;
$withCoupon['lines'] = json_encode([['variant_id' => $varId, 'quantity' => 2]], JSON_THROW_ON_ERROR);
$withCoupon['coupon_code'] = 'ATQUOTE10';
$qCoupon = AdminAssistedOrderService::quote($withCoupon);
aqOk(!empty($qCoupon['ok']) && (float) ($qCoupon['quote']['coupon_discount'] ?? 0) >= 10.0, 'valid coupon for customer');

$withCoupon['coupon_code'] = 'ATQUOTE_NOPE';
$qBadCoupon = AdminAssistedOrderService::quote($withCoupon);
aqOk(empty($qBadCoupon['ok']), 'invalid coupon rejected');

$percent = $base;
$percent['lines'] = json_encode([['variant_id' => $varId, 'quantity' => 2]], JSON_THROW_ON_ERROR);
$percent['custom_discount_enabled'] = '1';
$percent['custom_discount_type'] = 'percent';
$percent['custom_discount_value'] = '10';
$percent['custom_discount_reason'] = 'VIP';
$qp = AdminAssistedOrderService::quote($percent);
aqOk(!empty($qp['ok']) && (float) ($qp['quote']['custom_discount'] ?? 0) > 0, 'percent custom discount');

stage14_p1_clear_session();
echo $fail === 0 ? "All assisted-order quote tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
