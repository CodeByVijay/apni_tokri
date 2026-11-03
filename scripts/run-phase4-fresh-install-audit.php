<?php

declare(strict_types=1);

/**
 * Phase 4 fresh-install audit on disposable DB only (no prod/dev data mutation).
 * CLI: php scripts/run-phase4-fresh-install-audit.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$dbCfg = App::config()['database'];
$host = (string) ($dbCfg['host'] ?? '127.0.0.1');
$port = (int) ($dbCfg['port'] ?? 3306);
$user = (string) ($dbCfg['user'] ?? 'root');
$pass = (string) ($dbCfg['pass'] ?? $dbCfg['password'] ?? '');
$freshDb = 'apni_tokri_phase4_fresh_' . date('YmdHis');

function mysql_sh(array $db, string $extra = ''): string
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }

    return $cmd . ($extra !== '' ? ' ' . $extra : '');
}

$results = ['generated_at' => date('c'), 'fresh_database' => $freshDb, 'checks' => []];

function check(array &$results, string $id, bool $ok, string $detail = ''): void
{
    $results['checks'][] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
}

passthru(mysql_sh($dbCfg, '-e ' . escapeshellarg(
    "CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
)), $code);
check($results, 'create_database', $code === 0);

passthru(mysql_sh($dbCfg, escapeshellarg($freshDb)) . ' < ' . escapeshellarg($sqlFile), $code);
check($results, 'import_production_sql', $code === 0);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $freshDb),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$ver = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();
check($results, 'schema_version', $ver === Install::SCHEMA_VERSION, $ver);

$origConfig = App::config();
App::setConfig(array_replace_recursive($origConfig, ['database' => array_merge($origConfig['database'] ?? [], ['name' => $freshDb])]));
Database::disconnect();

try {
    $report = Install::statusReport();
    check($results, 'verify_install_schema_ready', ($report['schema_ready'] ?? false) === true);
    check($results, 'verify_install_schema_current', ($report['schema_current'] ?? false) === true);
    check($results, 'verify_install_missing_tables', ($report['missing_tables'] ?? []) === [], implode(',', (array) ($report['missing_tables'] ?? [])));

    $hash = (string) $pdo->query('SELECT password_hash FROM admin_users WHERE id = 1')->fetchColumn();
    check($results, 'default_admin_hash_bcrypt', str_starts_with($hash, '$2y$'));
    check($results, 'default_admin_password_known', password_verify('ChangeMe123!', $hash), 'Documented default password still valid — must change before go-live');

    $permCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_permissions')->fetchColumn();
    $rolePerm = (int) $pdo->query('SELECT COUNT(*) FROM admin_role_permissions')->fetchColumn();
    check($results, 'rbac_seed', $permCount >= 20 && $rolePerm >= 40, "permissions={$permCount} role_perms={$rolePerm}");

    $activePlans = (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1')->fetchColumn();
    check($results, 'subscription_plans_visible', $activePlans >= 3, "active_plans={$activePlans}");

    $products = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    check($results, 'products_empty', $products === 0, "products={$products}");

    withProductionEnv(function () use (&$results, $products): void {
        CatalogueGateway::reset();
        check($results, 'storefront_not_operational_no_catalogue', !StorefrontReadiness::isStorefrontOperational());
        $blockers = StorefrontReadiness::productionBlockers();
        check($results, 'production_blockers_nonempty', $blockers !== [], count($blockers) . ' blockers');
        check(
            $results,
            'production_blocks_simulate_payment',
            str_contains(implode(' ', $blockers), 'Simulated') || str_contains(implode(' ', $blockers), 'Payment'),
            implode('; ', $blockers)
        );
        check($results, 'simulate_endpoint_blocked', !AppEnvironment::allowsSimulatedPaymentEndpoint());
    });

    $cmsDraft = (int) $pdo->query("SELECT COUNT(*) FROM cms_pages WHERE status <> 'draft'")->fetchColumn();
    check($results, 'cms_pages_all_draft', $cmsDraft === 0, "non_draft_pages={$cmsDraft}");

    $activeSeedCoupons = (int) $pdo->query("SELECT COUNT(*) FROM coupons WHERE code IN ('WELCOME10','SAVE50') AND is_active = 1")->fetchColumn();
    check($results, 'seed_coupons_inactive', $activeSeedCoupons === 0, "active_seed_coupons={$activeSeedCoupons}");

    withProductionEnv(function () use (&$results): void {
        try {
            InvoiceProductionSecurity::ensureProductionStorageConfigured();
            check($results, 'invoice_storage_production', true, 'paths configured outside docroot');
        } catch (Throwable $e) {
            check($results, 'invoice_storage_production', false, $e->getMessage());
        }
    });

    // Login validates hash path; attempt() updates last_login_at on disposable DB only.
    check($results, 'admin_login_default_credentials', AdminAuth::attempt('admin@apnitokri.local', 'ChangeMe123!'));
    check($results, 'admin_force_password_change_enforcement', AdminAuth::requiresMandatoryPasswordChange(), 'Seed admin must require password change before admin access');
} finally {
    App::setConfig($origConfig);
    Database::disconnect();
}

passthru(mysql_sh($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`")), $code);
check($results, 'drop_disposable_database', $code === 0);

$fail = 0;
foreach ($results['checks'] as $c) {
    if (($c['ok'] ?? false) !== true && !in_array($c['id'], ['invoice_storage_production'], true)) {
        $fail++;
    }
}
// invoice_storage expected finding until hosting paths configured

$results['pass'] = $fail === 0;
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($fail === 0 ? 0 : 1);

/** @param callable(): void $fn */
function withProductionEnv(callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, ['app' => ['env' => 'production', 'debug' => false]]));
    CatalogueGateway::reset();
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        CatalogueGateway::reset();
    }
}
