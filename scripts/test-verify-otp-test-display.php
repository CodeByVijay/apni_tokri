<?php

declare(strict_types=1);

/**
 * verify-otp.php test OTP display (server-side env + SMS test mode gates).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-verify-otp-test-display.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (getenv('AT_OTP_DISP_CHILD') === '1') {
    $in = json_decode((string) getenv('AT_OTP_DISP_INPUT'), true);
    if (!is_array($in)) {
        exit(1);
    }
    $cfg = App::config();
    if (!empty($in['app_env']) && is_string($in['app_env'])) {
        $cfg = array_replace_recursive($cfg, ['app' => ['env' => $in['app_env']]]);
    }
    SmsEnvironmentService::setTestProductionOverride(null);
    if (array_key_exists('force_production', $in) && $in['force_production'] !== null) {
        SmsEnvironmentService::setTestProductionOverride((bool) $in['force_production']);
    }
    App::setConfig($cfg);
    if (isset($in['sms_mode'])) {
        AppSettings::set('sms.operation_mode', (string) $in['sms_mode']);
    }
    if (array_key_exists('fixed_otp', $in)) {
        AppSettings::set('sms.test.fixed_otp', (string) $in['fixed_otp']);
    }
    AppSettings::resetCache();
    $_SESSION['otp_pending_mobile'] = '9876500001';
    $_SESSION['_csrf_token'] = 'tok';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    register_shutdown_function(static function (): void {
        file_put_contents((string) getenv('AT_OTP_DISP_OUT'), (string) ob_get_contents());
    });
    ob_start();
    require dirname(__DIR__) . '/verify-otp.php';
    exit;
}

$fail = 0;
function okD(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function renderVerifyOtp(array $opts): string
{
    $out = tempnam(sys_get_temp_dir(), 'otpd');
    $env = 'AT_OTP_DISP_CHILD=1 AT_OTP_DISP_OUT=' . escapeshellarg($out)
        . ' AT_OTP_DISP_INPUT=' . escapeshellarg(json_encode($opts));
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>/dev/null');

    return (string) file_get_contents($out);
}

Install::ensureConnection();
AppSettings::set('sms.test.fixed_otp', '112233');
AppSettings::resetCache();

$code = '112233';
$visible = static function (string $html) use ($code): bool {
    return strpos($html, 'Test OTP: ' . $code) !== false
        && strpos($html, 'Development testing only') !== false
        && strpos($html, 'at-otp-test-hint') !== false;
};
$hidden = static function (string $html) use ($code): bool {
    return strpos($html, 'Test OTP:') === false && strpos($html, 'at-otp-test-hint') === false;
};

okD(OtpSmsService::fixedTestOtpForVerifyPageDisplay() === null, 'Helper null before env override in parent process');

$h = renderVerifyOtp(['app_env' => 'local', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => $code]);
okD($visible($h), 'Local + test mode: OTP visible');

$h = renderVerifyOtp(['app_env' => 'development', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => $code]);
okD($visible($h), 'Development + test mode: OTP visible');

$h = renderVerifyOtp(['app_env' => 'production', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => $code]);
okD($hidden($h), 'Production + test mode: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'production', 'sms_mode' => OtpSmsService::MODE_LIVE, 'fixed_otp' => $code]);
okD($hidden($h), 'Production + live mode: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'development', 'sms_mode' => OtpSmsService::MODE_LIVE, 'fixed_otp' => $code]);
okD($hidden($h), 'Development + live mode: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'development', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => '']);
okD($hidden($h), 'Development + test mode without fixed OTP: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'weird-env', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => $code]);
okD($hidden($h), 'Invalid environment: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'development', 'sms_mode' => 'invalid', 'fixed_otp' => $code]);
okD($hidden($h), 'Invalid SMS mode: OTP hidden');

$h = renderVerifyOtp(['app_env' => 'local', 'sms_mode' => OtpSmsService::MODE_TEST, 'fixed_otp' => $code]);
okD(strpos($h, 'type="hidden"') !== false && preg_match('/<script[^>]*>[^<]*112233/s', $h) !== 1, 'OTP not in hidden fields or inline script');
okD(!preg_match('/<!--[^>]*112233/', $h), 'OTP not inside HTML comment attributes');

AppSettings::set('sms.test.fixed_otp', '');
AppSettings::resetCache();

exit($fail > 0 ? 1 : 0);
