<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

Install::ensureConnection();
$origHandler = PaymentHttp::$testHandler;
PaymentHttp::$testHandler = null;

$restore = static function () use (&$origRzEn, &$origKid, &$origTm, &$origSec): void {
    AppSettings::set('razorpay.enabled', $origRzEn ?? '0');
    AppSettings::set('razorpay.key_id', $origKid ?? '');
    AppSettings::set('payment.test_mode', $origTm ?? '1');
    if ($origSec !== null && $origSec !== '') {
        AppSettings::set('razorpay.key_secret', $origSec, true);
    }
    PaymentGatewayDiagnosticState::invalidate('razorpay');
    AppSettings::resetCache();
};

$origRzEn = AppSettings::get('razorpay.enabled', null);
$origKid = AppSettings::get('razorpay.key_id', null);
$origTm = AppSettings::get('payment.test_mode', null);
$origSec = AppSettings::getSecret('razorpay.key_secret', null);

try {
    AppSettings::set('razorpay.enabled', '0');
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['config_state'] === PaymentGatewayDiagnosticState::CONFIG_NOT_CONFIGURED, 'disabled → not configured');

    AppSettings::set('razorpay.enabled', '1');
    AppSettings::set('razorpay.key_id', 'not_a_valid_key');
    AppSettings::set('payment.test_mode', '1');
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['config_state'] === PaymentGatewayDiagnosticState::CONFIG_INVALID_FORMAT, 'bad key id → invalid format');

    AppSettings::set('razorpay.key_id', 'rzp_live_abc123456');
    AppSettings::set('payment.test_mode', '1');
    AppSettings::set('razorpay.key_secret', 'sec_test_12345', true);
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['config_state'] === PaymentGatewayDiagnosticState::CONFIG_ENV_MISMATCH, 'live key + test mode → environment mismatch');

    AppSettings::set('razorpay.key_id', 'rzp_test_diag123456');
    AppSettings::set('payment.test_mode', '1');
    PaymentGatewayDiagnosticState::invalidate('razorpay');
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['config_state'] === PaymentGatewayDiagnosticState::CONFIG_READY, 'valid creds → configuration ready');
    ok(strpos($d['detail'], 'has not yet been verified') !== false, 'config ready shows not-yet-verified explanation');
    ok($d['api_state'] === null, 'no api state before any initiation');

    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 401, false, 'PAY-DIAG-401A');
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === PaymentGatewayDiagnosticState::API_AUTH_FAILED, '401 recorded → authentication failed');
    ok($d['http_status'] === 401, '401 http status stored');
    ok(strpos($d['detail'], 'HTTP 401') !== false, 'detail mentions HTTP 401');

    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 200, true, 'PAY-DIAG-OK1');
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === PaymentGatewayDiagnosticState::API_VERIFIED, 'success after 401 → verified (latest wins)');
    ok(strpos($d['detail'], 'verified') !== false, 'verified detail text');

    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 401, false, 'PAY-DIAG-401B');
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === PaymentGatewayDiagnosticState::API_AUTH_FAILED, '401 after verify → auth failed (stale success hidden)');

    $staleFp = 'deadbeef';
    AppSettings::set('payment.diag.razorpay', json_encode([
        'fp' => $staleFp,
        'result' => PaymentGatewayDiagnosticState::API_VERIFIED,
        'http' => 200,
        'at' => date(DATE_ATOM),
        'ref' => 'PAY-OLD',
    ], JSON_UNESCAPED_UNICODE));
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === null && strpos($d['detail'], 'has not yet been verified') !== false, 'fingerprint mismatch clears stale verified');

    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 503, false, 'PAY-DIAG-503');
    AppSettings::set('payment.diag.razorpay', json_encode([
        'fp' => PaymentGatewayDiagnosticState::configFingerprint('razorpay'),
        'result' => PaymentGatewayDiagnosticState::API_VERIFIED,
        'http' => 200,
        'at' => date(DATE_ATOM, time() - 60),
        'ref' => 'PAY-KEEP',
    ], JSON_UNESCAPED_UNICODE));
    AppSettings::resetCache();
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === PaymentGatewayDiagnosticState::API_VERIFIED, 'non-401 API error does not overwrite prior verified');

    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => ['id' => 'x'], 'error' => null];
    };
    PaymentGatewayDiagnosticState::invalidate('razorpay');
    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 200, true, 'PAY-MOCK-SHOULD-NOT');
    PaymentHttp::$testHandler = null;
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === null, 'mocked HTTP handler does not persist diagnostics');

    AppSettings::set('razorpay.key_id', 'rzp_test_diag999999');
    PaymentGatewayDiagnosticState::recordInitAttempt('razorpay', 200, true, 'PAY-BEFORE-SAVE');
    AppSettings::resetCache();
    PaymentGatewaySettingsService::saveFromPost([
        'active_gateway' => 'razorpay',
        'razorpay_enabled' => '1',
        'test_mode' => '1',
        'razorpay_key_id' => 'rzp_test_diag123456',
    ]);
    $d = PaymentGatewayDiagnosticState::adminView('razorpay');
    ok($d['api_state'] === null, 'credential change invalidates API diagnostic state');
} finally {
    PaymentHttp::$testHandler = $origHandler;
    $restore();
}

echo $fail === 0 ? "\nPASS: payment gateway diagnostics state\n" : "\nFAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
