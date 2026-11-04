<?php

declare(strict_types=1);

/**
 * QA helper: start an admin web session for browser/curl testing (local only).
 * Does not change passwords or zone data. Refuses production env.
 *
 * Usage: php7.3 scripts/qa-delivery-admin-browser-session.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (AppEnvironment::isProduction()) {
    fwrite(STDERR, "Refused: production environment.\n");
    exit(1);
}

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$row = $pdo->query(
    AdminLoginSecurity::authEpochColumnReady()
        ? 'SELECT id, email, name, auth_epoch FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1'
        : 'SELECT id, email, name FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "No active admin user.\n");
    exit(1);
}

$config = App::config();
AppSession::start($config['session'] ?? []);
$_SESSION['admin_user_id'] = (int) $row['id'];
$_SESSION['admin_user_email'] = (string) $row['email'];
$_SESSION['admin_user_name'] = (string) ($row['name'] ?? 'QA Admin');
$_SESSION['admin_auth_epoch'] = (int) ($row['auth_epoch'] ?? 1);
$sid = session_id();
session_write_close();

$name = (string) (($config['session']['name'] ?? '') ?: 'APNI_TOKRI_SESSID');
echo json_encode([
    'cookie_name' => $name,
    'session_id' => $sid,
    'admin_email' => $row['email'],
], JSON_UNESCAPED_UNICODE) . "\n";
