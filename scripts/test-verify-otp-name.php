<?php

declare(strict_types=1);

/**
 * verify-otp.php UI + returning/new registration fields.
 * Run: php scripts/run-in-disposable-db.php scripts/test-verify-otp-name.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (getenv('AT_VERIFY_CHILD') === '1') {
    $in = json_decode((string) getenv('AT_VERIFY_INPUT'), true);
    $_SESSION['otp_pending_mobile'] = $in['mobile'];
    $_SESSION['_csrf_token'] = 'tok';
    $_SERVER['REQUEST_METHOD'] = $in['method'];
    if ($in['method'] === 'POST') {
        $_POST = $in['post'] + ['_csrf_token' => 'tok'];
    }
    register_shutdown_function(static function (): void {
        $html = (string) ob_get_contents();
        file_put_contents((string) getenv('AT_VERIFY_OUT'), json_encode([
            'logged_in' => CustomerAuth::check(),
            'html' => $html,
        ]));
    });
    ob_start();
    @require dirname(__DIR__) . '/verify-otp.php';
    exit;
}

$fail = 0;
function okV(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function runPage(string $mobile, string $method, array $post = []): array
{
    $out = tempnam(sys_get_temp_dir(), 'atv');
    $env = 'AT_VERIFY_CHILD=1 AT_VERIFY_OUT=' . escapeshellarg($out)
        . ' AT_VERIFY_INPUT=' . escapeshellarg(json_encode(['mobile' => $mobile, 'method' => $method, 'post' => $post]));
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>/dev/null');
    $data = json_decode((string) file_get_contents($out), true);
    @unlink($out);

    return is_array($data) ? $data : ['logged_in' => false, 'html' => ''];
}

Install::ensureConnection();
$pdo = Database::pdo();
$migration = dirname(__DIR__) . '/database/migrations/1.36.0_customer_registration_consent.sql';
if (is_readable($migration)) {
    $pdo->exec((string) file_get_contents($migration));
}
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '654321');
AppSettings::resetCache();

$existing = '9876543210';
$fresh = '9876543211';
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?,?)')->execute([$existing, $fresh]);
$pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([
    $existing,
    'Saved Name',
    'saved@example.test',
]);

$page = runPage($existing, 'GET');
okV(strpos($page['html'], 'value="Saved Name"') !== false, 'existing customer sees saved name');
okV(strpos($page['html'], 'saved@example.test') !== false, 'existing customer sees saved email');
okV(strpos($page['html'], 'name="name"') === false, 'existing customer name is not submitted');
okV(strpos($page['html'], 'data-reg-consent') === false, 'returning customer: consent hidden');

$pageNew = runPage($fresh, 'GET');
okV(strpos($pageNew['html'], 'name="name"') !== false, 'new customer: editable name');
okV(strpos($pageNew['html'], 'name="email"') !== false, 'new customer: editable email');
okV(strpos($pageNew['html'], 'data-reg-consent') !== false, 'new customer: consent shown');

CustomerAuth::issueOtp($existing);
$r = runPage($existing, 'POST', ['otp' => '654321']);
okV($r['logged_in'] === true, 'existing customer verifies without registration fields');

CustomerAuth::issueOtp($fresh);
$r = runPage($fresh, 'POST', [
    'otp' => '654321',
    'name' => 'New Person',
    'email' => 'newperson@example.test',
    'consent' => '1',
]);
okV($r['logged_in'] === true, 'new customer registers after OTP with consent');

$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile IN (?,?)')->execute([$existing, $fresh]);
exit($fail > 0 ? 1 : 0);
