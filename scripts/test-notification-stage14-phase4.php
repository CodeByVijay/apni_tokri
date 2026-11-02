<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/includes/smtp_test_peer.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply migration 1.13.0_stage14_notifications_phase4.sql (expected ' . Install::SCHEMA_VERSION . ").\n");
    exit(1);
}
if (!NotificationQueueService::tableReady() || !NotificationTemplateRepository::tableReady()) {
    fwrite(STDERR, "Notification queue/templates tables missing.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

/** @implements SmsGatewayAdapter */
final class SmsTestOkAdapter implements SmsGatewayAdapter
{
    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        return ['ok' => true, 'message_id' => 'test_n14_msg', 'response' => '{}'];
    }
}

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function n14_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM notification_queue WHERE idempotency_key LIKE 'test_n14_%'");
    $pdo->exec("DELETE FROM notification_templates WHERE template_key LIKE 'test_n14.%'");
    $pdo->exec("DELETE FROM customer_notifications WHERE title LIKE 'test_n14_%'");
    $pdo->exec("DELETE FROM subscription_notification_log WHERE reference_key LIKE 'test_n14_%'");
    SmsGatewayFactory::setTestAdapter(null);
    SmtpMailer::setStreamConnector(null);
    AppSettings::resetCache();
}

n14_cleanup($pdo);

// 1. Queue insertion
$enq = NotificationQueueService::enqueue(
    'test.event',
    'email',
    'customer@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'ORD-1', 'amount' => '10.00', 'customer_name' => 'Test'],
    'test_n14_enqueue_1'
);
ok(!empty($enq['ok']) && !empty($enq['created']), 'Queue insertion');

// 2. Duplicate prevention
$dup = NotificationQueueService::enqueue(
    'test.event',
    'email',
    'customer@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'ORD-1', 'amount' => '10.00', 'customer_name' => 'Test'],
    'test_n14_enqueue_1'
);
ok(!empty($dup['duplicate']), 'Duplicate prevention');

// 3. Unique idempotency key behavior (different channel same key allowed? unique is channel+key - same key different channel)
NotificationQueueService::enqueue(
    'test.event',
    'in_app',
    'customer:1',
    'customer:1',
    'support.ticket_created.in_app',
    ['ticket_number' => 'TK-1'],
    'test_n14_enqueue_1'
);
$cnt = (int) $pdo->query(
    "SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = 'test_n14_enqueue_1'"
)->fetchColumn();
ok($cnt === 2, 'Unique idempotency key per channel');

// 4. Email channel (simulate SMTP accept)
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
    'pay@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'ORD-E1', 'amount' => '99.00', 'customer_name' => 'Pay'],
    'test_n14_email_ok'
);
$emailJobId = (int) ($pdo->query(
    "SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_email_ok' LIMIT 1"
)->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_token = 't', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$emailJobId]);
$emailRun = smtp_test_peer_run_server(
    static function ($server): void {
        smtp_test_peer_success_dialog($server, false, '', '');
    },
    static function () use ($emailJobId): array {
        return NotificationQueueService::processJob($emailJobId);
    }
);
if (($emailRun['error'] ?? '') === 'pcntl_unavailable') {
    ok(true, 'Email channel (skipped — pcntl unavailable)');
} else {
    $stEmail = (string) (Database::pdo()->query(
        "SELECT status FROM notification_queue WHERE idempotency_key = 'test_n14_email_ok' LIMIT 1"
    )->fetchColumn() ?: '');
    ok(!empty($emailRun['ok']) && $stEmail === 'sent', 'Email channel');
}

Database::disconnect();
Install::ensureConnection();
$pdo = Database::pdo();

// 5. SMS channel
AppSettings::set(SmsSettingsService::KEY_PROVIDER, 'simulate');
AppSettings::resetCache();
SmsGatewayFactory::setTestAdapter(new SmsTestOkAdapter());
$pdo->prepare(
    "INSERT INTO notification_templates (template_key, event_type, channel, sms_body, is_active)
     VALUES ('test_n14.sms', 'test.sms', 'sms', 'Hi {{customer_name}}', 1)
     ON DUPLICATE KEY UPDATE sms_body = VALUES(sms_body), is_active = 1"
)->execute();
NotificationQueueService::enqueue(
    'test.sms',
    'sms',
    '9876543210',
    'customer:1',
    'test_n14.sms',
    ['customer_name' => 'SmsUser'],
    'test_n14_sms_1'
);
$smsId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_sms_1' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing',
     lease_token = 's', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$smsId]);
$resSms = NotificationQueueService::processJob($smsId);
$stSms = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$smsId}")->fetchColumn() ?: '');
ok(!empty($resSms['ok']) && $stSms === 'sent', 'SMS channel');

// 6. In-app channel
$beforeInApp = (int) $pdo->query('SELECT COUNT(*) FROM customer_notifications')->fetchColumn();
NotificationQueueService::enqueue(
    'support.ticket_created',
    'in_app',
    'customer:1',
    'customer:1',
    'support.ticket_created.in_app',
    ['ticket_number' => 'TK-INAPP'],
    'test_n14_inapp_1'
);
$inAppId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_inapp_1' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_token = 'i', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$inAppId]);
NotificationQueueService::processJob($inAppId);
$afterInApp = (int) $pdo->query('SELECT COUNT(*) FROM customer_notifications')->fetchColumn();
ok($afterInApp > $beforeInApp, 'In-app channel');

// 7. Template lookup
$tpl = NotificationTemplateRepository::findByKey('payment.success.email');
ok($tpl !== null && ($tpl['channel'] ?? '') === 'email', 'Template lookup');

// 8. HTML variable escaping
$html = NotificationTemplateRenderer::render('<p>{{name}}</p>', ['name' => '<script>alert(1)</script>'], true);
ok(strpos($html, '<script>') === false && strpos($html, '&lt;script') !== false, 'HTML variable escaping');

// 9. Missing template
NotificationQueueService::enqueue(
    'test.missing',
    'email',
    'x@example.test',
    'customer:1',
    'test_n14.no_template',
    ['a' => 'b'],
    'test_n14_missing_tpl'
);
$missId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_missing_tpl' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_token = 'm', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$missId]);
$missRes = NotificationQueueService::processJob($missId);
$missSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$missId}")->fetchColumn() ?: '');
ok(empty($missRes['ok']) && $missSt === 'failed', 'Missing template');

// 10. Malformed payload (non-object JSON decodes safely)
$pdo->prepare(
    "INSERT INTO notification_queue (event_type, channel, recipient, template_key, payload_json, status, idempotency_key, max_attempts)
     VALUES ('test.bad', 'email', 'a@b.test', 'payment.success.email', '123', 'processing', 'test_n14_bad_json', 5)"
)->execute();
$badId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "UPDATE notification_queue SET lease_token = 'b', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$badId]);
$badThrew = false;
try {
    NotificationQueueService::processJob($badId);
} catch (Throwable $e) {
    $badThrew = true;
}
ok(!$badThrew, 'Malformed payload');

// 11. Disabled SMTP
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
NotificationQueueService::enqueue(
    'test.smtp_off',
    'email',
    'off@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'O', 'amount' => '1', 'customer_name' => 'X'],
    'test_n14_smtp_off'
);
$offId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_smtp_off' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', attempts = 0, lease_token = 'o', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$offId]);
NotificationQueueService::processJob($offId);
$offSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$offId}")->fetchColumn() ?: '');
ok($offSt === 'pending', 'Disabled SMTP (retry scheduled, not sent)');

// 12. Disabled SMS
AppSettings::set(SmsSettingsService::KEY_PROVIDER, SmsSettingsService::PROVIDER_HTTP);
AppSettings::set(SmsSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
NotificationQueueService::enqueue(
    'test.sms_off',
    'sms',
    '9876543210',
    'customer:1',
    'test_n14.sms',
    ['customer_name' => 'X'],
    'test_n14_sms_off'
);
$smsOffId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_sms_off' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', attempts = 0, lease_token = 'so', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$smsOffId]);
NotificationQueueService::processJob($smsOffId);
$smsOffSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$smsOffId}")->fetchColumn() ?: '');
ok($smsOffSt === 'pending', 'Disabled SMS (retry scheduled, not sent)');

// 13. Retry scheduling
$nextRetry = (string) ($pdo->query("SELECT next_retry_at FROM notification_queue WHERE id = {$offId}")->fetchColumn() ?: '');
ok($nextRetry !== '', 'Retry scheduling');

// 14. Maximum retry enforcement
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', attempts = 4, max_attempts = 5, template_key = 'payment.success.email',
     payload_json = ?, lease_token = 'mr', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([
    json_encode(['order_number' => 'O', 'amount' => '1', 'customer_name' => 'X'], JSON_UNESCAPED_UNICODE),
    $offId,
]);
AppSettings::set(SmtpSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
NotificationQueueService::processJob($offId);
$maxSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$offId}")->fetchColumn() ?: '');
ok($maxSt === 'failed', 'Maximum retry enforcement');

// 15. Permanent failure handling
$permId = $missId;
$permSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$permId}")->fetchColumn() ?: '');
ok($permSt === 'failed', 'Permanent failure handling');

// 16. Concurrent worker claim
NotificationQueueService::enqueue(
    'test.concurrent',
    'email',
    'c@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'C', 'amount' => '1', 'customer_name' => 'C'],
    'test_n14_concurrent'
);
$batch1 = NotificationQueueService::claimBatch(10);
$batch2 = NotificationQueueService::claimBatch(10);
$concurrentDup = 0;
foreach ($batch2 as $id2) {
    if (in_array($id2, $batch1, true)) {
        $concurrentDup++;
    }
}
ok($concurrentDup === 0, 'Concurrent worker claim');

// 17. Abandoned processing lease recovery
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE),
     lease_token = 'stale' WHERE idempotency_key = 'test_n14_concurrent'"
)->execute();
$rec = NotificationQueueService::recoverStaleProcessing();
$recSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE idempotency_key = 'test_n14_concurrent'")->fetchColumn() ?: '');
ok($rec >= 1 && $recSt === 'pending', 'Abandoned processing lease recovery');

// 18. No raw OTP in queue logs
NotificationQueueService::enqueue(
    'test.otp',
    'email',
    'otp@example.test',
    'customer:1',
    'payment.success.email',
    ['otp' => '123456', 'customer_name' => 'O'],
    'test_n14_otp_payload'
);
$san = NotificationTemplateRenderer::sanitizePayloadForLog(['otp' => '123456', 'name' => 'x']);
ok(($san['otp'] ?? '') === '[redacted]', 'No raw OTP in queue logs (sanitized)');

// 19. No SMTP/SMS credentials in queue
$payloadRow = (string) ($pdo->query(
    "SELECT payload_json FROM notification_queue WHERE idempotency_key = 'test_n14_otp_payload' LIMIT 1"
)->fetchColumn() ?: '');
ok(stripos($payloadRow, 'smtp') === false && stripos($payloadRow, 'api_key') === false, 'No SMTP/SMS credentials in queue');

// 20. Admin permission checks
stage14_p1_clear_session();
ok(!Permission::can('notifications.manage'), 'Admin permission checks (deny guest)');
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
ok(Permission::can('notifications.manage'), 'Admin permission checks (super admin)');
stage14_p1_clear_session();

// 21. CSRF validation
$_SESSION = [];
Csrf::token();
$_POST[Csrf::fieldName()] = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
ok(Csrf::validateRequest(), 'CSRF validation');
unset($_POST[Csrf::fieldName()]);

// 22. Admin retry/cancel actions
NotificationQueueService::enqueue(
    'test.admin',
    'email',
    'a@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'A', 'amount' => '1', 'customer_name' => 'A'],
    'test_n14_admin_cancel'
);
$cancelId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_admin_cancel'")->fetchColumn() ?: 0);
ok(NotificationQueueService::adminCancel($cancelId), 'Admin cancel pending');
$pdo->prepare(
    "UPDATE notification_queue SET status = 'failed' WHERE idempotency_key = 'test_n14_missing_tpl'"
)->execute();
$retryId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_missing_tpl'")->fetchColumn() ?: 0);
ok(NotificationQueueService::adminRetry($retryId), 'Admin retry failed');

// 23. Existing D3 delivery notification dedupe (still uses delivery_sms_logs, not notification_queue)
if (DeliverySmsService::tableReady()) {
    $d3Before = (int) $pdo->query('SELECT COUNT(*) FROM delivery_sms_logs')->fetchColumn();
    DeliverySmsService::sendDeliveryEvent(999999999, 'ready');
    $d3After = (int) $pdo->query('SELECT COUNT(*) FROM delivery_sms_logs')->fetchColumn();
    ok($d3After === $d3Before, 'Existing D3 delivery notification dedupe (no bogus delivery)');
} else {
    ok(true, 'Existing D3 delivery notification dedupe (skipped — table missing)');
}

// 24. Existing subscription notification dedupe
$custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($custId > 0) {
    $first = SubscriptionNotificationDedupeService::notifyOnce(
        $custId,
        'test_n14',
        'test_n14_sub_ref',
        'test_n14_title',
        'test_n14_body'
    );
    $second = SubscriptionNotificationDedupeService::notifyOnce(
        $custId,
        'test_n14',
        'test_n14_sub_ref',
        'test_n14_title',
        'test_n14_body'
    );
    ok($first && !$second, 'Existing subscription notification dedupe');
} else {
    ok(true, 'Existing subscription notification dedupe (skipped — no customer)');
}

// --- Shared cPanel hardening (Stage 14.4) ---

ok(NotificationQueueService::DEFAULT_BATCH === 10, 'Default batch size is 10');
ok(NotificationQueueService::resolveBatchSize(15) === 15, 'Configured batch size is respected');
ok(NotificationQueueService::resolveBatchSize(99) === NotificationQueueService::MAX_BATCH, 'Batch maximum is 20');
ok(
    NotificationQueueService::resolveMaxRuntimeSeconds() === NotificationQueueService::MAX_WORKER_RUNTIME_SECONDS,
    'Worker runtime budget default is 45 seconds'
);

$pastRun = NotificationQueueService::runWorker(10, 45, microtime(true) - 120);
ok($pastRun['claimed'] === 0 && $pastRun['stop_reason'] === 'budget_before_claim', 'Worker stops claiming when time budget is exhausted');

ok(
    NotificationQueueService::recommendedLeaseSeconds() >= NotificationQueueService::maxTransportTimeoutSeconds(),
    'Lease and transport timeout configuration are compatible'
);

$pdo->prepare(
    "INSERT INTO notification_queue (event_type, channel, recipient, template_key, payload_json, status, idempotency_key, max_attempts, lease_token, lease_expires_at)
     VALUES ('test.fence', 'in_app', 'customer:1', 'support.ticket_created.in_app', '{}', 'processing', 'test_n14_fence', 5, 'lease_old', DATE_ADD(NOW(), INTERVAL 1 HOUR))"
)->execute();
$fenceId = (int) $pdo->lastInsertId();
ok(!NotificationQueueService::markSentWithLease($fenceId, 'lease_stale'), 'Stale claim token cannot overwrite a newer claim');
ok(NotificationQueueService::markSentWithLease($fenceId, 'lease_old'), 'Valid claim token completes job');
$cronSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/cron/process_notification_queue.php');
ok(strpos($cronSrc, "PHP_SAPI !== 'cli'") !== false, 'CLI-only guard remains enforced');
ok(!preg_match('/pcntl_/', $cronSrc), 'Worker does not require pcntl');

for ($i = 0; $i < 12; $i++) {
    NotificationQueueService::enqueue(
        'test.budget',
        'in_app',
        'customer:' . ($custId > 0 ? $custId : 1),
        'customer:' . ($custId > 0 ? $custId : 1),
        'support.ticket_created.in_app',
        ['ticket_number' => 'TK-B' . $i],
        'test_n14_budget_' . $i
    );
}
$budgetRun = NotificationQueueService::runWorker(5, 45);
ok($budgetRun['claimed'] <= 5 && $budgetRun['claimed'] >= 1, 'Worker runtime budget caps batch processing');

$dupH = NotificationQueueService::enqueue(
    'test.event',
    'email',
    'h@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'H1', 'amount' => '1', 'customer_name' => 'H'],
    'test_n14_idem_hardening'
);
$dupH2 = NotificationQueueService::enqueue(
    'test.event',
    'email',
    'h@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'H1', 'amount' => '1', 'customer_name' => 'H'],
    'test_n14_idem_hardening'
);
ok(!empty($dupH['created']) && !empty($dupH2['duplicate']), 'Existing idempotency behavior remains unchanged');

AppSettings::set(SmtpSettingsService::KEY_ENABLED, '0');
AppSettings::resetCache();
NotificationQueueService::enqueue(
    'test.smtp_unchanged',
    'email',
    'u@example.test',
    'customer:1',
    'payment.success.email',
    ['order_number' => 'U', 'amount' => '1', 'customer_name' => 'U'],
    'test_n14_smtp_unchanged'
);
$unchId = (int) ($pdo->query("SELECT id FROM notification_queue WHERE idempotency_key = 'test_n14_smtp_unchanged' LIMIT 1")->fetchColumn() ?: 0);
$pdo->prepare(
    "UPDATE notification_queue SET status = 'processing', lease_token = 'lu', lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?"
)->execute([$unchId]);
$unchRes = NotificationQueueService::processJob($unchId);
$unchSt = (string) ($pdo->query("SELECT status FROM notification_queue WHERE id = {$unchId}")->fetchColumn() ?: '');
ok(empty($unchRes['ok']) && $unchSt === 'pending', 'Existing SMTP disabled behavior remains unchanged');

n14_cleanup($pdo);

echo $fail === 0 ? "All notification stage 14.4 checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
