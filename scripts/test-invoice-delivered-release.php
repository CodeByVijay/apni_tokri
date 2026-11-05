<?php

declare(strict_types=1);

/**
 * Customer invoice visibility and delivery-triggered email.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-invoice-delivered-release.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();

$fail = 0;
function okI(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$tok = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$mobile = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobile, 'Inv Test', 'inv-' . strtolower($tok) . '@example.test']);
$cid = (int) $pdo->lastInsertId();
$snap = json_encode(['address' => ['receiver_name' => 'R', 'receiver_phone' => '9876543210', 'line1' => '1 St', 'city' => 'Indore', 'state' => 'MP', 'pincode' => '452001', 'label' => 'Home']], JSON_UNESCAPED_UNICODE);
$on = 'INV-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$on, 'storefront', $cid, 'confirmed', 100, 0, 40, 140, 'paid', $snap]);
$oid = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oid, 'Tomato', '500 g', 'SKU1', 2, 50, 100]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oid, 'razorpay', 'gw', 'pay', 140, 'INR', 'captured']);

AppSettings::set('invoice.enable_email', '1');
AppSettings::set('invoice.auto_email', '1');
AppSettings::resetCache();

$gen = InvoiceService::ensureForOrder($oid, ['prefix' => 'ZT']);
okI($gen['ok'] && !empty($gen['invoice']), 'Invoice generated after payment');
$inv = $gen['invoice'];
$ref = (string) $inv['public_ref'];

// Admin access unchanged (by id / ref without portal gate)
okI(InvoiceService::byId((int) $inv['id']) !== null, 'Admin can load invoice before delivery');
okI(InvoiceService::forCustomer($ref, $cid) !== null, 'Owner still owns invoice record before delivery');

// Customer portal blocked before delivery
okI(!InvoiceService::customerPortalReleased($oid), 'Portal closed while confirmed');
okI(InvoiceService::forCustomerPortal($ref, $cid) === null, 'Customer portal denies PDF/view before delivery');

foreach (['preparing', 'ready', 'out_for_delivery'] as $st) {
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$st, $oid]);
    okI(InvoiceService::forCustomerPortal($ref, $cid) === null, "Portal denied for status $st");
}

// Delivered: portal open
$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([OrderStatus::DELIVERED, $oid]);
$pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?,?,?)')
    ->execute([$oid, OrderStatus::DELIVERED, 'Delivered']);
okI(InvoiceService::customerPortalReleased($oid), 'Portal open when delivered');
okI(InvoiceService::forCustomerPortal($ref, $cid) !== null, 'Customer can access invoice after delivery');

// Email on delivery
$sent = [];
InvoiceEmailService::$transport = static function ($to, $subject, $text, $html, $att) use (&$sent) {
    $sent[] = compact('to', 'subject', 'att');

    return ['ok' => true];
};
$e1 = InvoiceEmailService::sendWhenOrderDelivered($oid);
$e2 = InvoiceEmailService::sendWhenOrderDelivered($oid);
okI($e1['ok'] && !$e2['ok'] && count($sent) === 1, 'Exactly one delivered invoice email');
okI(strpos($sent[0]['subject'], $on) !== false && $sent[0]['att'][0]['mime'] === 'application/pdf', 'Email includes order context and PDF');

// Re-saving delivered status via admin service must not duplicate email
$before = count($sent);
AdminOrderService::updateStatus($oid, OrderStatus::DELIVERED, '');
okI(count($sent) === $before, 'Re-saving delivered status does not send duplicate invoice email');

// SMTP failure does not revert delivery
InvoiceEmailService::$transport = static function () {
    return ['ok' => false, 'error' => 'connection_failed'];
};
$pdo->prepare('DELETE FROM invoice_email_log WHERE invoice_id = ?')->execute([(int) $inv['id']]);
$oid2 = $oid;
$mobile2 = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobile2, 'No mail', '']);
$cid2 = (int) $pdo->lastInsertId();
$on2 = 'INV2-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$on2, 'storefront', $cid2, OrderStatus::DELIVERED, 50, 0, 40, 90, 'paid', $snap]);
$oid2 = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oid2, 'X', '1', 'S', 1, 50, 50]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oid2, 'razorpay', 'gw2', 'pay2', 90, 'INR', 'captured']);
InvoiceService::ensureForOrder($oid2, ['prefix' => 'ZT']);
$failMail = InvoiceEmailService::sendWhenOrderDelivered($oid2);
okI(!$failMail['ok'] && ($failMail['error'] ?? '') === 'no_email', 'No email attempt without valid customer email');
$st = (string) $pdo->query("SELECT status FROM orders WHERE id = $oid2")->fetchColumn();
okI($st === OrderStatus::DELIVERED, 'Delivery status unchanged after email failure');

// Refunded after delivery — access preserved via delivered history (same order as above)
$pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([OrderStatus::PARTIALLY_REFUNDED, $oid]);
okI(InvoiceService::orderDeliveryProven($oid), 'Partially refunded after delivery: still proven');
okI(InvoiceService::forCustomerPortal($ref, $cid) !== null, 'Portal stays open after refund when delivery was proven');

// Refunded after delivery — access preserved via delivered history (fresh order)
$mobile3 = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobile3, 'After del', 'inv3-' . strtolower($tok) . '@example.test']);
$cid3 = (int) $pdo->lastInsertId();
$on3 = 'INV3-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$on3, 'storefront', $cid3, OrderStatus::PARTIALLY_REFUNDED, 80, 0, 40, 120, 'partially_refunded', $snap]);
$oid3 = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oid3, 'Y', '1', 'S2', 1, 80, 80]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oid3, 'razorpay', 'gw3', 'pay3', 120, 'INR', 'captured']);
$pdo->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?,?,?)')
    ->execute([$oid3, OrderStatus::DELIVERED, 'Delivered']);
$r3 = InvoiceService::ensureForOrder($oid3, ['prefix' => 'ZT']);
$ref3 = (string) ($r3['invoice']['public_ref'] ?? '');
okI(InvoiceService::orderDeliveryProven($oid3), 'Partially refunded after delivery: proven via history');
okI($ref3 !== '' && InvoiceService::forCustomerPortal($ref3, $cid3) !== null, 'Refunded-after-delivery: customer portal allowed');

// Refunded-only status with no delivery proof
$mobile4 = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobile4, 'Pre refund', 'inv4-' . strtolower($tok) . '@example.test']);
$cid4 = (int) $pdo->lastInsertId();
$on4 = 'INV4-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$on4, 'storefront', $cid4, OrderStatus::REFUNDED, 50, 0, 40, 90, 'refunded', $snap]);
$oid4 = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oid4, 'Z', '1', 'S3', 1, 50, 50]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oid4, 'razorpay', 'gw4', 'pay4', 90, 'INR', 'captured']);
$r4 = InvoiceService::ensureForOrder($oid4, ['prefix' => 'ZT']);
$ref4 = (string) ($r4['invoice']['public_ref'] ?? '');
okI(!InvoiceService::orderDeliveryProven($oid4), 'Refunded-only order: delivery not proven');
okI($ref4 !== '' && InvoiceService::forCustomerPortal($ref4, $cid4) === null, 'Refunded-before-delivery: no portal access');

// Delivery-table-only completion (order status stays confirmed)
$sentDel = [];
InvoiceEmailService::$transport = static function ($to, $subject, $text, $html, $att) use (&$sentDel) {
    $sentDel[] = compact('to', 'subject');

    return ['ok' => true];
};
AppSettings::set('invoice.auto_email', '1');
AppSettings::resetCache();

$mobileD = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobileD, 'Del row', 'del-' . strtolower($tok) . '@example.test']);
$cidD = (int) $pdo->lastInsertId();
$onD = 'INVD-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$onD, 'storefront', $cidD, 'confirmed', 60, 0, 40, 100, 'paid', $snap]);
$oidD = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oidD, 'A', '1', 'SD', 1, 60, 60]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oidD, 'razorpay', 'gwd', 'payd', 100, 'INR', 'captured']);
InvoiceService::ensureForOrder($oidD, ['prefix' => 'ZT']);
$delId = (int) (DeliveryAssignmentService::createForOrder($oidD) ?? 0);
okI($delId > 0, 'Delivery fixture for invoice email test');
$u1 = DeliveryAssignmentService::updateFromAdmin($delId, [
    'status' => DeliveryStatus::DISPATCHED,
    'internal_remarks' => 'Dispatched for invoice test',
]);
okI(!empty($u1['ok']), 'Delivery dispatched');
$u2 = DeliveryAssignmentService::updateFromAdmin($delId, [
    'status' => DeliveryStatus::DELIVERED,
    'internal_remarks' => 'Marked delivered via delivery module',
    'allow_status_shortcut' => true,
]);
okI(!empty($u2['ok']), 'Delivery row marked delivered');
okI(InvoiceService::orderDeliveryProven($oidD), 'Delivery row proves delivery while order still confirmed');
$stOrd = (string) $pdo->query("SELECT status FROM orders WHERE id = $oidD")->fetchColumn();
okI($stOrd === 'confirmed', 'Order status unchanged when only delivery row completes');
okI(count($sentDel) === 1, 'Delivery-table completion sends invoice email once');
$key = InvoiceEmailService::deliveredEmailAutoKey($oidD);
$logKey = (string) $pdo->query("SELECT auto_key FROM invoice_email_log ORDER BY id DESC LIMIT 1")->fetchColumn();
okI($logKey === $key, 'Delivery workflow uses same idempotency key');

okI(DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'Duplicate delivered event'), 'Repeated delivered delivery update is idempotent');
okI(DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'Duplicate delivered event again'), 'Second duplicate delivery update is idempotent');
okI(count($sentDel) === 1, 'Duplicate delivery delivered events do not resend invoice email');

// Order-status delivered after delivery email — still one send
InvoiceEmailService::trySendWhenOrderDelivered($oidD);
okI(count($sentDel) === 1, 'Order-status path shares idempotency with delivery path');

// Refund without delivery proof must not email
$mobileR = '98765' . random_int(10000, 99999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobileR, 'Ref only', 'ref-' . strtolower($tok) . '@example.test']);
$cidR = (int) $pdo->lastInsertId();
$onR = 'INVR-' . $tok;
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
)->execute([$onR, 'storefront', $cidR, OrderStatus::REFUNDED, 50, 0, 40, 90, 'refunded', $snap]);
$oidR = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
    ->execute([$oidR, 'B', '1', 'SR', 1, 50, 50]);
$pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
    ->execute([$oidR, 'razorpay', 'gwr', 'payr', 90, 'INR', 'captured']);
InvoiceService::ensureForOrder($oidR, ['prefix' => 'ZT']);
$beforeRefund = count($sentDel);
$rTry = InvoiceEmailService::sendWhenOrderDelivered($oidR);
okI(!$rTry['ok'] && ($rTry['error'] ?? '') === 'not_delivered', 'Refunded without delivery proof does not send invoice email');
okI(count($sentDel) === $beforeRefund, 'No transport call for refund-without-delivery');

InvoiceEmailService::$transport = null;
AppSettings::set('invoice.auto_email', '0');
AppSettings::resetCache();

exit($fail > 0 ? 1 : 0);
