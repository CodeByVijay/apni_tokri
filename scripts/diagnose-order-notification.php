<?php

declare(strict_types=1);

/**
 * Read-only queue/SMTP/template diagnostic for one order (CLI).
 * Usage: php7.3 scripts/diagnose-order-notification.php AT-261105-FE25FF
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$orderNumber = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($orderNumber === '' || !Install::isSchemaReady()) {
    fwrite(STDERR, "Usage: php scripts/diagnose-order-notification.php <order_number>\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();

$stmt = $pdo->prepare(
    'SELECT o.id, o.order_number, o.status, o.payment_status, o.customer_id, c.email, c.name
     FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.order_number = ? LIMIT 1'
);
$stmt->execute([$orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    fwrite(STDERR, "Order not found.\n");
    exit(1);
}

echo "Order id={$order['id']} status={$order['status']} payment={$order['payment_status']}\n";
$email = trim((string) ($order['email'] ?? ''));
echo 'Customer email: ' . ($email !== '' ? $email : '(missing)') . "\n";
echo 'Email valid: ' . (Validation::email($email) ? 'yes' : 'no') . "\n";

$tpl = NotificationTemplateRepository::findByKeyAny('order.status.email');
echo 'Template order.status.email: ' . ($tpl ? ('id=' . $tpl['id'] . ' active=' . ($tpl['is_active'] ?? '')) : 'MISSING') . "\n";

$smtpErr = SmtpSettingsService::readinessError();
echo 'SMTP ready: ' . ($smtpErr === null ? 'yes' : ('no (' . $smtpErr . ')')) . "\n";

$oid = (int) $order['id'];
$q = $pdo->prepare(
    "SELECT id, event_type, template_key, status, idempotency_key, attempts, error_code, error_message, created_at, sent_at
     FROM notification_queue
     WHERE payload_json LIKE ? OR idempotency_key LIKE ?
     ORDER BY id ASC"
);
$q->execute(['%' . $orderNumber . '%', 'order.status:order:' . $oid . ':%']);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
echo 'Queue rows: ' . count($rows) . "\n";
foreach ($rows as $r) {
    echo sprintf(
        "  #%d %s %s %s attempts=%s err=%s/%s sent_at=%s\n",
        (int) $r['id'],
        (string) $r['event_type'],
        (string) $r['status'],
        (string) $r['idempotency_key'],
        (string) $r['attempts'],
        (string) ($r['error_code'] ?? ''),
        (string) ($r['error_message'] ?? ''),
        (string) ($r['sent_at'] ?? '')
    );
}

$prepKey = 'order.status:order:' . $oid . ':preparing:email';
echo "Expected preparing idempotency key: {$prepKey}\n";
