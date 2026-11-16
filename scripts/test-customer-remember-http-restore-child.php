<?php

declare(strict_types=1);

/**
 * Subprocess: simulate storefront HTTP bootstrap (session + remember restore). Disposable DB only.
 */
if (getenv('AT_REMEMBER_HTTP_CHILD') !== '1') {
    fwrite(STDERR, "This script is only for remember HTTP integration subprocesses.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

TestScriptSafety::assertDisposableDatabaseOrAbort();
require_once __DIR__ . '/includes/customer_remember_test_helpers.php';
remember_integration_enable();

$outPath = getenv('AT_REMEMBER_HTTP_OUT');
$cookieFile = getenv('AT_REMEMBER_COOKIE_FILE');
$selector = getenv('AT_REMEMBER_SELECTOR');
if (!is_string($outPath) || $outPath === '' || !is_string($cookieFile) || !is_readable($cookieFile)
    || !is_string($selector) || $selector === '') {
    fwrite(STDERR, "Missing AT_REMEMBER_HTTP_OUT, cookie file, or selector.\n");
    exit(2);
}

$cookieRaw = trim((string) file_get_contents($cookieFile));
if ($cookieRaw === '' || CustomerRememberAuth::parseCookieValue($cookieRaw) === null) {
    file_put_contents($outPath, json_encode(['ok' => false]));
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$before = remember_token_row_by_selector($pdo, $selector);
$hashBefore = $before['token_hash'];

$config = App::config();
AppSession::start($config['session'] ?? []);
unset($_SESSION['customer_id']);
CustomerAuth::logout();
$_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieRaw;
$_SERVER['REQUEST_URI'] = (url_base() !== '' ? url_base() : '') . '/index.php';

CustomerRememberAuth::maybeRestoreFromCookie();

$after = remember_token_row_by_selector($pdo, $selector);
$customerId = CustomerAuth::id();

$payload = [
    'ok' => true,
    'restored' => $customerId !== null && $customerId > 0,
    'customer_id' => $customerId,
    'last_used_at_set' => $after['last_used_at'] !== null,
    'hash_rotated' => $hashBefore !== '' && $after['token_hash'] !== '' && !Security::hashEquals($hashBefore, $after['token_hash']),
];

file_put_contents($outPath, json_encode($payload));
exit($payload['restored'] ? 0 : 1);
