<?php

declare(strict_types=1);

/**
 * Push notification schema + idempotency (disposable DB).
 *
 * Usage: php scripts/run-in-disposable-db.php scripts/test-firebase-push-schema.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$ok = 0;
$fail = 0;

function okPush(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

okPush(PushSchema::ready(), 'push tables exist after migration');

Install::ensureConnection();
$pdo = Database::pdo();
$pdo->exec("INSERT INTO firebase_projects
    (name, firebase_project_id, status, web_api_key, web_app_id, vapid_public_key, service_account_client_email)
    VALUES ('Test', 'test-push-proj', 'active', 'key', 'app', 'vapid', 'test@test.iam.gserviceaccount.com')");
$id = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE firebase_projects SET is_active_global = 1 WHERE id = ?')->execute([$id]);

$custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($custId <= 0) {
    fwrite(STDERR, "FAIL no seeded customer in disposable DB\n");
    exit(1);
}
PushTokenService::register('web', 'c8mSchemaTestFid0123456789ab', 'granted', $custId, null, 'fid');

AppSettings::set(PushSettings::KEY_DRY_RUN, '1');
AppSettings::set(PushSettings::KEY_ENABLED, '1');
$result = PushNotificationService::sendToCustomer(
    $custId,
    'order.confirmed.push',
    ['customer_name' => 'Test', 'order_number' => 'T1', 'order_total' => '10', 'order_id' => '1'],
    'push:test:schema:1',
    'test'
);
okPush(!empty($result['dry_run']), 'dry-run send path');
$result2 = PushNotificationService::sendToCustomer(
    $custId,
    'order.confirmed.push',
    ['customer_name' => 'Test', 'order_number' => 'T1', 'order_total' => '10', 'order_id' => '1'],
    'push:test:schema:1',
    'test'
);
okPush(!empty($result2['duplicate']), 'idempotency prevents duplicate log');

$click = PushUrlValidator::resolveClickUrl('/order-details.php?id=1', []);
okPush($click !== null && str_contains($click, 'order-details.php'), 'click URL validation');

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
