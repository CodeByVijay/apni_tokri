<?php

declare(strict_types=1);

/**
 * Admin order status → customer email (mocked SMTP).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-order-status-email-admin.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';

if (!Install::isSchemaReady() || !NotificationQueueService::tableReady()) {
    fwrite(STDERR, "Schema/queue not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okS(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function applyMigration130(PDO $pdo): void
{
    if ((int) $pdo->query("SELECT COUNT(*) FROM notification_templates WHERE template_key = 'order.status.email'")->fetchColumn() > 0) {
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

applyMigration130($pdo);

$tag = bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    '97' . substr($tag, 0, 8),
    'Status Email Test',
    'status+' . $tag . '@example.test',
]);
$custId = (int) $pdo->lastInsertId();
$orderNumber = 'AT-STAT-' . strtoupper($tag);
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, 100, 0, 0, 100, ?, ?, NOW())'
)->execute([$orderNumber, $custId, OrderStatus::CONFIRMED, 'paid', 'morning']);
$orderId = (int) $pdo->lastInsertId();

$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
Permission::clearCache();

SmtpMailer::setStreamConnector(static function (): mixed {
    return false;
});
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '1');
AppSettings::set(SmtpSettingsService::KEY_HOST, '127.0.0.1');
AppSettings::set(SmtpSettingsService::KEY_PORT, '2525');
AppSettings::set(SmtpSettingsService::KEY_ENCRYPTION, SmtpSettingsService::ENCRYPTION_NONE);
AppSettings::set(SmtpSettingsService::KEY_AUTH_ENABLED, '0');
AppSettings::set(SmtpSettingsService::KEY_FROM_EMAIL, 'noreply@apnitokri.test');
AppSettings::resetCache();

$key = 'order.status:order:' . $orderId . ':' . OrderStatus::PREPARING . ':email';
$pdo->exec("DELETE FROM notification_queue WHERE idempotency_key = " . $pdo->quote($key));

$upd = AdminOrderService::updateStatus($orderId, OrderStatus::PREPARING, 'Automated preparing transition test');
okS(!empty($upd['ok']), 'Admin updateStatus to preparing succeeds');

$st = $pdo->prepare('SELECT id, status, error_code, error_message, attempts FROM notification_queue WHERE idempotency_key = ? LIMIT 1');
$st->execute([$key]);
$job = $st->fetch(PDO::FETCH_ASSOC);
okS(is_array($job), 'Queue row created for preparing status email');
okS(is_array($job) && (string) ($job['status'] ?? '') !== '', 'Queue row has status');

$dup = AdminOrderService::updateStatus($orderId, OrderStatus::PREPARING, '');
okS(empty($dup['ok']), 'Duplicate preparing status rejected');
$st->execute([$key]);
okS((int) $pdo->query('SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = ' . $pdo->quote($key))->fetchColumn() === 1, 'Duplicate admin save does not enqueue second email');

$orderSt = (string) $pdo->query('SELECT status FROM orders WHERE id = ' . (int) $orderId)->fetchColumn();
okS($orderSt === OrderStatus::PREPARING, 'Order status committed even if SMTP fails');

NotificationTemplateRepository::ensureFromDefaults('order.status.email');
$pdo->prepare('UPDATE notification_templates SET is_active = 0 WHERE template_key = ?')->execute(['order.status.email']);
$pdo->exec("DELETE FROM notification_queue WHERE idempotency_key = 'order.status:order:{$orderId}:" . OrderStatus::READY . ":email'");
$upd2 = AdminOrderService::updateStatus($orderId, OrderStatus::READY, 'Move to ready inactive template test');
okS(!empty($upd2['ok']), 'Order status update ok when template inactive');
okS(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = 'order.status:order:{$orderId}:ready:email'"
    )->fetchColumn() === 0,
    'Inactive template prevents enqueue'
);

$pdo->prepare('UPDATE notification_templates SET is_active = 1 WHERE template_key = ?')->execute(['order.status.email']);

$pdo->prepare('DELETE FROM notification_queue WHERE idempotency_key LIKE ?')->execute(['order.status:order:' . $orderId . ':%']);
$pdo->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
$pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$custId]);
SmtpMailer::setStreamConnector(null);

echo $fail === 0 ? "All order status admin email tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
