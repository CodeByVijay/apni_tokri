<?php

declare(strict_types=1);

/**
 * Admin assisted order — minimum-order early status + controlled override.
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-min-order.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';
require_once __DIR__ . '/includes/admin_assisted_test_helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function moOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
TestScriptSafety::assertSafeToMutateSettings();
AppSettings::set('marketing.min_order_enabled', '1');
AppSettings::set('marketing.min_order_amount_inr', '200');
AppSettings::resetCache();

$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);

// Fixture: customer + address + one cheap variant (₹40 < ₹200 minimum).
$pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?))')->execute(['9800666601']);
$pdo->prepare('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?))')->execute(['9800666601']);
$pdo->prepare('DELETE FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800666601']);
$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile = ?)')->execute(['9800666601']);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9800666601']);
$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['9800666601', 'Min Order Tester']);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custId, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'Recv', '9800666601']);
$addrId = (int) $pdo->lastInsertId();
$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);
moOk($varId > 0, 'fixture variant exists');

$base = [
    'customer_id' => $custId,
    'address_id' => $addrId,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 1]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
    'payment_mode' => 'pending',
];

// 1. Early-warning payload: non-strict quote exposes full min-order status (global fallback).
$q1 = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($base));
$mq = $q1['quote'] ?? [];
moOk(!empty($q1['ok']), 'quote ok below minimum');
moOk(!empty($mq['min_order_enabled']), 'min-order enabled flag');
moOk(empty($mq['min_order_met']), 'min-order not met');
moOk((float) ($mq['min_order_amount_inr'] ?? 0) === 200.0, 'global fallback amount 200');
moOk((float) ($mq['min_order_merchandise_inr'] ?? 0) === 40.0, 'eligible merchandise 40 (no delivery fee)');
moOk((float) ($mq['min_order_shortfall'] ?? 0) === 160.0, 'shortfall 160');
moOk(($mq['min_order_source'] ?? '') === 'global', 'source global fallback');
$fee = (float) ($mq['delivery_fee'] ?? 0);
$grand = (float) ($mq['grand_total'] ?? 0);
moOk(abs($grand - (40.0 + $fee)) < 0.01, 'delivery fee excluded from eligible merchandise');

// 2. Strict quote + place blocked with admin wording (not "proceed to checkout").
$qs = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($base), true);
moOk(empty($qs['ok']), 'strict quote rejected below minimum');
moOk(str_contains((string) ($qs['message'] ?? ''), 'create this order'), 'admin wording on rejection');
moOk(!str_contains((string) ($qs['message'] ?? ''), 'proceed to checkout'), 'no customer-facing wording');
$pl = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($base));
moOk(empty($pl['ok']), 'placeOrder rejected below minimum');

// 3. Invalid override inputs rejected.
$badReason = $base + ['min_order_override' => '1', 'min_order_override_reason' => 'made_up_reason'];
$br = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($badReason));
moOk(empty($br['ok']) && str_contains((string) ($br['message'] ?? ''), 'override reason'), 'invalid reason rejected');
$blankReason = $base + ['min_order_override' => '1', 'min_order_override_reason' => ''];
$blr = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($blankReason), true);
moOk(empty($blr['ok']), 'blank reason rejected');
$noNote = $base + ['min_order_override' => '1', 'min_order_override_reason' => 'other'];
$nn = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($noNote), true);
moOk(empty($nn['ok']) && str_contains((string) ($nn['message'] ?? ''), 'Explain'), 'other-without-note rejected');

// 4. Authorized override: strict quote + placement succeed; meta + audit persisted.
$good = $base + [
    'min_order_override' => '1',
    'min_order_override_reason' => 'customer_support_exception',
];
$gq = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($good), true);
moOk(!empty($gq['ok']), 'strict quote ok with valid override');
$gqData = $gq['quote'] ?? [];
moOk(is_array($gqData['min_order_override'] ?? null)
    && ($gqData['min_order_override']['reason'] ?? '') === 'customer_support_exception',
    'quote echoes override meta');
moOk(empty($gqData['min_order_met']), 'override does not fake min_order_met');
$good['place_submission_token'] = (string) ($gqData['place_submission_token'] ?? '');
$good['client_grand_total'] = (string) ($gqData['grand_total'] ?? '0');
$gp = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($good));
moOk(!empty($gp['ok']), 'placeOrder ok with authorized override');
$orderId = (int) ($gp['order_id'] ?? 0);

$meta = json_decode((string) ($pdo->query('SELECT admin_meta_json FROM orders WHERE id = ' . $orderId)->fetchColumn() ?: ''), true);
$mo = is_array($meta) ? ($meta['min_order_override'] ?? null) : null;
moOk(is_array($mo) && ($mo['reason'] ?? '') === 'customer_support_exception', 'order admin_meta records override reason');
moOk((float) ($mo['required_min'] ?? 0) === 200.0 && (float) ($mo['eligible_merchandise'] ?? 0) === 40.0
    && (float) ($mo['shortfall'] ?? 0) === 160.0, 'order admin_meta records required/eligible/shortfall');
$audit = $pdo->prepare('SELECT admin_user_id, payload_json FROM admin_audit_logs WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
$audit->execute(['order.min_order_override', (string) $orderId]);
$auditRow = $audit->fetch(PDO::FETCH_ASSOC);
moOk(is_array($auditRow) && (int) $auditRow['admin_user_id'] === $superId, 'audit row records admin id');
$auditPayload = is_array($auditRow) ? json_decode((string) $auditRow['payload_json'], true) : null;
moOk(is_array($auditPayload) && ($auditPayload['reason_label'] ?? '') === 'Customer support exception', 'audit payload records reason');

// 5. Tamper: token issued for reason A cannot place with reason B.
$tamper = $base + ['min_order_override' => '1', 'min_order_override_reason' => 'first_time_customer'];
$tamper['place_submission_token'] = (string) ($gqData['place_submission_token'] ?? '');
$tamper['client_grand_total'] = (string) ($gqData['grand_total'] ?? '0');
$tp = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($tamper));
moOk(empty($tp['ok']), 'tampered override reason rejected (signature bound)');

// 6. Per-order: a second below-minimum order without override is still rejected.
$second = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($base));
moOk(empty($second['ok']), 'override does not persist to next order');

// 7. Zone minimum overrides global when configured.
$colExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_zones' AND COLUMN_NAME = 'minimum_order_amount'"
)->fetchColumn();
moOk($colExists === 1, 'delivery_zones.minimum_order_amount column present (1.42.0)');
if ($colExists === 1) {
    $pdo->exec("UPDATE delivery_zones SET minimum_order_amount = 150.00");
    AppSettings::resetCache();
    $qz = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($base));
    $mz = $qz['quote'] ?? [];
    moOk(!empty($qz['ok']) && ($mz['min_order_source'] ?? '') === 'zone'
        && (float) ($mz['min_order_amount_inr'] ?? 0) === 150.0, 'zone minimum overrides global');
    $pdo->exec("UPDATE delivery_zones SET minimum_order_amount = NULL");
    AppSettings::resetCache();
}

// 8. Amount meets minimum → no warning state, strict quote passes.
AppSettings::set('marketing.min_order_amount_inr', '30');
AppSettings::resetCache();
$qm = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($base), true);
$mm = $qm['quote'] ?? [];
moOk(!empty($qm['ok']) && !empty($mm['min_order_met']), 'min-order met when eligible >= minimum');
AppSettings::set('marketing.min_order_amount_inr', '200');
AppSettings::resetCache();

// 9. Unauthorized admin: create_on_behalf but NOT orders.override_min_order.
$pdo->exec("INSERT INTO admin_roles (slug, name) VALUES ('min_order_limited', 'Min Order Limited')");
$limitRid = (int) $pdo->lastInsertId();
$createPerm = (int) $pdo->query("SELECT id FROM admin_permissions WHERE slug = 'orders.create_on_behalf'")->fetchColumn();
$pdo->prepare('INSERT INTO admin_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$limitRid, $createPerm]);
$limitedId = stage14_p1_create_user_with_role($pdo, 'minorder-limited@test.local', 'min_order_limited');
stage14_p1_session($limitedId);
$ua = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($good), true);
moOk(empty($ua['ok']) && str_contains((string) ($ua['message'] ?? ''), 'permission'), 'unauthorized override rejected');
$uaPlace = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($base));
moOk(empty($uaPlace['ok']), 'unauthorized admin still bound by minimum');
stage14_p1_session($superId);

// 10. Storefront checkout rules untouched — global min still rejects storefront totals.
$sf = MarketingSettings::checkoutMinimumRejection(['subtotal' => 40.0, 'offer_discount' => 0, 'coupon_discount' => 0, 'total' => 40.0]);
moOk(is_string($sf) && $sf !== '', 'storefront checkoutMinimumRejection unchanged');

echo ($fail === 0 ? "All minimum-order override tests passed.\n" : "FAILURES: {$fail}\n");
exit($fail === 0 ? 0 : 1);
