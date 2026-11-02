<?php

declare(strict_types=1);

/**
 * Customer profile / address management / logout regression tests.
 * Creates two throw-away customers and removes them afterwards.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$col = $pdo->query("SHOW COLUMNS FROM customer_addresses LIKE 'archived_at'")->fetch();
if (!$col) {
    fwrite(STDERR, "Apply database/migrations/1.17.0_customer_addresses_archive.sql first.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

$tag = substr(md5((string) microtime(true)), 0, 6);
$mk = static function (string $mobile, string $name) use ($pdo): int {
    $pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute([$mobile]);
    $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute([$mobile, $name]);

    return (int) $pdo->lastInsertId();
};
$A = $mk('9000000011', 'Acct Test A');
$B = $mk('9000000012', 'Acct Test B');
$orderNumbers = [];

try {
    // 1/2 profile
    $r = CustomerAccountService::updateProfile($A, '  New   Name ', "a{$tag}@example.com");
    $pa = CustomerAccountService::profile($A);
    ok($r['ok'] && $pa['name'] === 'New Name' && $pa['email'] === "a{$tag}@example.com", '1 customer updates own profile (name normalised, email saved)');
    ok($pa['mobile'] === '9000000011', '1 mobile unchanged by profile update');
    $pb = CustomerAccountService::profile($B);
    ok($pb['name'] === 'Acct Test B' && $pb['email'] === null, '2 other customer profile untouched (service scopes to given id only)');
    ok(!CustomerAccountService::updateProfile($A, '', '')['ok'], 'profile: empty name rejected');
    ok(isset(CustomerAccountService::updateProfile($A, 'Ok Name', 'not-an-email')['errors']['email']), 'profile: invalid email rejected');
    ok(isset(CustomerAccountService::updateProfile($B, 'Ok Name', "a{$tag}@example.com")['errors']['email']), 'profile: duplicate email rejected');
    ok(!CustomerAccountService::updateProfile($A, '<script>', '')['ok'], 'profile: markup in name rejected');
    $src = (string) file_get_contents(dirname(__DIR__) . '/my-profile.php');
    ok(strpos($src, "\$_POST['customer_id']") === false && strpos($src, "\$_POST['mobile']") === false, '2 profile page never reads customer_id/mobile from POST');

    // 3 add multiple
    $a1 = CustomerAccountService::addAddress($A, ['label' => 'Home', 'line1' => '1 Test Rd', 'pincode' => '452010']);
    $a2 = CustomerAccountService::addAddress($A, ['label' => 'Office', 'line1' => '2 Test Rd', 'line2' => 'Near X', 'pincode' => '452001']);
    $a3 = CustomerAccountService::addAddress($A, ['label' => 'Gift', 'line1' => '3 Test Rd', 'pincode' => '452010'], true);
    ok($a1['ok'] && $a2['ok'] && $a3['ok'] && count(CustomerAccountService::listAddresses($A)) === 3, '3 multiple addresses added');
    ok(!CustomerAccountService::addAddress($A, ['line1' => '', 'pincode' => '452010'])['ok'], 'address: empty line1 rejected');
    ok(isset(CustomerAccountService::addAddress($A, ['line1' => 'x', 'pincode' => '12'])['errors']['pincode']), 'address: bad pincode rejected');
    ok(isset(CustomerAccountService::addAddress($A, ['line1' => 'x', 'pincode' => '110001'])['errors']['pincode']), 'address: non-serviceable pincode rejected');

    // 7 only one default
    $defs = static function (int $cid) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL AND is_default = 1');
        $s->execute([$cid]);

        return (int) $s->fetchColumn();
    };
    ok($defs($A) === 1 && (int) CustomerAccountService::findAddress($A, $a3['id'])['is_default'] === 1, '7 exactly one default after add with make_default');
    CustomerAccountService::setDefault($A, $a1['id']);
    ok($defs($A) === 1 && (int) CustomerAccountService::findAddress($A, $a1['id'])['is_default'] === 1, '7 set default moves the single default');
    ok(!CustomerAccountService::setDefault($B, $a2['id'])['ok'] && $defs($A) === 1, '5/7 cannot set default on another customer\'s address');

    // 4/5 edit
    $e = CustomerAccountService::updateAddress($A, $a2['id'], ['label' => 'Work', 'line1' => '22 Test Rd', 'pincode' => '452001']);
    ok($e['ok'] && CustomerAccountService::findAddress($A, $a2['id'])['line1'] === '22 Test Rd', '4 edit own address (unreferenced: in place)');
    $x = CustomerAccountService::updateAddress($B, $a2['id'], ['label' => 'Pwn', 'line1' => 'hijack', 'pincode' => '452001']);
    ok(!$x['ok'] && CustomerAccountService::findAddress($A, $a2['id'])['line1'] === '22 Test Rd', '5 cannot edit another customer\'s address (no change)');
    ok(!CustomerAccountService::deleteAddress($B, $a2['id'])['ok'] && CustomerAccountService::findAddress($A, $a2['id']) !== null, '5 cannot delete another customer\'s address');

    // 8 checkout selection helpers
    $_SESSION = [];
    $_SESSION['customer_id'] = $A;
    $list = CustomerService::addresses();
    ok($list !== [] && (string) $list[0]['id'] === (string) $a1['id'] && $list[0]['is_default'] === '1', '8 checkout list shows default first and flags it');
    ok(CustomerService::address($a2['id'], $A) !== null, '8 checkout can select a saved address');
    ok(CustomerService::address($a2['id'], $B) === null, '8 checkout rejects another customer\'s address');
    $n = CustomerService::addAddress('9 Checkout St', '452010', 'Home');
    ok($n['ok'] && !empty($n['id']) && CustomerService::address((int) $n['id'], $A) !== null, '8 add-new-address at checkout returns id and is selectable');
    CustomerAccountService::deleteAddress($A, (int) $n['id']);

    // 9 historical snapshot
    $snapId = $a2['id'];
    $pdo->prepare("INSERT INTO orders (order_number, customer_id, customer_address_id, status, delivery_snapshot_json) VALUES (?, ?, ?, 'confirmed', ?)")
        ->execute(["AT-ACCT-{$tag}", $A, $snapId, json_encode(['pincode' => '452001'])]);
    $orderNumbers[] = "AT-ACCT-{$tag}";
    $before = $pdo->prepare('SELECT line1, pincode, label FROM customer_addresses WHERE id = ?');
    $before->execute([$snapId]);
    $orig = $before->fetch(PDO::FETCH_ASSOC);
    $e = CustomerAccountService::updateAddress($A, $snapId, ['label' => 'Changed', 'line1' => 'Totally New Line', 'pincode' => '452010']);
    $before->execute([$snapId]);
    $after = $before->fetch(PDO::FETCH_ASSOC);
    ok($e['ok'] && $after === $orig, '9 editing an order-referenced address leaves the historical row unchanged');
    ok($e['id'] !== $snapId && CustomerAccountService::findAddress($A, $e['id'])['line1'] === 'Totally New Line', '9 edit created a new active address');
    ok(CustomerAccountService::findAddress($A, $snapId) === null, '9 old row hidden from customer lists');
    ok(CustomerService::address($snapId, $A) === null, '9 archived address cannot be chosen at checkout');
    $oid = $pdo->prepare('SELECT customer_address_id FROM orders WHERE order_number = ?');
    $oid->execute(["AT-ACCT-{$tag}"]);
    ok((int) $oid->fetchColumn() === $snapId, '9 order still points at original address row');

    // 6 delete
    $newId = (int) $e['id'];
    $d = CustomerAccountService::deleteAddress($A, $a3['id']);
    ok($d['ok'] && empty($d['archived']) && CustomerAccountService::findAddress($A, $a3['id']) === null, '6 unreferenced address hard-deleted');
    $pdo->prepare("INSERT INTO orders (order_number, customer_id, customer_address_id, status) VALUES (?, ?, ?, 'confirmed')")
        ->execute(["AT-ACCT2-{$tag}", $A, $newId]);
    $orderNumbers[] = "AT-ACCT2-{$tag}";
    $d = CustomerAccountService::deleteAddress($A, $newId);
    $row = $pdo->prepare('SELECT archived_at FROM customer_addresses WHERE id = ?');
    $row->execute([$newId]);
    ok($d['ok'] && !empty($d['archived']) && $row->fetchColumn() !== null, '6 order-referenced address archived, not removed');
    // default deletion
    $cur = CustomerAccountService::listAddresses($A);
    $defId = 0;
    foreach ($cur as $c) {
        if ((int) $c['is_default'] === 1) {
            $defId = (int) $c['id'];
        }
    }
    CustomerAccountService::deleteAddress($A, $defId);
    $left = CustomerAccountService::listAddresses($A);
    ok($defs($A) === ($left === [] ? 0 : 1), '6/7 deleting the default promotes another (single default kept)');
    foreach ($left as $c) {
        CustomerAccountService::deleteAddress($A, (int) $c['id']);
    }
    ok(CustomerAccountService::listAddresses($A) === [] && $defs($A) === 0, '6 deleting last address leaves clean empty state');

    // 10-12 HTTP-level via subprocess harness
    $php = test_cli_php_binary();
    $root = dirname(__DIR__);
    $harness = static function (string $script, array $session, string $method, array $post, string $case) use ($php, $root): array {
        $dir = sys_get_temp_dir() . '/at_sess_' . getmypid();
        @mkdir($dir, 0700, true);
        $code = <<<'PHP'
<?php
$root = $argv[1]; $cfg = json_decode($argv[2], true);
session_save_path($cfg['dir']); session_id('acctharness'); 
$_SERVER['REQUEST_METHOD'] = $cfg['method']; $_SERVER['REQUEST_URI'] = '/' . $cfg['script']; $_SERVER['HTTP_HOST'] = 'localhost';
require $root . '/includes/bootstrap.php';
if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
$_SESSION = array_merge($_SESSION, $cfg['session']);
if (!empty($cfg['csrf'])) { $cfg['post']['_csrf_token'] = Csrf::token(); }
$_POST = $cfg['post'];
register_shutdown_function(function () {
    echo "\nSTATE:" . json_encode(['cid' => $_SESSION['customer_id'] ?? null, 'admin' => $_SESSION['admin_user_id'] ?? null, 'code' => http_response_code(), 'hdr' => headers_list()]) . "\n";
});
chdir($cfg['root']);
require $root . '/' . $cfg['script'];
PHP;
        $cfg = json_encode(['dir' => $dir, 'method' => $method, 'script' => $script, 'session' => $session, 'post' => $post, 'csrf' => $case === 'csrf_ok', 'root' => $root]);
        $out = [];
        $hf = $dir . '/harness.php';
        file_put_contents($hf, $code);
        exec(escapeshellarg($php) . ' ' . escapeshellarg($hf) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($cfg) . ' 2>&1', $out, $rc);
        $text = implode("\n", $out);
        if (getenv("AT_DEBUG")) { echo "[[" . $text . "]]\n"; }
        $state = null;
        if (preg_match('/STATE:(\{.*\})/', $text, $m)) {
            $state = json_decode($m[1], true);
        }

        return ['text' => $text, 'state' => $state];
    };

    $get = $harness('logout.php', ['customer_id' => $A], 'GET', [], 'none');
    ok($get['state'] !== null && (int) $get['state']['cid'] === $A, '10 GET /logout.php does not log out');
    $bad = $harness('logout.php', ['customer_id' => $A], 'POST', [], 'none');
    ok($bad['state'] !== null && (int) $bad['state']['cid'] === $A && strpos($bad['text'], 'Invalid or missing CSRF') !== false, '12 POST /logout.php without CSRF token is rejected (still logged in)');
    $good = $harness('logout.php', ['customer_id' => $A, 'checkout_nonce' => 'x', 'admin_user_id' => 77], 'POST', [], 'csrf_ok');
    ok($good['state'] !== null && $good['state']['cid'] === null, '10 POST + CSRF destroys customer authentication');
    ok($good['state'] !== null && (int) $good['state']['admin'] === 77, '10 logout leaves admin session keys untouched');
    ok($good['state'] !== null && in_array((int) $good['state']['code'], [302, 303], true)
        && strpos((string) file_get_contents($root . '/logout.php'), "'/index.php'") !== false, '10 logout redirects (3xx) to homepage');

    foreach (['my-profile.php', 'my-addresses.php', 'my-account.php'] as $page) {
        $u = $harness($page, [], 'GET', [], 'none');
        $guardSrc = (string) file_get_contents($root . '/' . $page) . (string) file_get_contents($root . '/includes/partials/account-guard.php');
        $redir = $u['state'] !== null && (int) $u['state']['code'] === 302 && strpos($guardSrc, '/login.php') !== false;
        ok($redir, "11 unauthenticated {$page} redirects to login");
    }
    foreach ([['my-profile.php', ['name' => 'X']], ['my-addresses.php', ['action' => 'add', 'line1' => 'x', 'pincode' => '452010']]] as $c) {
        $r = $harness($c[0], ['customer_id' => $A], 'POST', $c[1], 'none');
        ok(strpos($r['text'], 'Invalid or missing CSRF') !== false, "12 POST {$c[0]} without CSRF rejected");
    }
    $r = $harness('my-addresses.php', ['customer_id' => $A], 'POST', ['action' => 'add', 'label' => 'H', 'line1' => 'Harness St', 'pincode' => '452010'], 'csrf_ok');
    ok(count(CustomerAccountService::listAddresses($A)) === 1, '12 POST my-addresses.php with CSRF succeeds');
    $r = $harness('my-addresses.php', ['customer_id' => $B], 'POST', ['action' => 'delete', 'address_id' => (string) CustomerAccountService::listAddresses($A)[0]['id'], 'customer_id' => (string) $A], 'csrf_ok');
    ok(count(CustomerAccountService::listAddresses($A)) === 1, '5 IDOR: B (with forged customer_id) cannot delete A\'s address via page');
} finally {
    foreach ($orderNumbers as $on) {
        $pdo->prepare('DELETE FROM orders WHERE order_number = ?')->execute([$on]);
    }
    $pdo->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$A, $B]);
    $dir = sys_get_temp_dir() . '/at_sess_' . getmypid();
    foreach ((array) glob($dir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

echo $fail === 0 ? "\nPASS: customer account tests\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
