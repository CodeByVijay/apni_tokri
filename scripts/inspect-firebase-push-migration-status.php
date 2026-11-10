<?php

declare(strict_types=1);

/**
 * Read-only Firebase migration inspection (uses configured application database).
 * CLI: php scripts/tmp-firebase-migration-inspect.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

try {
    Install::ensureConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "ABORT: " . $e->getMessage() . "\n");
    exit(2);
}

$pdo = Database::pdo();
$appDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

$schemaVer = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1")->fetchColumn();
echo "application_database: {$appDb}\n";
echo "schema_version: {$schemaVer}\n";

$tables = ['firebase_projects', 'device_push_registrations', 'push_notification_templates', 'push_notification_logs'];
foreach ($tables as $t) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$t]);
    $exists = (int) $stmt->fetchColumn() > 0;
    echo 'table_' . $t . ': ' . ($exists ? 'present' : 'MISSING') . "\n";
}

$stmt = $pdo->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'device_push_registrations'
       AND COLUMN_NAME = 'target_type'"
);
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo 'column_target_type: ' . $col['COLUMN_TYPE'] . ' default=' . ($col['COLUMN_DEFAULT'] ?? 'NULL') . "\n";
} else {
    echo "column_target_type: MISSING\n";
}

if (PushSchema::tableReady('push_notification_templates')) {
    foreach (['subscription.paused.push', 'subscription.resumed.push'] as $key) {
        $st = $pdo->prepare('SELECT template_key, event_type, is_active FROM push_notification_templates WHERE template_key = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "template_{$key}: active=" . (int) $row['is_active'] . " event=" . $row['event_type'] . "\n";
        } else {
            echo "template_{$key}: MISSING\n";
        }
    }
} else {
    echo "templates_table: MISSING (skip pause/resume template probe)\n";
}

$perm = (int) $pdo->query("SELECT COUNT(*) FROM admin_permissions WHERE slug = 'firebase.manage'")->fetchColumn();
echo 'permission_firebase_manage: ' . ($perm === 1 ? 'present' : 'MISSING') . "\n";

// Infer migration apply state from schema_version + objects (no migration runner table).
$m39 = [
    '1.39.0' => str_contains($schemaVer, '1.39.0') || PushSchema::tableReady('firebase_projects'),
    '1.39.1' => str_contains($schemaVer, '1.39.1') || ($col !== false),
    '1.39.2' => str_contains($schemaVer, '1.39.2'),
];
foreach ($m39 as $ver => $likely) {
    echo "migration_{$ver}_applied: " . ($likely ? 'yes' : 'unknown_or_no') . "\n";
}
