<?php

declare(strict_types=1);

/**
 * Login mobile number validation (frontend contract + server normalize rules).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

// 1 valid 10-digit
ok(Validation::normalizeMobileIndia('9876543210') === '9876543210', '1 valid 10-digit accepted');
ok(Validation::normalizeMobileIndia(' 98765 43210 ') === '9876543210', '1 strips spaces');

// 2 nine-digit rejected
ok(Validation::normalizeMobileIndia('987654321') === null, '2 nine-digit rejected');

// 3 eleven-digit rejected
ok(Validation::normalizeMobileIndia('98765432101') === null, '3 eleven-digit rejected');

// 4 alphabetic rejected
ok(Validation::normalizeMobileIndia('98abcdefgh') === null, '4 alphabetic rejected');

// 5 special characters handled
ok(Validation::normalizeMobileIndia('+91-98765-43210') === '9876543210', '5 formatted +91 number accepted');
ok(Validation::normalizeMobileIndia('(987) 654-3210') === '9876543210', '5 punctuation stripped');

// 7 +91 prefix
ok(Validation::normalizeMobileIndia('919876543210') === '9876543210', '7 +91 prefix handled');
ok(Validation::normalizeMobileIndia('+919876543210') === '9876543210', '7 +91 with plus handled');

// 3/6 overflow cannot normalize to valid
ok(Validation::normalizeMobileIndia('98765432109999') === null, '6 pasted overflow rejected');
ok(Validation::normalizeMobileIndia('91987654321099') === null, '6 long +91 overflow rejected');

// Invalid starting digit
ok(Validation::normalizeMobileIndia('5876543210') === null, 'invalid leading digit rejected');

// Backend matrix: lengths, letters, symbols
ok(Validation::normalizeMobileIndia('987654321') === null, 'M 9 digits rejected');
ok(Validation::normalizeMobileIndia('9876543210') === '9876543210', 'M 10 digits accepted');
ok(Validation::normalizeMobileIndia('98765432101') === null, 'M 11 digits rejected');
ok(Validation::normalizeMobileIndia('919876543210') === '9876543210', 'M 12 digits with 91 accepted');
ok(Validation::normalizeMobileIndia('989876543210') === null, 'M 12 digits without 91 rejected');
ok(Validation::normalizeMobileIndia('987654321012345') === null, 'M 15 digits rejected');
ok(Validation::normalizeMobileIndia('+919876543210') === '9876543210', 'M +91 accepted');
ok(Validation::normalizeMobileIndia('98765abc43210') === null, 'M letters mixed with digits rejected');
ok(Validation::normalizeMobileIndia('9876543210; DROP') === null, 'M injection chars rejected');
ok(Validation::normalizeMobileIndia('98765#43210') === null, 'M # symbol rejected');
ok(Validation::normalizeMobileIndia('98765+43210') === null, 'M + not at start rejected');
ok(Validation::normalizeMobileIndia("9876543210\0") === null, 'M NUL byte rejected');

// 8 invalid must not create OTP row when schema ready
if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $mobile = '9876500099';
    $before = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts')->fetchColumn();
    $threw = false;
    try {
        CustomerAuth::issueOtp('1234567890');
    } catch (Throwable $e) {
        $threw = true;
    }
    $after = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts')->fetchColumn();
    ok($threw && $after === $before, '8 invalid number does not issue OTP (exception, no new row)');
    unset($mobile);
    $bad = ['987654321', '98765432101', '989876543210', '987654321012345', '98765abc43210', '98765#43210'];
    $allRejected = true;
    $before = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts')->fetchColumn();
    foreach ($bad as $b) {
        try {
            CustomerAuth::issueOtp($b);
            $allRejected = false;
        } catch (OtpRequestException $e) {
            $allRejected = $allRejected && $e->errorCode() === OtpSmsService::ERR_INVALID_MOBILE;
        }
    }
    $after = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts')->fetchColumn();
    ok($allRejected && $before === $after, 'issueOtp rejects malformed inputs with INVALID_MOBILE, no rows');
} else {
    ok(true, '8 OTP row check skipped (schema not ready)');
}

// 9 server rejects bypass — login.php uses normalizeMobileIndia before issueOtp
$loginSrc = (string) file_get_contents(dirname(__DIR__) . '/login.php');
ok(strpos($loginSrc, 'Validation::normalizeMobileIndia') !== false, '9 login.php normalizes server-side before OTP');
ok(strpos((string) file_get_contents(dirname(__DIR__) . '/assets/js/apni-tokri.js'), 'initMobileLogin') !== false, 'frontend initMobileLogin present');
ok(strpos($loginSrc, 'maxlength="10"') !== false, 'frontend maxlength=10 on login input');

echo $fail === 0 ? "\nPASS: login mobile validation\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
