<?php

declare(strict_types=1);

/**
 * Customer 30-day remember-login regression tests.
 *
 * Static (no DB): php scripts/test-customer-remember-login.php
 * Integration:   php scripts/run-in-disposable-db.php scripts/test-customer-remember-login.php --migrate-remember
 *
 * Integration requires APNI_TOKRI_DISPOSABLE_DB=1 (set by the disposable runner).
 * Exercises restore under CLI via APNI_TOKRI_REMEMBER_INTEGRATION_RESTORE on apni_tokri_tmp_* DBs only.
 * HTTP subprocess: test-customer-remember-http-restore-child.php (session start + maybeRestoreFromCookie).
 * Concurrent subprocess: test-customer-remember-restore-concurrent-child.php when proc_open is available.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

/**
 * @param array<string, mixed> $patch
 */
function with_test_config(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    CatalogueGateway::reset();
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        CatalogueGateway::reset();
    }
}

function okRemember(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        $fail++;
    }
}

function remember_cookie_fixture(int $customerId): ?string
{
    if (!CustomerRememberAuth::tablesReady()) {
        return null;
    }
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = CustomerRememberAuth::hashValidator($validator);
    Database::pdo()->prepare(
        'INSERT INTO customer_remember_tokens (customer_id, selector, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    )->execute([$customerId, $selector, $hash]);

    return $selector . '.' . $validator;
}

$verifyOtp = (string) file_get_contents(APNI_TOKRI_ROOT . '/verify-otp.php');
okRemember(str_contains($verifyOtp, 'remember_login'), 'verify-otp posts remember_login checkbox');
okRemember(str_contains($verifyOtp, 'Stay signed in on this device for 30 days'), 'approved checkbox label present');
okRemember(
    !preg_match('/name="remember_login"[^>]*checked/i', $verifyOtp),
    'remember checkbox is not checked by default'
);

$configSession = (array) (App::config()['session'] ?? []);
okRemember(((int) ($configSession['lifetime'] ?? 0)) === 7200, 'PHP session lifetime unchanged at 7200 in config');
okRemember(CustomerRememberAuth::COOKIE_NAME === 'VT_REMEMBER', 'remember cookie name VT_REMEMBER');

$configExample = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/config.example.php');
okRemember(str_contains($configExample, "'lifetime' => 7200"), 'config.example.php session lifetime still 7200');

$rememberSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/auth/customer_remember_auth.php');
okRemember(str_contains($rememberSrc, 'isAdminRequest()'), 'admin request guard present');
okRemember(str_contains($rememberSrc, 'expires_at > NOW()'), 'SQL-side expiry validation');
$loginPos = strpos($rememberSrc, 'CustomerAuth::loginById');
$rotatePos = strpos($rememberSrc, 'rotateTokenAfterSuccessfulRestore');
okRemember($loginPos !== false && $rotatePos !== false && $loginPos < $rotatePos, 'login occurs before token rotation');
okRemember(
    !preg_match('/gotLock\s*!==\s*1[\s\S]{0,120}clearCookie/s', $rememberSrc),
    'lock timeout does not clear remember cookie'
);
okRemember(str_contains($rememberSrc, 'DELETE FROM customer_remember_tokens WHERE id = ?'), 'orphan token cleanup on failed issue');
okRemember(str_contains($rememberSrc, 'integrationRestorePermittedInCli'), 'CLI restore gated to disposable integration env');
okRemember(str_contains($rememberSrc, 'last_used_at = NOW()'), 'rotation updates last_used_at in SQL');

$savedUri = $_SERVER['REQUEST_URI'] ?? '';
with_test_config(['app' => ['url_path' => '']], static function (): void {
    $_SERVER['REQUEST_URI'] = '/admin/login.php';
    okRemember(CustomerRememberAuth::isAdminRequest(), 'root install: /admin path is admin request');
    $_SERVER['REQUEST_URI'] = '/index.php';
    okRemember(!CustomerRememberAuth::isAdminRequest(), 'root install: storefront is not admin');
});
with_test_config(['app' => ['url_path' => 'Apni_Tokri']], static function (): void {
    $_SERVER['REQUEST_URI'] = '/Apni_Tokri/admin/dashboard.php';
    okRemember(CustomerRememberAuth::isAdminRequest(), 'subdirectory install: admin under url_path');
    $_SERVER['REQUEST_URI'] = '/Apni_Tokri/cart.php';
    okRemember(!CustomerRememberAuth::isAdminRequest(), 'subdirectory install: storefront not admin');
    okRemember(CustomerRememberAuth::cookiePath() === '/Apni_Tokri', 'subdirectory cookie path matches url_base');
});
with_test_config(['app' => ['url_path' => '']], static function (): void {
    okRemember(CustomerRememberAuth::cookiePath() === '/', 'root cookie path is /');
});
$_SERVER['REQUEST_URI'] = $savedUri;

$integration = CustomerRememberAuth::tablesReady();

if (!$integration) {
    echo "SKIP: customer_remember_tokens table not present — integration tests omitted.\n";
    echo "      Run: php scripts/run-in-disposable-db.php scripts/test-customer-remember-login.php\n";
    echo "      (apply migration 1.38.0 in disposable DB — see test runner env APPLY_REMEMBER_MIGRATION).\n";
} elseif (getenv('APNI_TOKRI_DISPOSABLE_DB') !== '1') {
    echo "SKIP: integration tests require scripts/run-in-disposable-db.php (disposable DB only).\n";
} else {
    require_once __DIR__ . '/lib/test_script_safety.php';
    require_once __DIR__ . '/includes/customer_remember_test_helpers.php';
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    remember_integration_enable();

    Install::ensureConnection();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM customer_remember_tokens');
    $pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
        ->execute(['9999900001', 'Remember Test', 'remember1@test.local']);
    $cust1 = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
        ->execute(['9999900002', 'Remember Two', 'remember2@test.local']);
    $cust2 = (int) $pdo->lastInsertId();

    unset($_SESSION['customer_id'], $_COOKIE[CustomerRememberAuth::COOKIE_NAME]);
    CustomerAuth::logout();
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = remember_cookie_fixture($cust1);

    $savedUriInt = $_SERVER['REQUEST_URI'] ?? '';
    $_SERVER['REQUEST_URI'] = (url_base() !== '' ? url_base() : '') . '/admin/login.php';
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'admin URL does not restore customer_id into shared session');
    $_SERVER['REQUEST_URI'] = (url_base() !== '' ? url_base() : '') . '/index.php';
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'storefront request restores customer after admin skip');
    $_SERVER['REQUEST_URI'] = $savedUriInt;

    unset($_SESSION['customer_id']);
    CustomerAuth::logout();
    $restoreCookie = remember_cookie_fixture($cust1);
    $preRotationCookie = $restoreCookie;
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $restoreCookie;
    $_SERVER['REQUEST_URI'] = (url_base() !== '' ? url_base() : '') . '/cart.php';
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'valid remember token restores customer_id');

    $parsed = CustomerRememberAuth::parseCookieValue($_COOKIE[CustomerRememberAuth::COOKIE_NAME] ?? '');
    okRemember($parsed !== null, 'remember restore rotated cookie still parseable');
    if ($parsed !== null) {
        $row = remember_token_row_by_selector($pdo, $parsed['selector']);
        okRemember(
            Security::hashEquals($row['token_hash'], CustomerRememberAuth::hashValidator($parsed['validator'])),
            'token rotated after successful restore'
        );
        okRemember($row['last_used_at'] !== null, 'last_used_at set after successful restore and rotation');
        okRemember($row['expires_ok'], 'sliding expiry refreshed on restore (~30 days)');

        unset($_SESSION['customer_id']);
        CustomerAuth::logout();
        $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $preRotationCookie;
        CustomerRememberAuth::maybeRestoreFromCookie();
        okRemember(CustomerAuth::id() === null, 'pre-rotation validator no longer authenticates after rotation');

        $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $parsed['selector'] . '.' . str_repeat('b', 64);
        CustomerRememberAuth::maybeRestoreFromCookie();
        okRemember(CustomerAuth::id() === null, 'stale validator after rotation does not restore');
    }

    unset($_SESSION['customer_id']);
    CustomerAuth::logout();
    $headersCookie = remember_cookie_fixture($cust1);
    $headersSel = explode('.', $headersCookie, 2)[0];
    $beforeHeaders = remember_token_row_by_selector($pdo, $headersSel);
    unset($_SESSION['customer_id']);
    CustomerAuth::logout();
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $headersCookie;
    echo 'x';
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'headers_sent: customer session still restored');
    $afterHeaders = remember_token_row_by_selector($pdo, $headersSel);
    okRemember($afterHeaders['last_used_at'] === null, 'headers_sent: last_used_at not updated when rotation skipped');
    okRemember(
        Security::hashEquals($beforeHeaders['token_hash'], $afterHeaders['token_hash']),
        'headers_sent: token_hash unchanged when rotation skipped'
    );

    unset($_SESSION['customer_id']);
    CustomerAuth::logout();
    CustomerRememberAuth::integrationTestForceCookieSendFailure(true);
    $rollbackCookie = remember_cookie_fixture($cust1);
    $rollbackSel = explode('.', $rollbackCookie, 2)[0];
    $hashBeforeRotate = remember_token_row_by_selector($pdo, $rollbackSel)['token_hash'];
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $rollbackCookie;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'cookie send failure: session restored');
    $hashAfterFailedCookie = remember_token_row_by_selector($pdo, $rollbackSel)['token_hash'];
    okRemember(
        Security::hashEquals($hashBeforeRotate, $hashAfterFailedCookie),
        'cookie send failure: DB token_hash rolled back after failed setcookie'
    );
    CustomerRememberAuth::integrationTestForceCookieSendFailure(null);

    unset($_SESSION['customer_id']);
    CustomerAuth::logout();
    CustomerRememberAuth::integrationTestForceRotateDbError(true);
    $dbErrCookie = remember_cookie_fixture($cust1);
    $dbErrSel = explode('.', $dbErrCookie, 2)[0];
    $hashBeforeDbErr = remember_token_row_by_selector($pdo, $dbErrSel)['token_hash'];
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $dbErrCookie;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'forced DB rotation error: session still restored');
    okRemember(
        remember_token_row_by_selector($pdo, $dbErrSel)['last_used_at'] === null,
        'forced DB rotation error: last_used_at not updated'
    );
    okRemember(
        Security::hashEquals($hashBeforeDbErr, remember_token_row_by_selector($pdo, $dbErrSel)['token_hash']),
        'forced DB rotation error: token_hash unchanged'
    );
    CustomerRememberAuth::integrationTestForceRotateDbError(false);

    $beforeIssueCount = (int) $pdo->query('SELECT COUNT(*) FROM customer_remember_tokens WHERE customer_id = ' . (int) $cust2)->fetchColumn();
    CustomerRememberAuth::integrationTestForceCookieSendFailure(true);
    $issued = CustomerRememberAuth::issueForCustomer($cust2);
    okRemember(!$issued, 'issueForCustomer fails when cookie cannot be sent');
    $afterIssueCount = (int) $pdo->query('SELECT COUNT(*) FROM customer_remember_tokens WHERE customer_id = ' . (int) $cust2)->fetchColumn();
    okRemember($afterIssueCount === $beforeIssueCount, 'issueForCustomer removes orphan row when cookie send fails');
    CustomerRememberAuth::integrationTestForceCookieSendFailure(null);

    $httpChild = __DIR__ . '/test-customer-remember-http-restore-child.php';
    if (!function_exists('proc_open')) {
        echo "SKIP: proc_open unavailable — HTTP remember restore subprocess not executed\n";
    } else {
        unset($_SESSION['customer_id']);
        CustomerAuth::logout();
        $httpCookie = remember_cookie_fixture($cust1);
        $httpResult = remember_http_restore_subprocess($httpChild, remember_integration_base_env(), $httpCookie);
        okRemember($httpResult['ok'], 'HTTP subprocess restore completed successfully');
        okRemember($httpResult['restored'], 'HTTP subprocess restored customer');
        okRemember($httpResult['last_used_at_set'], 'HTTP subprocess set last_used_at');
        okRemember($httpResult['hash_rotated'], 'HTTP subprocess rotated token hash');
    }

    $concurrentChild = __DIR__ . '/test-customer-remember-restore-concurrent-child.php';
    if (!function_exists('proc_open')) {
        echo "SKIP: proc_open unavailable — concurrent remember restore not executed\n";
    } else {
        unset($_SESSION['customer_id']);
        CustomerAuth::logout();
        $parCookie = remember_cookie_fixture($cust1);
        $parSel = explode('.', $parCookie, 2)[0];
        $cookieFile = remember_store_cookie_fixture_file($parCookie);
        $barrier = tempnam(sys_get_temp_dir(), 'atrembar');
        if ($barrier !== false) {
            touch($barrier);
        }
        $outA = tempnam(sys_get_temp_dir(), 'atremca');
        $outB = tempnam(sys_get_temp_dir(), 'atremcb');
        $baseEnv = remember_integration_base_env();
        $baseEnv['APNI_TOKRI_REMEMBER_INTEGRATION_RESTORE'] = '1';
        $childEnv = [
            'AT_REMEMBER_CONCURRENT_CHILD' => '1',
            'AT_REMEMBER_COOKIE_FILE' => $cookieFile,
            'AT_REMEMBER_BARRIER' => $barrier !== false ? $barrier : '',
        ];
        $envA = array_merge($baseEnv, $childEnv, ['AT_REMEMBER_CONCURRENT_OUT' => $outA !== false ? $outA : '']);
        $envB = array_merge($baseEnv, $childEnv, ['AT_REMEMBER_CONCURRENT_OUT' => $outB !== false ? $outB : '']);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $procA = proc_open([PHP_BINARY, $concurrentChild], $descriptors, $pipesA, null, $envA);
        $procB = proc_open([PHP_BINARY, $concurrentChild], $descriptors, $pipesB, null, $envB);
        usleep(200_000);
        if ($barrier !== false) {
            @unlink($barrier);
        }
        if (is_resource($procA)) {
            fclose($pipesA[0]);
            stream_get_contents($pipesA[1]);
            fclose($pipesA[1]);
            stream_get_contents($pipesA[2]);
            fclose($pipesA[2]);
            proc_close($procA);
        }
        if (is_resource($procB)) {
            fclose($pipesB[0]);
            stream_get_contents($pipesB[1]);
            fclose($pipesB[1]);
            stream_get_contents($pipesB[2]);
            fclose($pipesB[2]);
            proc_close($procB);
        }
        $payA = is_string($outA) ? json_decode((string) file_get_contents($outA), true) : null;
        $payB = is_string($outB) ? json_decode((string) file_get_contents($outB), true) : null;
        @unlink($cookieFile);
        @unlink($outA);
        @unlink($outB);
        $restoredCount = (is_array($payA) && !empty($payA['restored']) ? 1 : 0)
            + (is_array($payB) && !empty($payB['restored']) ? 1 : 0);
        okRemember($restoredCount >= 1, 'concurrent restore: at least one request authenticated');
        okRemember(
            is_array($payA) && is_array($payB) && !empty($payA['cookie_parseable']) && !empty($payB['cookie_parseable']),
            'concurrent restore: both subprocesses left parseable cookie state'
        );
        $parRow = remember_token_row_by_selector($pdo, $parSel);
        okRemember($parRow['last_used_at'] !== null, 'concurrent restore: last_used_at set after winning rotation');
        okRemember($parRow['revoked_at'] === null, 'concurrent restore: token row still active');
    }

    unset($_SESSION['customer_id']);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = remember_cookie_fixture($cust1);
    $pdo->prepare('UPDATE customer_remember_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE customer_id = ?')
        ->execute([$cust1]);
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'expired token does not restore login');

    unset($_SESSION['customer_id']);
    $goodCookie = remember_cookie_fixture($cust1);
    [$goodSel] = explode('.', $goodCookie, 2);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $goodSel . '.' . str_repeat('a', 64);
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'tampered validator rejected');
    okRemember(isset($_COOKIE[CustomerRememberAuth::COOKIE_NAME]), 'wrong validator leaves cookie for retry (no definitive clear)');

    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = 'cccccccccccccccccccccccc.' . str_repeat('d', 64);
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'unknown selector rejected');

    unset($_SESSION['customer_id']);
    $cookie1 = remember_cookie_fixture($cust1);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookie1;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'restore succeeds before logout');
    CustomerService::logout();
    okRemember(CustomerAuth::id() === null, 'logout clears customer session');
    $sel = explode('.', $cookie1, 2)[0];
    $stmt = $pdo->prepare('SELECT revoked_at FROM customer_remember_tokens WHERE selector = ?');
    $stmt->execute([$sel]);
    okRemember($stmt->fetchColumn() !== null, 'logout revokes current device remember token');
    unset($_SESSION['customer_id']);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookie1;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'revoked token does not restore');

    $cookieDev2 = remember_cookie_fixture($cust1);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieDev2;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust1, 'second device token still valid after first-device revoke');
    CustomerService::logout();

    $cookieOther = remember_cookie_fixture($cust2);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieOther;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust2, 'independent second customer device token works');

    CustomerRememberAuth::revokeAllForCustomer($cust1);
    unset($_SESSION['customer_id']);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieDev2;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'revokeAllForCustomer invalidates customer tokens');

    $pdo->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$cust2]);
    unset($_SESSION['customer_id']);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieOther;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === null, 'inactive customer cannot restore via remember token');

    $_SESSION['admin_user_id'] = 1;
    $_SESSION['admin_auth_epoch'] = 1;
    unset($_SESSION['customer_id']);
    $cookieAdmin = remember_cookie_fixture($cust2);
    $pdo->prepare('UPDATE customers SET is_active = 1 WHERE id = ?')->execute([$cust2]);
    $_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieAdmin;
    CustomerRememberAuth::maybeRestoreFromCookie();
    okRemember(CustomerAuth::id() === $cust2, 'remember restore with admin session keys set does not block customer restore');
    okRemember(isset($_SESSION['admin_user_id']) && (int) $_SESSION['admin_user_id'] === 1, 'admin session keys unchanged after customer remember restore');

    okRemember(CustomerRememberAuth::issueForCustomer($cust2), 'issueForCustomer succeeds when table ready');
    $tokenCount = (int) $pdo->query('SELECT COUNT(*) FROM customer_remember_tokens WHERE customer_id = ' . (int) $cust2)->fetchColumn();
    okRemember($tokenCount >= 2, 'multiple independent tokens allowed per customer');

    CustomerAuth::logout();
    unset($_SESSION['admin_user_id'], $_SESSION['admin_auth_epoch']);
    $pdo->exec('DELETE FROM customer_remember_tokens');
    $pdo->exec('DELETE FROM customers WHERE id IN (' . (int) $cust1 . ',' . (int) $cust2 . ')');
}

if ($integration) {
    okRemember(CustomerRememberAuth::enabled(), 'enabled() true under persisted storefront when table exists');
}
with_test_config(
    ['app' => ['env' => 'local', 'demo_commerce' => true]],
    static function (): void {
        if (!CustomerRememberAuth::tablesReady()) {
            okRemember(!CustomerRememberAuth::enabled(), 'remember disabled when storefront not persisted (no table)');
            return;
        }
        okRemember(
            !CustomerRememberAuth::issueForCustomer(1) || AppEnvironment::usePersistedStorefront(),
            'issueForCustomer only when persisted storefront'
        );
    }
);

echo $fail === 0 ? "All customer remember-login checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
