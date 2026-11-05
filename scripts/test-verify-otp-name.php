<?php

declare(strict_types=1);

/**
 * verify-otp.php name handling + OTP box markup + OTP JS helpers.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-verify-otp-name.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// ---- child mode: run verify-otp.php once with a faked request, report state as JSON ----
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
AppSettings::set('sms.operation_mode', OtpSmsService::MODE_TEST);
AppSettings::set('sms.test.fixed_otp', '654321');
AppSettings::resetCache();

$existing = '9876543210';
$fresh = '9876543211';
$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$existing, 'Saved Name']);
$countFor = static function (string $m) use ($pdo): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE mobile = ?');
    $s->execute([$m]);

    return (int) $s->fetchColumn();
};
$nameFor = static function (string $m) use ($pdo): ?string {
    $s = $pdo->prepare('SELECT name FROM customers WHERE mobile = ?');
    $s->execute([$m]);
    $v = $s->fetchColumn();

    return $v === false ? null : ($v === null ? '' : (string) $v);
};

// 1. Existing customer: read-only prefilled name, no editable name field.
$page = runPage($existing, 'GET');
okV(strpos($page['html'], 'value="Saved Name"') !== false, 'existing customer sees saved name');
okV(preg_match('/<input id="name"[^>]*readonly[^>]*disabled/', $page['html']) === 1, 'saved name is readonly + disabled');
okV(strpos($page['html'], 'name="name"') === false, 'existing customer name is not a submitted field');

// OTP boxes markup
okV(substr_count($page['html'], 'data-otp-box') === 6, 'six OTP boxes rendered');
okV(preg_match_all('/<input[^>]*data-otp-box[^>]*inputmode="numeric"/s', $page['html']) === 6, 'numeric keypad on all boxes');
okV(substr_count($page['html'], 'autocomplete="one-time-code"') === 1, 'one-time-code on first box only');
okV(preg_match('/<input id="otp" name="otp" type="hidden"/', $page['html']) === 1, 'combined hidden otp field kept');

// 2. Existing customer verifies without a name.
CustomerAuth::issueOtp($existing);
$r = runPage($existing, 'POST', ['otp' => '654321']);
okV($r['logged_in'] === true, 'existing customer verifies without submitting a name');
okV($nameFor($existing) === 'Saved Name', 'name unchanged after verify without name');

// 3. Manipulated POST cannot overwrite.
CustomerAuth::issueOtp($existing);
$r = runPage($existing, 'POST', ['otp' => '654321', 'name' => 'Hacker']);
okV($r['logged_in'] === true && $nameFor($existing) === 'Saved Name', 'forged name ignored for existing customer');

// Service-level guard too
CustomerAuth::issueOtp($existing);
okV(CustomerAuth::verifyOtp($existing, '654321', 'Other') && $nameFor($existing) === 'Saved Name', 'verifyOtp never overwrites saved name');
okV($countFor($existing) === 1, 'no duplicate for existing customer');

// 4. New customer sees editable name.
$page = runPage($fresh, 'GET');
okV(preg_match('/<input id="name" name="name"[^>]*>/', $page['html']) === 1 && strpos($page['html'], 'readonly') === false, 'new customer sees editable name input');

// 5. New customer registers with name after successful OTP.
CustomerAuth::issueOtp($fresh);
$r = runPage($fresh, 'POST', ['otp' => '000000', 'name' => 'New Person']);
okV($r['logged_in'] === false && $nameFor($fresh) === null, 'wrong OTP creates no customer');
CustomerAuth::issueOtp($fresh);
$r = runPage($fresh, 'POST', ['otp' => '654321', 'name' => 'New Person']);
okV($r['logged_in'] === true && $nameFor($fresh) === 'New Person', 'new customer saved with name after OTP');

// 6. No duplicates.
okV($countFor($fresh) === 1, 'exactly one record for new customer');

// Name-less existing record (e.g. earlier OTP-only signup) may still get a name once.
$noName = '9876543212';
$pdo->prepare('INSERT INTO customers (mobile) VALUES (?)')->execute([$noName]);
CustomerAuth::issueOtp($noName);
okV(CustomerAuth::verifyOtp($noName, '654321', 'First Name') && $nameFor($noName) === 'First Name', 'customer without name can set it once');

// 7. JS helpers (node)
$node = trim((string) shell_exec('command -v node'));
if ($node !== '') {
    $js = <<<'JS'
const o = require(process.argv[2]);
const cases = [['123456','123456'],['123 456','123456'],['123-456','123456'],['12','12'],['1234567890','123456'],['a1b2c3','123']];
let bad = 0;
for (const [i, e] of cases) { const r = o.distribute(i); const got = r.values.join(''); if (got !== e) { console.log('BAD', i, got); bad++; } }
const r = o.distribute('12'); if (r.focusIndex !== 2) { console.log('BAD focus'); bad++; }
const f = o.distribute('123456'); if (f.focusIndex !== 5) { console.log('BAD focus full'); bad++; }
process.exit(bad);
JS;
    $tmp = tempnam(sys_get_temp_dir(), 'otpjs') . '.js';
    file_put_contents($tmp, $js);
    exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/otp-input.js'), $o, $code);
    @unlink($tmp);
    okV($code === 0, 'JS paste distribution (123456, 123 456, 123-456, short, long, mixed)');
} else {
    echo "SKIP: node not available\n";
}

$pdo->prepare('DELETE FROM customer_otp_attempts WHERE mobile IN (?,?,?)')->execute([$existing, $fresh, $noName]);
exit($fail > 0 ? 1 : 0);
