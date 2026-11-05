<?php

declare(strict_types=1);

/**
 * Concurrent coupon apply: two parallel requests must leave one coupon on the cart.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-cart-coupon-concurrent.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

AppSession::start(App::config()['session'] ?? []);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okCc(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$tok = strtolower(bin2hex(random_bytes(2)));
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('CC$tok', 'cc-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, ['name' => "CC$tok", 'slug' => "cc-$tok", 'status' => 'published', 'category_id' => (string) $cat]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [['id' => '', 'name' => '1', 'sku' => 'CC' . strtoupper($tok), 'price' => 50, 'sale_price' => '', 'stock_qty' => 50, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0]]);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) ProductRepository::variants($pid)[0]['id'];

$cA = 'CONA' . strtoupper($tok);
$cB = 'CONB' . strtoupper($tok);
foreach ([[$cA, 20], [$cB, 30]] as $pair) {
    $pdo->exec("INSERT INTO coupons (code, internal_name, discount_type, discount_value, is_active, stackable_with_offers)
        VALUES (" . $pdo->quote($pair[0]) . ", 't', 'fixed', {$pair[1]}, 1, 1)");
}

CartService::clear();
CartService::add($slug, $vid, 4);

putenv('APNI_TOKRI_ROOT=' . dirname(__DIR__));
$child = <<<'PHP'
<?php
require_once (getenv('APNI_TOKRI_ROOT') ?: dirname(__DIR__)) . '/includes/bootstrap.php';
AppSession::start(App::config()['session'] ?? []);
putenv('APNI_TOKRI_TEST_DB_NAME=' . getenv('APNI_TOKRI_TEST_DB_NAME'));
$code = $argv[1] ?? '';
$customerId = (int) ($argv[2] ?? 0);
usleep((int) ($argv[3] ?? 0));
if ($customerId > 0) {
    $_SESSION['customer_id'] = $customerId;
}
try {
    $r = CartCouponHandler::process(['coupon_code' => $code]);
    echo json_encode(['ok' => !empty($r['ok']), 'code' => $r['totals']['coupon_code'] ?? null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
}
PHP;
$childPath = sys_get_temp_dir() . '/at_coupon_concurrent_child.php';
file_put_contents($childPath, $child);

$env = 'APNI_TOKRI_DISPOSABLE_DB=1 APNI_TOKRI_TEST_DB_NAME=' . escapeshellarg((string) getenv('APNI_TOKRI_TEST_DB_NAME'))
    . ' APNI_TOKRI_TEST_DB_HOST=' . escapeshellarg((string) getenv('APNI_TOKRI_TEST_DB_HOST'))
    . ' APNI_TOKRI_TEST_DB_PORT=' . escapeshellarg((string) getenv('APNI_TOKRI_TEST_DB_PORT'))
    . ' APNI_TOKRI_TEST_DB_USER=' . escapeshellarg((string) getenv('APNI_TOKRI_TEST_DB_USER'))
    . ' APNI_TOKRI_TEST_DB_PASS=' . escapeshellarg((string) getenv('APNI_TOKRI_TEST_DB_PASS'));

$pdo->exec("INSERT INTO customers (mobile, name) VALUES ('99000$tok', 'Conc')");
$cust = (int) $pdo->lastInsertId();
$_SESSION['customer_id'] = $cust;
CartService::clear();
CartService::add($slug, $vid, 4);
$cartId = (int) $pdo->query('SELECT id FROM carts WHERE customer_id = ' . $cust)->fetchColumn();

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$cmdA = $env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childPath) . ' ' . escapeshellarg($cA) . ' ' . $cust . ' 0';
$cmdB = $env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childPath) . ' ' . escapeshellarg($cB) . ' ' . $cust . ' 0';
$procEnv = [];
foreach ($_ENV as $k => $v) {
    if (is_string($k) && is_string($v)) {
        $procEnv[$k] = $v;
    }
}
foreach (['APNI_TOKRI_ROOT', 'APNI_TOKRI_DISPOSABLE_DB', 'APNI_TOKRI_TEST_DB_NAME', 'APNI_TOKRI_TEST_DB_HOST', 'APNI_TOKRI_TEST_DB_PORT', 'APNI_TOKRI_TEST_DB_USER', 'APNI_TOKRI_TEST_DB_PASS'] as $ek) {
    $ev = getenv($ek);
    if ($ev !== false) {
        $procEnv[$ek] = $ev;
    }
}
$procA = proc_open($cmdA, $descriptors, $pipesA, null, $procEnv);
$procB = proc_open($cmdB, $descriptors, $pipesB, null, $procEnv);
$outA = stream_get_contents($pipesA[1]);
$errA = stream_get_contents($pipesA[2]);
$outB = stream_get_contents($pipesB[1]);
$errB = stream_get_contents($pipesB[2]);
$p1 = json_decode((string) $outA, true) ?: ['ok' => false, 'raw' => $outA, 'err' => $errA];
$p2 = json_decode((string) $outB, true) ?: ['ok' => false, 'raw' => $outB, 'err' => $errB];
fclose($pipesA[1]);
fclose($pipesB[1]);
proc_close($procA);
proc_close($procB);
$finalCode = (string) (CartService::totals()['coupon_code'] ?? '');
$stored = (string) $pdo->query('SELECT coupon_code FROM carts WHERE id = ' . $cartId)->fetchColumn();

okCc($p1['ok'] || $p2['ok'], 'At least one concurrent apply succeeded');
okCc($finalCode !== '' && in_array($finalCode, [$cA, $cB], true), 'Cart ends with exactly one applied coupon');
okCc($stored === $finalCode, 'Persisted cart coupon_code matches totals');
okCc($finalCode === ($p2['code'] ?? $finalCode) || $finalCode === ($p1['code'] ?? $finalCode), 'Final cart coupon matches one of the concurrent applies (single coupon slot)');

@unlink($childPath);
CartService::clear();
$pdo->exec("DELETE FROM coupons WHERE code IN (" . $pdo->quote($cA) . ',' . $pdo->quote($cB) . ')');
$pdo->exec("DELETE FROM customers WHERE id = $cust");

exit($fail > 0 ? 1 : 0);
