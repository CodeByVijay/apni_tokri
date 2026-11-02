<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile = ?')->execute(['9876500001']);
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '112233');
AppSettings::resetCache();

$mobile = '9876500001';
$failures = 0;
$blockedOnFourth = false;
for ($i = 0; $i < 4; $i++) {
    try {
        CustomerAuth::issueOtp($mobile);
        echo "OTP request " . ($i + 1) . " ok\n";
    } catch (Throwable $e) {
        echo "OTP request " . ($i + 1) . " blocked: " . $e->getMessage() . "\n";
        if ($i === 3) {
            $blockedOnFourth = true;
        } else {
            $failures++;
        }
    }
}

if ($blockedOnFourth && $failures === 0) {
    echo "PASS: rate limit triggered.\n";
    exit(0);
}

echo "FAIL: expected rate limit on 4th request.\n";
exit(1);
