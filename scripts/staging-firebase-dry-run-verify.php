<?php

declare(strict_types=1);

/**
 * Safe dry-run push verification on configured DB (no FCM HTTP).
 * Aborts if dry_run is not effectively enabled.
 *
 * CLI: php scripts/staging-firebase-dry-run-verify.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

if (!Install::hasLocalConfig()) {
    fwrite(STDERR, "ABORT: config.local.php required.\n");
    exit(2);
}

$role = strtolower(trim((string) (App::config()['database']['role'] ?? '')));
if (in_array($role, ['production', 'prod'], true)) {
    fwrite(STDERR, "ABORT: database.role production — refusing staging dry-run probe.\n");
    exit(2);
}

if (!PushNotificationService::dryRunOnly()) {
    fwrite(STDERR, "ABORT: push dry_run is not active — enable dry-run before running this probe.\n");
    exit(2);
}

if (!PushSchema::ready()) {
    fwrite(STDERR, "ABORT: push schema not ready.\n");
    exit(2);
}

Install::ensureConnection();
$pdo = Database::pdo();

$active = FirebaseProjectManager::activeProject(false);
if ($active === null) {
    fwrite(STDERR, "SKIP: no active Firebase project — configure in admin first.\n");
    exit(3);
}

AppSettings::set(PushSettings::KEY_ENABLED, '1');
AppSettings::set(PushSettings::KEY_DRY_RUN, '1');

$custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($custId <= 0) {
    fwrite(STDERR, "ABORT: no customer row for dry-run send.\n");
    exit(2);
}

$probeFid = 'stagingDryRunFid' . bin2hex(random_bytes(4));
PushTokenService::register('web', $probeFid, 'granted', $custId, null, 'fid');

$key = 'push:staging:dryrun:' . bin2hex(random_bytes(4));
$result = PushNotificationService::sendToCustomer(
    $custId,
    'order.confirmed.push',
    [
        'customer_name' => 'Staging Probe',
        'order_number' => 'STG-DRY',
        'order_total' => '0',
        'order_id' => '0',
    ],
    $key,
    'staging.dry_run.probe',
    ['order_id' => '0']
);

if (empty($result['dry_run'])) {
    fwrite(STDERR, 'FAIL: send did not report dry_run. Result: ' . json_encode($result) . "\n");
    exit(1);
}

$st = $pdo->prepare('SELECT status, failure_category FROM push_notification_logs WHERE idempotency_key = ? LIMIT 1');
$st->execute([$key]);
$log = $st->fetch(PDO::FETCH_ASSOC);
if (!$log || (string) ($log['status'] ?? '') !== 'dry_run') {
    fwrite(STDERR, 'FAIL: push_notification_logs missing dry_run row for idempotency key.' . "\n");
    exit(1);
}

// Revoke probe registration so it does not linger as a "device".
$row = $pdo->prepare(
    "SELECT id FROM device_push_registrations WHERE fcm_token = ? AND platform = 'web' ORDER BY id DESC LIMIT 1"
);
$row->execute([$probeFid]);
$regId = (int) $row->fetchColumn();
if ($regId > 0) {
    PushTokenService::adminRevokeById($regId);
}

fwrite(STDOUT, "OK  dry_run send and log verified (idempotency ends with ..." . substr($key, -8) . ")\n");
exit(0);
