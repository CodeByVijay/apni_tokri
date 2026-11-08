<?php

declare(strict_types=1);

/**
 * Customer order/payment/refund emails (mocked SMTP; no live gateway or mail).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-payment-order-email-notifications.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/smtp_test_peer.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!NotificationQueueService::tableReady() || !NotificationTemplateRepository::tableReady()) {
    fwrite(STDERR, "Notification tables missing.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okE(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function applyMigration130(PDO $pdo): void
{
    $chk = $pdo->query(
        "SELECT COUNT(*) FROM notification_templates WHERE template_key = 'order.placed.email'"
    )->fetchColumn();
    if ((int) $chk > 0) {
        return;
    }
    $sql = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.30.0_customer_order_email_notifications.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $chunk) {
        if ($chunk === '' || stripos($chunk, 'SET NAMES') === 0) {
            continue;
        }
        $pdo->exec($chunk);
    }
}

function applyMigration131EmailLog(PDO $pdo): void
{
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_email_logs'"
    )->fetchColumn();
    if ($n > 0) {
        return;
    }
    $sql = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.31.0_order_confirmation_email_log.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $chunk) {
        if ($chunk === '' || stripos($chunk, 'SET NAMES') === 0) {
            continue;
        }
        $pdo->exec($chunk);
    }
}

function queueIdForKey(PDO $pdo, string $key): int
{
    $st = $pdo->prepare('SELECT id FROM notification_queue WHERE idempotency_key = ? LIMIT 1');
    $st->execute([$key]);

    return (int) ($st->fetchColumn() ?: 0);
}

function cleanupEmailTests(PDO $pdo, string $tag): void
{
    $pdo->exec("DELETE FROM notification_queue WHERE idempotency_key LIKE 'test_email_{$tag}%' OR idempotency_key LIKE 'payment.%' OR idempotency_key LIKE 'order.%' OR idempotency_key LIKE 'refund.%'");
    SmtpMailer::setStreamConnector(null);
}

applyMigration130($pdo);
applyMigration131EmailLog($pdo);
okE(
    (int) $pdo->query("SELECT COUNT(*) FROM notification_templates WHERE template_key = 'order.placed.email'")->fetchColumn() > 0,
    'Migration 1.30 templates present'
);
okE(DeliveryOrderEmailService::tableReady(), 'Migration 1.31 delivery_email_logs present');

$tag = bin2hex(random_bytes(3));
cleanupEmailTests($pdo, $tag);

$variantId = (int) ($pdo->query('SELECT id FROM product_variants ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
okE($variantId > 0, 'Catalogue variant for order fixture');

$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    '98' . substr($tag, 0, 8),
    'Email Test',
    'email+' . $tag . '@example.test',
]);
$custId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)'
)->execute([$custId, 'Home', 'Line 1', 'Indore', 'MP', '452001']);
$addrId = (int) $pdo->lastInsertId();

$orderNumber = 'AT-EMAIL-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total, delivery_fee,
     grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, ?, 100, 0, 10, 110, ?, ?, NOW())'
)->execute([$orderNumber, $custId, $addrId, 'pending_payment', 'pending', 'morning']);
$orderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
     VALUES (?, ?, ?, ?, 1, 100, 100)'
)->execute([$orderId, $variantId, 'Test item', '1 kg']);
$idemp1 = 'pay_' . $orderNumber . '_1';
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key)
     VALUES (?, ?, ?, 110, ?, ?, ?)'
)->execute([$orderId, 'cashfree', 'cf_' . $tag, 'INR', 'pending', $idemp1]);

// Order placed
NotificationDispatcher::orderPlacedPendingPayment($orderId);
$placedKey = 'order.placed:order:' . $orderId . ':email';
okE(queueIdForKey($pdo, $placedKey) > 0, 'Order placed email enqueued');
NotificationDispatcher::orderPlacedPendingPayment($orderId);
$stPlaced = $pdo->prepare('SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = ?');
$stPlaced->execute([$placedKey]);
okE((int) $stPlaced->fetchColumn() === 1, 'Duplicate order placed prevented');

// Payment success (Cashfree)
$pdo->prepare('UPDATE payments SET status = ?, gateway_payment_id = ?, captured_at = NOW() WHERE order_id = ?')
    ->execute(['captured', 'cfpay_' . $tag, $orderId]);
$pdo->prepare('UPDATE orders SET payment_status = ?, status = ? WHERE id = ?')->execute(['paid', 'confirmed', $orderId]);
okE(OrderEmailContext::isOrderPaidAndCaptured($orderId), 'Paid + captured before confirmation email');
NotificationDispatcher::orderPaymentSuccess($orderId);
$successKey = 'payment.success:order:' . $orderId . ':email';
okE(queueIdForKey($pdo, $successKey) > 0, 'Cashfree payment success email enqueued');
$row = $pdo->prepare('SELECT payload_json FROM notification_queue WHERE idempotency_key = ?');
$row->execute([$successKey]);
$payload = json_decode((string) $row->fetchColumn(), true);
okE(is_array($payload) && ($payload['gateway_name'] ?? '') === 'Cashfree', 'Success payload includes Cashfree gateway name');
okE(is_array($payload) && str_contains((string) ($payload['order_items_html'] ?? ''), 'Test item'), 'Success payload includes order line items');
okE(is_array($payload) && ($payload['track_order_url'] ?? '') !== '', 'Success payload includes track order URL');
NotificationDispatcher::orderPaymentSuccess($orderId);
$stPlaced->execute([$successKey]);
okE((int) $stPlaced->fetchColumn() === 1, 'Duplicate payment success webhook prevented');
$stEmailLog = $pdo->prepare(
    "SELECT COUNT(*) FROM delivery_email_logs WHERE order_id = ? AND event_key = 'order_confirmed'"
);
$stEmailLog->execute([$orderId]);
okE((int) $stEmailLog->fetchColumn() >= 1, 'Confirmation attempt logged for delivery admin');
NotificationDispatcher::orderPaymentSuccess($orderId);
$stEmailLog->execute([$orderId]);
okE((int) $stEmailLog->fetchColumn() === 1, 'Duplicate webhook does not duplicate delivery email log');

// Razorpay payload on separate order
$orderNumber2 = 'AT-RZP-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total, delivery_fee,
     grand_total, payment_status, delivery_slot) VALUES (?, ?, ?, ?, 50, 0, 0, 50, ?, ?)'
)->execute([$orderNumber2, $custId, $addrId, 'confirmed', 'paid', 'morning']);
$orderId2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, idempotency_key, captured_at)
     VALUES (?, ?, ?, ?, 50, ?, ?, ?, NOW())'
)->execute([$orderId2, 'razorpay', 'order_rzp_' . $tag, 'pay_rzp_' . $tag, 'INR', 'captured', 'pay_' . $orderNumber2]);
NotificationDispatcher::orderPaymentSuccess($orderId2);
$row->execute(['payment.success:order:' . $orderId2 . ':email']);
$payloadRzp = json_decode((string) $row->fetchColumn(), true);
okE(is_array($payloadRzp) && ($payloadRzp['gateway_name'] ?? '') === 'Razorpay', 'Razorpay payment success payload');

// Payment failure only when order marked failed (no open attempts)
$idempFail = 'pay_' . $orderNumber . '_fail';
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, 110, ?, ?, ?)'
)->execute([$orderId, 'cashfree', 'INR', 'pending', $idempFail]);
PaymentService::markPaymentFailed($orderNumber, $idempFail, 'Test hard failure');
$failKey = 'payment.failed:order:' . $orderNumber . ':email';
okE(queueIdForKey($pdo, $failKey) > 0, 'Payment failed email when order marked failed');

$orderNumber3 = 'AT-RETRY-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, 40, 0, 0, 40, ?, ?)'
)->execute([$orderNumber3, $custId, 'pending_payment', 'pending', 'morning']);
$orderId3 = (int) $pdo->lastInsertId();
$idempA = 'pay_' . $orderNumber3 . '_a';
$idempB = 'pay_' . $orderNumber3 . '_b';
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, 40, ?, ?, ?)'
)->execute([$orderId3, 'cashfree', 'INR', 'failed', $idempA]);
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, 40, ?, ?, ?)'
)->execute([$orderId3, 'cashfree', 'INR', 'pending', $idempB]);
PaymentService::markPaymentFailed($orderNumber3, $idempA, 'Earlier attempt failed');
$failKey3 = 'payment.failed:order:' . $orderNumber3 . ':email';
okE(queueIdForKey($pdo, $failKey3) === 0, 'Attempt failure with open retry does not send failed email');

// Order status transition + duplicate prevention
NotificationDispatcher::orderStatusChanged($orderId, 'confirmed', 'preparing');
$statusKey = 'order.status:order:' . $orderId . ':preparing:email';
okE(queueIdForKey($pdo, $statusKey) > 0, 'Order status email enqueued');
NotificationDispatcher::orderStatusChanged($orderId, 'confirmed', 'preparing');
$stPlaced->execute([$statusKey]);
okE((int) $stPlaced->fetchColumn() === 1, 'Duplicate order status prevented');
NotificationDispatcher::orderStatusChanged($orderId, 'preparing', 'preparing');
$stPlaced->execute([$statusKey]);
okE((int) $stPlaced->fetchColumn() === 1, 'Same status transition sends nothing new');

// Refund initiated / completed
$pdo->prepare(
    'INSERT INTO refund_requests (request_number, customer_id, order_id, reason_code, status, approved_amount_inr)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute(['RF-' . strtoupper($tag), $custId, $orderId, 'quality', RefundStatus::REQUESTED, 50.00]);
$refundId = (int) $pdo->lastInsertId();
NotificationDispatcher::refundStatusUpdated($refundId, RefundStatus::REFUND_INITIATED);
okE(queueIdForKey($pdo, 'refund.initiated:' . $refundId . ':email') > 0, 'Refund initiated email');
NotificationDispatcher::refundStatusUpdated($refundId, RefundStatus::REFUNDED);
okE(queueIdForKey($pdo, 'refund.completed:' . $refundId . ':email') > 0, 'Refund completed email');

// Paid order, customer has no email — skip confirmation (order still valid)
$noEmailNo = 'AT-NOEM-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, ?, 30, 30, ?, ?)'
)->execute([$noEmailNo, $custId, $addrId, 'confirmed', 'paid', 'morning']);
$noEmailOrderId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, gateway_payment_id, captured_at, idempotency_key)
     VALUES (?, ?, 30, ?, ?, ?, NOW(), ?)'
)->execute([$noEmailOrderId, 'cashfree', 'INR', 'captured', 'noem_' . $tag, 'pay_' . $noEmailNo]);
$pdo->prepare('UPDATE customers SET email = NULL WHERE id = ?')->execute([$custId]);
NotificationDispatcher::orderPaymentSuccess($noEmailOrderId);
$noEmailKey = 'payment.success:order:' . $noEmailOrderId . ':email';
okE(queueIdForKey($pdo, $noEmailKey) === 0, 'No confirmation email when customer has no email');
$stSkip = $pdo->prepare(
    "SELECT status FROM delivery_email_logs WHERE order_id = ? AND event_key = 'order_confirmed' LIMIT 1"
);
$stSkip->execute([$noEmailOrderId]);
okE((string) ($stSkip->fetchColumn() ?: '') === 'skipped', 'No-email confirmation logged as skipped');
$pdo->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute(['email+' . $tag . '@example.test', $custId]);

// Failed payment — no confirmation email
$failedPayNo = 'AT-FAIL-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, ?, 25, 25, ?, ?)'
)->execute([$failedPayNo, $custId, $addrId, 'payment_failed', 'failed', 'morning']);
$failedPayId = (int) $pdo->lastInsertId();
NotificationDispatcher::orderPaymentSuccess($failedPayId);
okE(queueIdForKey($pdo, 'payment.success:order:' . $failedPayId . ':email') === 0, 'No confirmation email when payment not paid');

// Pending payment — no confirmation email
$pendingNo = 'AT-PEND-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, grand_total, payment_status, delivery_slot)
     VALUES (?, ?, ?, ?, 20, 20, ?, ?)'
)->execute([$pendingNo, $custId, $addrId, 'pending_payment', 'pending', 'morning']);
$pendId = (int) $pdo->lastInsertId();
NotificationDispatcher::orderPaymentSuccess($pendId);
okE(queueIdForKey($pdo, 'payment.success:order:' . $pendId . ':email') === 0, 'No confirmation email when payment not captured/paid');
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$pendId]);

// Missing email skipped
$pdo->prepare('UPDATE customers SET email = NULL WHERE id = ?')->execute([$custId]);
NotificationDispatcher::orderPlacedPendingPayment($orderId);
$stPlaced->execute([$placedKey]);
okE((int) $stPlaced->fetchColumn() === 1, 'No new placed email when customer email missing');

// Branded HTML wrapper + escape
$rendered = NotificationTemplateRenderer::renderForChannel(
    [
        'channel' => 'email',
        'subject' => 'Test {{customer_name}}',
        'body_html' => '<p>Hi {{customer_name}}</p>',
        'body_text' => 'Hi {{customer_name}}',
    ],
    ['customer_name' => 'Test<script>alert(1)</script>']
);
okE(strpos((string) ($rendered['html'] ?? ''), '#2d7048') !== false, 'Branded layout applied');
okE(strpos((string) ($rendered['html'] ?? ''), '<script') === false, 'HTML escaped in template body');

// SMTP failure does not throw from dispatcher (queue retains job for retry)
$pdo->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute(['smtp+' . $tag . '@example.test', $custId]);
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmtpSettingsService::KEY_HOST, '127.0.0.1');
AppSettings::set(SmtpSettingsService::KEY_PORT, '2525');
AppSettings::set(SmtpSettingsService::KEY_ENCRYPTION, SmtpSettingsService::ENCRYPTION_NONE);
AppSettings::set(SmtpSettingsService::KEY_AUTH_ENABLED, '0');
AppSettings::set(SmtpSettingsService::KEY_FROM_EMAIL, 'noreply@apnitokri.test');
AppSettings::resetCache();
NotificationQueueService::enqueue(
    'payment.success',
    'email',
    'smtp+' . $tag . '@example.test',
    'customer:' . $custId,
    'payment.success.email',
    ['order_number' => 'X', 'amount' => '1.00', 'customer_name' => 'T', 'gateway_name' => 'Cashfree', 'payment_reference' => 'r', 'order_url' => 'https://example.test/o', 'payment_retry_url' => 'https://example.test/p'],
    'test_email_' . $tag . '_smtp'
);
$smtpJobId = queueIdForKey($pdo, 'test_email_' . $tag . '_smtp');
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_token = 't', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$smtpJobId]);
SmtpMailer::setStreamConnector(static function (): mixed {
    return false;
});
$proc = NotificationQueueService::processJob($smtpJobId);
okE(empty($proc['ok']), 'SMTP failure returns retryable send failure');
$stJob = $pdo->prepare('SELECT status FROM notification_queue WHERE id = ?');
$stJob->execute([$smtpJobId]);
$jobStatus = (string) $stJob->fetchColumn();
okE($jobStatus === 'pending' || $jobStatus === 'failed', 'Email job not marked sent after SMTP failure');

$pdo->prepare('DELETE FROM delivery_email_logs WHERE order_id IN (?, ?, ?, ?, ?)')->execute(
    [$orderId, $orderId2, $orderId3, $noEmailOrderId, $failedPayId]
);
cleanupEmailTests($pdo, $tag);
$pdo->prepare('DELETE FROM refund_requests WHERE id = ?')->execute([$refundId]);
$pdo->prepare('DELETE FROM payments WHERE order_id IN (?, ?, ?, ?, ?)')->execute(
    [$orderId, $orderId2, $orderId3, $noEmailOrderId, $failedPayId]
);
$pdo->prepare('DELETE FROM order_items WHERE order_id IN (?, ?, ?)')->execute([$orderId, $orderId2, $orderId3]);
$pdo->prepare('DELETE FROM orders WHERE id IN (?, ?, ?, ?)')->execute(
    [$orderId, $orderId2, $orderId3, $noEmailOrderId, $failedPayId]
);
$pdo->prepare('DELETE FROM customer_addresses WHERE id = ?')->execute([$addrId]);
$pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$custId]);

echo $fail === 0 ? "All payment/order email notification tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
