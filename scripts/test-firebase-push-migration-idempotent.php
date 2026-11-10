<?php

declare(strict_types=1);

/**
 * Migration 1.39.1 must re-run cleanly after 1.39.0 (disposable DB only).
 *
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-push-migration-idempotent.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$pdo = Database::pdo();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$path = APNI_TOKRI_ROOT . '/database/migrations/1.39.1_push_registration_target_type.sql';

$result = disposable_apply_migration_file($pdo, $dbName, $path);
if (empty($result['ok'])) {
    fwrite(STDERR, 'FAIL re-apply 1.39.1: ' . ($result['message'] ?? '') . "\n");
    exit(1);
}

$stmt = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_push_registrations' AND COLUMN_NAME = 'target_type'"
);
$exists = (int) $stmt->fetchColumn() === 1;

fwrite(STDOUT, ($exists ? 'OK' : 'FAIL') . "  target_type column present after idempotent 1.39.1\n");
exit($exists ? 0 : 1);
