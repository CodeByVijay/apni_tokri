<?php

declare(strict_types=1);

/**
 * Read-only staging/admin readiness (no secrets in output).
 * CLI: php scripts/inspect-firebase-staging-readiness.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

Install::ensureConnection();
$pdo = Database::pdo();

$env = (string) (App::config()['app']['env'] ?? '');
$baseUrl = (string) (App::config()['app']['base_url'] ?? '');
$dbRole = (string) (App::config()['database']['role'] ?? '');
$dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn());

echo "app_env: {$env}\n";
echo "database_role: " . ($dbRole !== '' ? $dbRole : '(unset)') . "\n";
echo "database_name: {$dbName}\n";
echo "base_url_config: {$baseUrl}\n";
echo "push_enabled: " . trim((string) (AppSettings::get(PushSettings::KEY_ENABLED, '') ?? '')) . "\n";
echo "push_dry_run: " . trim((string) (AppSettings::get(PushSettings::KEY_DRY_RUN, '') ?? '')) . "\n";
echo "push_dry_run_effective: " . (PushNotificationService::dryRunOnly() ? 'yes' : 'no') . "\n";

$active = FirebaseProjectManager::activeProject(false);
if ($active === null) {
    echo "active_firebase_project: none\n";
} else {
    echo "active_firebase_project_id: " . (int) $active['id'] . "\n";
    echo "active_firebase_project_name: " . (string) $active['name'] . "\n";
    echo "active_firebase_project_slug: " . (string) $active['firebase_project_id'] . "\n";
    echo "web_configured: " . (trim((string) ($active['web_app_id'] ?? '')) !== '' && trim((string) ($active['web_api_key'] ?? '')) !== '' ? 'yes' : 'no') . "\n";
    echo "vapid_configured: " . (trim((string) ($active['vapid_public_key'] ?? '')) !== '' ? 'yes' : 'no') . "\n";
    echo "android_configured: " . (trim((string) ($active['android_app_id'] ?? '')) !== '' ? 'yes' : 'no') . "\n";
    echo "service_account_stored: " . (trim((string) ($active['service_account_client_email'] ?? '')) !== '' ? 'yes' : 'no') . "\n";
    echo "last_credential_test: " . (string) ($active['last_test_status'] ?? 'unknown') . "\n";
}

$projectCount = (int) $pdo->query('SELECT COUNT(*) FROM firebase_projects')->fetchColumn();
$deviceCount = (int) $pdo->query("SELECT COUNT(*) FROM device_push_registrations WHERE status = 'active'")->fetchColumn();
$logDry = (int) $pdo->query("SELECT COUNT(*) FROM push_notification_logs WHERE status = 'dry_run'")->fetchColumn();
echo "firebase_project_count: {$projectCount}\n";
echo "active_device_registrations: {$deviceCount}\n";
echo "push_logs_dry_run_count: {$logDry}\n";
