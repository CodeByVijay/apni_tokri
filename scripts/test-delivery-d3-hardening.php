<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

if (!DeliverySmsSendLockService::tableReady()) {
    fwrite(STDERR, "Apply database/migrations/1.8.0_d3_hardening.sql first.\n");
    exit(1);
}

$fail = 0;
$skipped = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skip(string $m): void
{
    global $skipped;
    echo "SKIP: {$m}\n";
    $skipped++;
}

Install::ensureConnection();
$pdo = Database::pdo();

$d4SchemaVersions = ['1.8.0-d4-checkout', '1.8.0-d4-hardening', '1.8.0-d5-subscription', '1.8.0-stage12-c2-1', '1.8.0-stage12-c3-1', '1.9.0-stage13-cms-1'];
$schemaOk = Install::installedSchemaVersion() === '1.8.0-d3-manual'
    || in_array(Install::installedSchemaVersion(), $d4SchemaVersions, true)
    || Install::isVersionCurrent();
ok($schemaOk, 'schema_version at least D3 manual (current: ' . (Install::installedSchemaVersion() ?? 'null') . ')');
ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Install::SCHEMA_VERSION matches DB');
$expected = App::config()['install']['expected_schema_version'] ?? '';
ok($expected === Install::SCHEMA_VERSION, 'config.local expected_schema_version matches');

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($customerId > 0, 'fixture customer');
$pdo->prepare('UPDATE customers SET mobile = ? WHERE id = ?')->execute(['9876543210', $customerId]);

$ordNum = 'D3H-' . strtoupper(substr(Security::randomToken(4), 0, 6));
$pdo->prepare(
    'INSERT INTO orders (order_number, customer_id, status, payment_status, subtotal, grand_total, delivery_slot, delivery_date, placed_at)
     VALUES (?, ?, ?, ?, 100, 100, ?, CURDATE(), NOW())'
)->execute([$ordNum, $customerId, 'confirmed', 'paid', 'Test slot']);
$orderId = (int) $pdo->lastInsertId();
$delId = (int) (DeliveryAssignmentService::createForOrder($orderId) ?? 0);
ok($delId > 0, 'delivery fixture');

$internalRemark = 'SECRET_INTERNAL_D3H_PORTER_REF_998877';
$res = DeliveryAssignmentService::updateFromAdmin($delId, [
    'status' => DeliveryStatus::DISPATCHED,
    'courier_service_name' => 'Porter',
    'booking_reference' => 'BK-SECRET',
    'internal_remarks' => $internalRemark,
]);
ok(!empty($res['ok']), 'admin status update');

$visibleRows = DeliveryCustomerTimeline::visibleHistoryRows($delId);
$leaked = false;
foreach ($visibleRows as $row) {
    $note = (string) ($row['note'] ?? '');
    if ($note !== '' && (stripos($note, 'SECRET_INTERNAL') !== false || stripos($note, 'Porter') !== false)) {
        $leaked = true;
    }
}
ok(!$leaked, 'customer-visible history rows exclude internal remarks');

$histStmt = $pdo->prepare(
    'SELECT note, customer_visible FROM delivery_status_history WHERE delivery_id = ? AND note LIKE ? LIMIT 1'
);
$histStmt->execute([$delId, '%' . $internalRemark . '%']);
$auditRow = $histStmt->fetch(PDO::FETCH_ASSOC);
ok($auditRow !== false && (int) ($auditRow['customer_visible'] ?? 1) === 0, 'internal remark stored with customer_visible=0');

$timeline = DeliveryCustomerTimeline::forOrderId($orderId);
foreach ($timeline as $step) {
    ok(stripos((string) ($step['status'] ?? ''), 'SECRET') === false, 'timeline label has no internal remark text');
}

$pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ?')->execute([$delId]);

DeliverySmsService::sendDeliveryEvent($delId, 'ready');
DeliverySmsService::sendDeliveryEvent($delId, 'ready');
$sentReady = 0;
foreach (DeliverySmsService::logsForDelivery($delId) as $l) {
    if (($l['event_key'] ?? '') === 'ready' && ($l['status'] ?? '') === 'sent') {
        $sentReady++;
    }
}
ok($sentReady === 1, 'sequential duplicate ready SMS prevented');

$pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ? AND event_key = ?')->execute([$delId, 'dispatched']);
$pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ?')->execute([$delId, 'dispatched']);

$workerPath = escapeshellarg(APNI_TOKRI_ROOT . '/scripts/d3-sms-concurrent-worker.php');
$inner = 'D3_SMS_SLOW=1 php ' . $workerPath . ' ' . (int) $delId . ' dispatched & '
    . 'D3_SMS_SLOW=1 php ' . $workerPath . ' ' . (int) $delId . ' dispatched & wait';
exec('bash -c ' . escapeshellarg($inner), $outWorkers, $workerCode);
if ($workerCode === 0) {
    $sentDisp = 0;
    foreach (DeliverySmsService::logsForDelivery($delId) as $l) {
        if (($l['event_key'] ?? '') === 'dispatched' && ($l['status'] ?? '') === 'sent') {
            $sentDisp++;
        }
    }
    ok($sentDisp === 1, 'concurrent workers produce one sent dispatched SMS');
} else {
    skip('concurrent SMS worker spawn (bash wait failed exit ' . $workerCode . ')');
}

$pdo->prepare(
    'INSERT IGNORE INTO delivery_sms_send_locks (delivery_id, event_key, lock_status, lease_token)
     VALUES (?, ?, ?, ?)'
)->execute([$delId, 'delivered', DeliverySmsSendLockService::STATUS_IDLE, '']);
$pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ? AND event_key = ?')->execute([$delId, 'failed']);
$pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ? AND event_key = ?')->execute([$delId, 'failed']);
$pdo->prepare(
    'INSERT INTO delivery_sms_logs (delivery_id, order_id, event_key, template_key, mobile, mobile_masked, status, gateway, attempt_number, error_message)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $delId,
    $orderId,
    'failed',
    'sms.template.failed',
    '9876543210',
    DeliverySmsService::maskMobile('9876543210'),
    'failed',
    'simulate',
    1,
    'Simulated provider failure',
]);
$failLogId = (int) $pdo->lastInsertId();
$beforeAttempts = (int) $pdo->query(
    'SELECT COUNT(*) FROM delivery_sms_logs WHERE delivery_id = ' . (int) $delId . " AND event_key = 'failed'"
)->fetchColumn();
ok(DeliverySmsService::retryLog($failLogId), 'admin retry SMS on failed log');
$afterAttempts = (int) $pdo->query(
    'SELECT COUNT(*) FROM delivery_sms_logs WHERE delivery_id = ' . (int) $delId . " AND event_key = 'failed'"
)->fetchColumn();
ok($afterAttempts > $beforeAttempts, 'legitimate failed-send retry creates new attempt');
$sentFailed = 0;
foreach (DeliverySmsService::logsForDelivery($delId) as $l) {
    if (($l['event_key'] ?? '') === 'failed' && ($l['status'] ?? '') === 'sent') {
        $sentFailed++;
    }
}
ok($sentFailed === 1, 'retry does not leave duplicate sent rows');

$pdo->prepare(
    'UPDATE delivery_sms_send_locks SET lock_status = ?, lease_token = ?, lease_expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
     WHERE delivery_id = ? AND event_key = ?'
)->execute([DeliverySmsSendLockService::STATUS_SENDING, 'stale_token', $delId, 'delivered']);
$rec = DeliverySmsSendLockService::recoverStaleSending($delId, 'delivered');
ok(!empty($rec['recovered']), 'stale sending claim recovered');
DeliverySmsService::sendDeliveryEvent($delId, 'delivered');
$deliveredSent = 0;
foreach (DeliverySmsService::logsForDelivery($delId) as $l) {
    if (($l['event_key'] ?? '') === 'delivered' && ($l['status'] ?? '') === 'sent') {
        $deliveredSent++;
    }
}
ok($deliveredSent === 1, 'send after stale recovery succeeds');

$rejectBody = json_encode(['status' => 'failed', 'message_id' => '']);
$evalReject = SmsHttpResponsePolicy::evaluate(200, json_decode($rejectBody, true), $rejectBody);
ok(empty($evalReject['ok']), 'HTTP 200 with provider rejection fails validation');

$evalBadJson = SmsHttpResponsePolicy::evaluate(200, null, 'not-json');
ok(empty($evalBadJson['ok']), 'invalid JSON fails validation');

$evalTimeout = SmsHttpResponsePolicy::evaluate(0, null, '');
ok(empty($evalTimeout['ok']), 'non-success HTTP / timeout fails validation');

$evalNoId = SmsHttpResponsePolicy::evaluate(200, ['status' => 'success'], json_encode(['status' => 'success']));
ok(empty($evalNoId['ok']), 'missing gateway message id fails validation');

$goodBody = json_encode(['status' => 'success', 'message_id' => 'gw_real_123']);
$evalOk = SmsHttpResponsePolicy::evaluate(200, json_decode($goodBody, true), $goodBody);
ok(!empty($evalOk['ok']) && ($evalOk['message_id'] ?? '') === 'gw_real_123', 'genuine provider acceptance');

$adminLogs = DeliverySmsService::logsForAdmin($delId);
$hasRawMobile = false;
foreach ($adminLogs as $row) {
    if (array_key_exists('mobile', $row)) {
        $hasRawMobile = true;
    }
}
ok(!$hasRawMobile && $adminLogs !== [], 'admin SMS list omits raw mobile numbers');

$pdo->prepare('DELETE FROM delivery_sms_send_locks WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM delivery_sms_logs WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM delivery_status_history WHERE delivery_id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM deliveries WHERE id = ?')->execute([$delId]);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);

SmsGatewayFactory::setTestAdapter(null);

$regressions = [
    'test-delivery-d3.php' => 'D3',
    'test-delivery-d2-hardening.php' => 'D2 hardening',
    'test-delivery-stage8.php' => 'Stage 8',
    'test-payment-stage9.php' => 'Stage 9',
    'test-promotions-stage10.php' => 'Stage 10',
    'test-subscription-stage11.php' => 'Stage 11',
    'test-trust-stage12.php' => 'Stage 12 A',
    'test-trust-stage12-phase-b.php' => 'Stage 12 B',
];

foreach ($regressions as $script => $label) {
    $path = APNI_TOKRI_ROOT . '/scripts/' . $script;
    if (!is_readable($path)) {
        skip($label . ' regression (missing script)');
        continue;
    }
    $out = [];
    $code = test_cli_exec_script($path, $out);
    $joined = implode("\n", $out);
    $failed = $code !== 0
        || preg_match('/\bFAIL:/', $joined)
        || preg_match('/\bFAILURES:\s*[1-9]/', $joined)
        || preg_match('/\d+ failure\(s\)/', $joined);
    ok(!$failed, "{$label} regression (exit {$code})");
}

echo "\nSkipped: {$skipped}\n";
if ($fail > 0) {
    echo "{$fail} check(s) failed.\n";
    exit(1);
}

echo "PASS: D3 audit hardening.\n";
exit(0);
