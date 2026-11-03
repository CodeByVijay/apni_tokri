<?php

declare(strict_types=1);

/**
 * Phase 4.1 — production blocker remediation (disposable DB + config patches only).
 * CLI: php scripts/test-phase4-1-remediation.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';

$fail41 = 0;
$pass41 = 0;

function ok41(bool $cond, string $msg): void
{
    global $fail41, $pass41;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if ($cond) {
        $pass41++;
    } else {
        $fail41++;
    }
}

/** @param array<string, mixed> $patch */
function withConfig41(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        CatalogueGateway::reset();
    }
}

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$dbCfg = App::config()['database'];
$host = (string) ($dbCfg['host'] ?? '127.0.0.1');
$port = (int) ($dbCfg['port'] ?? 3306);
$user = (string) ($dbCfg['user'] ?? 'root');
$pass = (string) ($dbCfg['pass'] ?? $dbCfg['password'] ?? '');
$freshDb = 'apni_tokri_phase41_' . date('YmdHis');

function mysql41(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' ' . $extra, $code);

    return (int) $code;
}

if (!is_readable($sqlFile)) {
    fwrite(STDERR, "Missing production SQL — run scripts/build-apni-tokri-production-sql.php first.\n");
    exit(1);
}

$code = mysql41($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"));
ok41($code === 0, 'Create disposable database');
$code = mysql41($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile));
ok41($code === 0, 'Import production SQL into disposable database');

$origConfig = App::config();
App::setConfig(array_replace_recursive($origConfig, ['database' => array_merge($origConfig['database'] ?? [], ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $pdo = Database::pdo();

    $inactiveCoupons = (int) $pdo->query("SELECT COUNT(*) FROM coupons WHERE code IN ('WELCOME10','SAVE50') AND is_active = 0")->fetchColumn();
    ok41($inactiveCoupons === 2, 'Seed coupons WELCOME10 and SAVE50 inactive on fresh install');

    $cmsDraft = (int) $pdo->query("SELECT COUNT(*) FROM cms_pages WHERE status <> 'draft'")->fetchColumn();
    ok41($cmsDraft === 0, 'All seeded CMS pages remain draft');

    $blocked = CmsInstallSafety::publishBlockedReason([
        'slug' => 'privacy-policy',
        'body_html' => '<p>Content placeholder. Replace with lawyer-reviewed privacy policy before publishing.</p>',
    ]);
    ok41($blocked !== null, 'CMS publish blocked for placeholder seed content');

    $_SESSION = [];
    ok41(AdminAuth::attempt(Install::SEED_ADMIN_EMAIL, 'ChangeMe123!'), 'Default admin login on disposable DB');
    ok41(AdminAuth::requiresMandatoryPasswordChange(), 'Seed admin requires mandatory password change');
    $change = AdminAuth::changePasswordMandatory('ChangeMe123!', 'StrongPass41x', 'StrongPass41x');
    ok41($change['ok'], 'Mandatory password change succeeds with strong password');
    ok41(!AdminAuth::requiresMandatoryPasswordChange(), 'Password change clears mandatory flag');
    $hashAfter = (string) $pdo->query('SELECT password_hash FROM admin_users WHERE id = 1')->fetchColumn();
    ok41(!hash_equals(Install::SEED_ADMIN_PASSWORD_HASH, $hashAfter), 'Password hash updated in database');
    $authSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/auth.php');
    ok41(str_contains($authSrc, 'changePasswordMandatory') && str_contains($authSrc, 'AppSession::regenerate'), 'Mandatory password change regenerates session (code path)');
    $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = 1')->execute([Install::SEED_ADMIN_PASSWORD_HASH]);
    ok41(AdminAuth::check(), 'Admin session valid after password change');

    AdminAuth::attempt(Install::SEED_ADMIN_EMAIL, 'ChangeMe123!');
    $rejectDefault = AdminAuth::changePasswordMandatory('ChangeMe123!', 'ChangeMe123!', 'ChangeMe123!');
    ok41(empty($rejectDefault['ok']), 'Cannot set password back to factory default');

    AdminAuth::logout();
    ok41(!AdminAuth::check(), 'Logout clears admin session');

    withConfig41(['app' => ['env' => 'production', 'debug' => false]], static function (): void {
        ok41(!AppEnvironment::allowsSimulatedPaymentEndpoint(), 'Production blocks simulate endpoint');
        $gate = PaymentGatewayConfig::assertPaymentExecutionAllowed('simulate');
        ok41(empty($gate['ok']), 'Production rejects simulate payment execution');
        $prod = AppEnvironment::assertProductionPaymentsReady();
        ok41(empty($prod['ok']), 'Production rejects seed simulate gateway + test mode');
        $init = PaymentGatewayConfig::assertPaymentExecutionAllowed('razorpay');
        ok41(empty($init['ok']), 'Production rejects payments without live gateway config');
    });

    withConfig41(['app' => ['env' => 'local', 'debug' => true]], static function (): void {
        ok41(PaymentGatewayConfig::assertPaymentExecutionAllowed('simulate')['ok'], 'Local allows simulate execution');
        ok41(AppEnvironment::allowsSimulatedPaymentEndpoint(), 'Local allows simulate endpoint');
    });

    withConfig41(['app' => ['env' => 'staging', 'debug' => false]], static function (): void {
        ok41(!AppEnvironment::isProduction(), 'Staging is not production');
        ok41(PaymentGatewayConfig::assertPaymentExecutionAllowed('simulate')['ok'], 'Staging allows simulate execution');
    });

    if (Install::isSchemaReady()) {
        withConfig41(['app' => ['env' => 'production', 'debug' => false]], static function (): void {
            $before = (int) Database::pdo()->query('SELECT COUNT(*) FROM payments')->fetchColumn();
            $orderId = (int) Database::pdo()->query('SELECT id FROM orders ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($orderId <= 0) {
                echo "SKIP: no orders for initiateForOrder production test\n";

                return;
            }
            $orderNo = (string) Database::pdo()->query('SELECT order_number FROM orders WHERE id = ' . $orderId)->fetchColumn();
            $init = PaymentService::initiateForOrder($orderId, $orderNo, 1.0, '9000000001');
            ok41(empty($init['ok']), 'Production blocks PaymentService::initiateForOrder without live config');
            $after = (int) Database::pdo()->query('SELECT COUNT(*) FROM payments')->fetchColumn();
            ok41($after === $before, 'Production initiateForOrder does not insert simulate payment rows');
        });
    }

    $protected = AdminGuard::protectedAdminScripts();
    ok41(in_array(APNI_TOKRI_ROOT . '/admin/change-password.php', $protected, true), 'change-password.php is an authenticated admin route');
} finally {
    App::setConfig($origConfig);
    Database::disconnect();
    mysql41($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo "\nSummary: pass={$pass41} fail={$fail41}\n";
echo ($fail41 === 0 ? 'PASS' : 'FAIL') . "\n";
exit($fail41 === 0 ? 0 : 1);
