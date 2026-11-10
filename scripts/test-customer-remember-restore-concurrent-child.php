<?php

declare(strict_types=1);

/**
 * Subprocess: concurrent remember restore against the same selector. Disposable DB only.
 */
if (getenv('AT_REMEMBER_CONCURRENT_CHILD') !== '1') {
    fwrite(STDERR, "This script is only for remember concurrent integration subprocesses.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';
require_once __DIR__ . '/includes/customer_remember_test_helpers.php';

TestScriptSafety::assertDisposableDatabaseOrAbort();
remember_integration_enable();

$outPath = getenv('AT_REMEMBER_CONCURRENT_OUT');
$cookieFile = getenv('AT_REMEMBER_COOKIE_FILE');
$barrier = getenv('AT_REMEMBER_BARRIER');
if (!is_string($outPath) || $outPath === '' || !is_string($cookieFile) || !is_readable($cookieFile)
    || !is_string($barrier) || $barrier === '') {
    fwrite(STDERR, "Missing concurrent child env.\n");
    exit(2);
}

$cookieRaw = trim((string) file_get_contents($cookieFile));
$parsed = CustomerRememberAuth::parseCookieValue($cookieRaw);
if ($parsed === null) {
    file_put_contents($outPath, json_encode(['ok' => false]));
    exit(1);
}

// Wait for parent to release barrier so both children enter restore together.
$deadline = microtime(true) + 15.0;
while (is_file($barrier) && microtime(true) < $deadline) {
    usleep(20_000);
}

$config = App::config();
AppSession::start($config['session'] ?? []);
unset($_SESSION['customer_id']);
CustomerAuth::logout();
$_COOKIE[CustomerRememberAuth::COOKIE_NAME] = $cookieRaw;
$_SERVER['REQUEST_URI'] = (url_base() !== '' ? url_base() : '') . '/cart.php';

CustomerRememberAuth::maybeRestoreFromCookie();

$customerId = CustomerAuth::id();
$parsedAfter = CustomerRememberAuth::parseCookieValue($_COOKIE[CustomerRememberAuth::COOKIE_NAME] ?? '');

file_put_contents($outPath, json_encode([
    'ok' => true,
    'restored' => $customerId !== null && $customerId > 0,
    'customer_id' => $customerId,
    'cookie_parseable' => $parsedAfter !== null,
]));

exit(0);
