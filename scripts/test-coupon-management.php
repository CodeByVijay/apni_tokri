<?php

declare(strict_types=1);

/**
 * Coupon management + eligibility tests. Disposable database only; no real payments.
 * CLI: php7.3 scripts/test-coupon-management.php
 * Internal: php7.3 scripts/test-coupon-management.php --child <db> <orderId> <code> <customerId>
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$dbCfg = App::config()['database'];

if (($argv[1] ?? '') === '--child') {
    App::setConfig(array_replace_recursive(App::config(), ['database' => array_merge($dbCfg, ['name' => (string) $argv[2]])]));
    Database::disconnect();
    Install::ensureConnection();
    try {
        CouponService::recordUsage((string) $argv[4], (int) $argv[3], (int) $argv[5]);
        exit(0);
    } catch (Throwable $e) {
        exit(3);
    }
}

$pass = 0;
$fail = 0;
function okC(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function mysqlC(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' 2>/dev/null ' . $extra, $code);

    return (int) $code;
}

/** Build a POST array for CouponAdminService. */
function postC(array $over = []): array
{
    return array_merge([
        'code' => '', 'internal_name' => '', 'discount_type' => 'percent', 'discount_value' => '10',
        'min_cart_value' => '', 'max_discount' => '', 'usage_limit' => '', 'per_customer_limit' => '',
        'priority' => '0', 'starts_at' => '', 'ends_at' => '', 'is_active' => '1',
        'eligibility_mode' => 'all', 'customer_mode' => 'all',
    ], $over);
}

function lineC(int $pid, int $cid, float $price, int $qty = 1): array
{
    return ['product_id' => $pid, 'category_id' => $cid, 'unit_price' => $price, 'qty' => $qty, 'regular_price' => $price];
}

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$freshDb = 'apni_tokri_coupon_' . date('YmdHis');

okC(mysqlC($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable DB');
okC(mysqlC($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import production SQL');

$orig = App::config();
App::setConfig(array_replace_recursive($orig, ['database' => array_merge($dbCfg, ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $pdo = Database::pdo();

    // ---- Fixtures ----
    $pdo->exec("INSERT INTO categories (name, slug) VALUES ('Fruits','t-fruits'),('Vegetables','t-veg')");
    $catA = (int) $pdo->query("SELECT id FROM categories WHERE slug='t-fruits'")->fetchColumn();
    $catB = (int) $pdo->query("SELECT id FROM categories WHERE slug='t-veg'")->fetchColumn();
    $mkProd = static function (string $name, string $sku, int $cat) use ($pdo): int {
        $pdo->prepare("INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, 'published')")
            ->execute([$cat, $name, strtolower($sku), $sku]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_variants (product_id, name, sku, price, stock_qty) VALUES (?, '1 kg', ?, 100, 50)")
            ->execute([$pid, $sku . '-1KG']);

        return $pid;
    };
    $p1 = $mkProd('Apple Shimla', 'APL-001', $catA);
    $p2 = $mkProd('Tomato Local', 'TOM-002', $catB);
    $p3 = $mkProd('Banana Robusta', 'BAN-003', $catA);
    $mkCust = static function (string $name, string $mobile, string $email) use ($pdo): int {
        $pdo->prepare('INSERT INTO customers (mobile, name, email) VALUES (?, ?, ?)')->execute([$mobile, $name, $email]);

        return (int) $pdo->lastInsertId();
    };
    $c1 = $mkCust('Asha Verma', '9811100001', 'asha@example.test');
    $c2 = $mkCust('Bharat Singh', '9811100002', 'bharat@example.test');
    $c3 = $mkCust('Chitra Rao', '9811100003', 'chitra@example.test');
    $cInactive = $mkCust('Dormant User', '9811100004', 'dormant@example.test');
    $pdo->exec("UPDATE customers SET is_active = 0 WHERE id = {$cInactive}");

    okC(CommerceGateway::useDatabase(), 'Commerce uses database in test');

    $mk = static function (array $over, ?int $id = null): array {
        return CouponAdminService::save($id, postC($over));
    };
    $idOf = static function (string $code) use ($pdo): int {
        $s = $pdo->prepare('SELECT id FROM coupons WHERE code = ?');
        $s->execute([$code]);

        return (int) $s->fetchColumn();
    };

    $cartMixed = [lineC($p1, $catA, 100.0), lineC($p2, $catB, 200.0)]; // 300 total

    // ---- 1-3 Codes ----
    $gen = [];
    for ($i = 0; $i < 25; $i++) {
        $gen[] = CouponAdminService::generateUniqueCode();
    }
    okC(count(array_unique($gen)) === 25 && count(array_filter($gen, 'CouponAdminService::codeIsValid')) === 25, 'Generated codes are valid uppercase alphanumeric and distinct');
    $r = $mk(['code' => '  fresh10 ']);
    okC($r['ok'] && $idOf('FRESH10') > 0, 'Manual code saved trimmed and uppercased (FRESH10)');
    $takenGen = $gen[0];
    $mk(['code' => $takenGen]);
    okC(CouponAdminService::codeExists($takenGen) && CouponAdminService::generateUniqueCode() !== $takenGen, 'Generator never returns an existing code');
    $dup = $mk(['code' => 'fresh10']);
    okC(!$dup['ok'] && isset($dup['errors']['code']), 'Duplicate code rejected server-side (case-insensitive)');
    $bad = $mk(['code' => 'a b!']);
    okC(!$bad['ok'] && isset($bad['errors']['code']), 'Invalid characters in code rejected');
    $ed = $mk(['code' => 'FRESH10', 'discount_value' => '12'], $idOf('FRESH10'));
    okC($ed['ok'], 'Editing a coupon may keep its own code');

    // ---- Input validation ----
    okC(!$mk(['code' => 'VAL1', 'discount_value' => '150'])['ok'], 'Percent over 100 rejected');
    okC(!$mk(['code' => 'VAL2', 'discount_value' => '0'])['ok'], 'Zero discount rejected');
    okC(!$mk(['code' => 'VAL3', 'starts_at' => '2030-01-02T10:00', 'ends_at' => '2030-01-01T10:00'])['ok'], 'End before start rejected');
    okC(!$mk(['code' => 'VAL4', 'usage_limit' => '2', 'per_customer_limit' => '5'])['ok'], 'Per-customer limit above total limit rejected');
    okC(!$mk(['code' => 'VAL5', 'usage_limit' => '-3'])['ok'], 'Negative usage limit rejected');
    okC(!$mk(['code' => 'VAL6', 'eligibility_mode' => 'categories'])['ok'], 'Category mode with nothing selected rejected');
    okC(!$mk(['code' => 'VAL7', 'customer_mode' => 'selected', 'customer_ids' => [999999]])['ok'], 'Selected customers must exist');
    okC(!$mk(['code' => 'VAL8', 'customer_mode' => 'selected', 'customer_ids' => [$cInactive]])['ok'], 'Inactive customers not eligible for selection');
    okC((int) $pdo->query("SELECT COUNT(*) FROM coupons WHERE code LIKE 'VAL%'")->fetchColumn() === 0, 'Rejected input wrote nothing');
    $fx = $mk(['code' => 'FIXMAX', 'discount_type' => 'fixed', 'discount_value' => '30', 'max_discount' => '5']);
    okC($fx['ok'] && $pdo->query("SELECT max_discount FROM coupons WHERE code='FIXMAX'")->fetchColumn() === null, 'Max discount ignored for fixed coupons');

    // ---- 7-11 Eligibility ----
    $mk(['code' => 'ALLP', 'discount_value' => '10']);
    $v = CouponService::validate('ALLP', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 30.0) < 0.01, 'All products: 10% of 300 = 30');

    $mk(['code' => 'CATA', 'eligibility_mode' => 'categories', 'category_ids' => [$catA]]);
    $v = CouponService::validate('CATA', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 10.0) < 0.01, 'Selected categories: only Fruits line discounted (10)');

    $mk(['code' => 'PRODB', 'eligibility_mode' => 'products', 'product_ids' => [$p2]]);
    $v = CouponService::validate('PRODB', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 20.0) < 0.01, 'Selected products: only Tomato discounted (20)');

    $mk(['code' => 'UNION', 'eligibility_mode' => 'both', 'category_ids' => [$catA], 'product_ids' => [$p2]]);
    $v = CouponService::validate('UNION', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 30.0) < 0.01, 'Categories + products use UNION logic (30)');
    $cartThree = [lineC($p1, $catA, 100.0), lineC($p2, $catB, 200.0), lineC($p3, $catA, 50.0, 2)];
    $v = CouponService::validate('PRODB', 400.0, null, $cartThree);
    okC($v['ok'] && abs($v['discount'] - 20.0) < 0.01, 'Mixed cart: ineligible lines are not discounted');

    $v = CouponService::validate('CATA', 200.0, null, [lineC($p2, $catB, 200.0)]);
    okC(!$v['ok'] && stripos((string) $v['message'], 'does not apply') !== false, 'No eligible products -> rejected with clear message');

    // ---- 12-14 Min / max / fixed ----
    $mk(['code' => 'MINELIG', 'eligibility_mode' => 'categories', 'category_ids' => [$catA], 'min_cart_value' => '150']);
    $v = CouponService::validate('MINELIG', 300.0, null, $cartMixed);
    okC(!$v['ok'] && stripos((string) $v['message'], 'Minimum') !== false, 'Minimum amount checked on eligible subtotal (100 < 150 although cart is 300)');
    $v = CouponService::validate('MINELIG', 400.0, null, [lineC($p1, $catA, 100.0, 2), lineC($p2, $catB, 200.0)]);
    okC($v['ok'], 'Minimum satisfied when eligible subtotal reaches it');

    $mk(['code' => 'CAP20', 'discount_value' => '50', 'max_discount' => '20']);
    $v = CouponService::validate('CAP20', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 20.0) < 0.01, 'Percentage maximum discount caps at 20');

    $mk(['code' => 'FIX500', 'discount_type' => 'fixed', 'discount_value' => '500']);
    $v = CouponService::validate('FIX500', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 300.0) < 0.01, 'Fixed discount never exceeds eligible value');
    $mk(['code' => 'FIX50A', 'discount_type' => 'fixed', 'discount_value' => '50', 'eligibility_mode' => 'categories', 'category_ids' => [$catA]]);
    $v = CouponService::validate('FIX50A', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 50.0) < 0.01, 'Fixed discount within eligible value (50)');
    $v = CouponService::validate('FIX50A', 40.0, null, [lineC($p1, $catA, 40.0)]);
    okC($v['ok'] && abs($v['discount'] - 40.0) < 0.01, 'Fixed discount clamped to eligible item value (40)');

    // ---- Delivery never discounted (pricing) ----
    $totals = PricingService::totalsFromLines($cartMixed, 'CATA', null);
    okC(abs((float) $totals['coupon_discount'] - 10.0) < 0.01, 'PricingService applies the same eligible-only discount');
    okC(abs((float) $totals['total'] - ((float) $totals['subtotal'] - (float) $totals['offer_discount'] - (float) $totals['coupon_discount'] + (float) $totals['delivery_fee'])) < 0.01, 'Total = items - discounts + delivery (delivery not discounted)');
    okC((float) $totals['total'] >= 0, 'Total is never negative');

    // ---- 15-16 Customers ----
    $mk(['code' => 'ONLYC1', 'customer_mode' => 'selected', 'customer_ids' => [$c1]]);
    okC(CouponService::validate('ONLYC1', 300.0, $c1, $cartMixed)['ok'], 'Selected customer can use coupon');
    okC(!CouponService::validate('ONLYC1', 300.0, $c2, $cartMixed)['ok'], 'Other customer rejected');
    okC(!CouponService::validate('ONLYC1', 300.0, null, $cartMixed)['ok'], 'Guest rejected for assigned coupon');
    $ids = static function (array $rows): array {
        return array_map(static function ($r) { return $r['id']; }, $rows);
    };
    okC($ids(CouponAdminService::searchCustomers('Bharat')) === [$c2], 'Customer search by name');
    okC($ids(CouponAdminService::searchCustomers('9811100003')) === [$c3], 'Customer search by mobile');
    okC($ids(CouponAdminService::searchCustomers('chitra@example')) === [$c3], 'Customer search by email');
    okC(!in_array($cInactive, $ids(CouponAdminService::searchCustomers('Dormant')), true), 'Inactive customers hidden from search');
    okC(count(CouponAdminService::searchCustomers('9811100', 2)) === 2, 'Customer search honours result limit');
    $prodByName = CouponAdminService::searchProducts('apple');
    okC(count($prodByName) === 1 && $prodByName[0]['sku'] === 'APL-001' && strpos($prodByName[0]['variants'], '1 kg') !== false, 'Product search by name shows SKU and variants');
    okC($ids(CouponAdminService::searchProducts('TOM-002')) === [$p2], 'Product search by SKU');
    okC($ids(CouponAdminService::searchProducts('BAN-003-1KG')) === [$p3], 'Product search by variant SKU');
    okC(count(CouponAdminService::searchProducts('', 2)) === 2, 'Product search is limited (no full catalogue load)');

    // ---- 17 First paid order ----
    $mk(['code' => 'FIRSTP', 'customer_mode' => 'first_order']);
    okC(!CouponService::validate('FIRSTP', 300.0, null, $cartMixed)['ok'], 'First-order coupon requires login');
    okC(CouponService::validate('FIRSTP', 300.0, $c3, $cartMixed)['ok'], 'Customer with no orders is first-order eligible');
    $mkOrder = static function (int $cid, string $status, string $pay) use ($pdo): int {
        $num = 'AT-CPN-' . strtoupper(substr(Security::randomToken(4), 0, 6));
        $pdo->prepare('INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at) VALUES (?, ?, ?, 100, 0, 0, 100, ?, NOW())')
            ->execute([$num, $cid, $status, $pay]);

        return (int) $pdo->lastInsertId();
    };
    $mkOrder($c3, 'pending_payment', 'pending');
    $mkOrder($c3, 'payment_failed', 'failed');
    okC(CouponService::validate('FIRSTP', 300.0, $c3, $cartMixed)['ok'], 'Unpaid / failed orders do not consume first-order eligibility');
    $ghost = $mkOrder($c3, 'confirmed', 'paid');
    $pdo->prepare("INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, 'simulate', 100, 'INR', 'failed', ?)")->execute([$ghost, 'g-' . $ghost]);
    okC(CouponService::validate('FIRSTP', 300.0, $c3, $cartMixed)['ok'], '"Paid" order with only failed payment rows is not a successful paid order');
    $real = $mkOrder($c3, 'confirmed', 'paid');
    $pdo->prepare("INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at) VALUES (?, 'simulate', 100, 'INR', 'captured', ?, NOW())")->execute([$real, 'p-' . $real]);
    okC(!CouponService::validate('FIRSTP', 300.0, $c3, $cartMixed)['ok'], 'Captured paid order makes customer ineligible');

    // ---- 18 Usage limits ----
    $mk(['code' => 'ONCE', 'usage_limit' => '1', 'per_customer_limit' => '1']);
    $o1 = $mkOrder($c1, 'confirmed', 'paid');
    CouponService::recordUsage('ONCE', $o1, $c1);
    CouponService::recordUsage('ONCE', $o1, $c1); // duplicate order -> ignored
    okC((int) $pdo->query("SELECT COUNT(*) FROM coupon_usages WHERE order_id = {$o1}")->fetchColumn() === 1, 'Duplicate usage record for same order ignored');
    okC((int) $pdo->query("SELECT used_count FROM coupons WHERE code='ONCE'")->fetchColumn() === 1, 'used_count incremented once');
    okC(!CouponService::validate('ONCE', 300.0, $c2, $cartMixed)['ok'], 'Total usage limit rejects further use');
    $mk(['code' => 'PERC', 'per_customer_limit' => '1']);
    $o2 = $mkOrder($c2, 'confirmed', 'paid');
    CouponService::recordUsage('PERC', $o2, $c2);
    okC(!CouponService::validate('PERC', 300.0, $c2, $cartMixed)['ok'], 'Per-customer limit rejects same customer');
    okC(CouponService::validate('PERC', 300.0, $c1, $cartMixed)['ok'], 'Per-customer limit allows other customers');
    $o3 = $mkOrder($c2, 'confirmed', 'paid');
    $threw = false;
    try {
        CouponService::recordUsage('PERC', $o3, $c2);
    } catch (Throwable $e) {
        $threw = true;
    }
    okC($threw && (int) $pdo->query("SELECT used_count FROM coupons WHERE code='PERC'")->fetchColumn() === 1, 'recordUsage enforces per-customer limit server-side');

    // ---- 19 Expired / inactive ----
    $mk(['code' => 'EXPIRED', 'starts_at' => '2020-01-01T00:00', 'ends_at' => '2020-02-01T00:00']);
    okC(!CouponService::validate('EXPIRED', 300.0, null, $cartMixed)['ok'], 'Expired coupon rejected');
    $mk(['code' => 'FUTURE', 'starts_at' => '2099-01-01T00:00']);
    okC(!CouponService::validate('FUTURE', 300.0, null, $cartMixed)['ok'], 'Not-yet-valid coupon rejected');
    $q = $pdo->prepare('UPDATE coupons SET is_active = 0 WHERE code = ?');
    $mk(['code' => 'OFFC', 'is_active' => '']);
    okC(!CouponService::validate('OFFC', 300.0, null, $cartMixed)['ok'], 'Inactive coupon rejected');

    // ---- 4-6 Select / deselect / clear (stored selections) ----
    $selId = $idOf('CATA');
    $mk(['code' => 'CATA', 'eligibility_mode' => 'categories', 'category_ids' => [$catA, $catB]], $selId);
    okC(count(CouponRepository::categoryIds($selId)) === 2, 'Select multiple categories');
    $mk(['code' => 'CATA', 'eligibility_mode' => 'categories', 'category_ids' => [$catB]], $selId);
    okC(CouponRepository::categoryIds($selId) === [$catB], 'Deselect a category removes it');
    $mk(['code' => 'CATA', 'eligibility_mode' => 'all'], $selId);
    okC(CouponRepository::categoryIds($selId) === [] && CouponAdminService::eligibilityModeFor([], []) === 'all', 'Clear all categories -> all products');
    $pid = $idOf('PRODB');
    $mk(['code' => 'PRODB', 'eligibility_mode' => 'products', 'product_ids' => [$p1, $p2, $p3]], $pid);
    okC(count(CouponRepository::productIds($pid)) === 3, 'Select multiple products');
    $mk(['code' => 'PRODB', 'eligibility_mode' => 'products', 'product_ids' => [$p3]], $pid);
    okC(CouponRepository::productIds($pid) === [$p3], 'Deselect products');
    $mk(['code' => 'PRODB', 'eligibility_mode' => 'all'], $pid);
    okC(CouponRepository::productIds($pid) === [], 'Clear all products');
    $cid1 = $idOf('ONLYC1');
    $mk(['code' => 'ONLYC1', 'customer_mode' => 'all'], $cid1);
    okC(CouponRepository::assignedCustomerIds($cid1) === [], 'Clear all customers -> all customers');

    // ---- 21 Existing coupons ----
    $pdo->exec("UPDATE coupons SET is_active = 1, ends_at = NULL, starts_at = NULL WHERE code = 'WELCOME10'");
    $v = CouponService::validate('WELCOME10', 400.0, null, [lineC($p1, $catA, 400.0)]);
    okC($v['ok'] && abs($v['discount'] - 40.0) < 0.01, 'Existing seeded coupon WELCOME10 still works (10% of 400)');
    $before = (int) $pdo->query("SELECT used_count FROM coupons WHERE code='ONCE'")->fetchColumn();
    $mk(['code' => 'ONCE', 'usage_limit' => '5', 'per_customer_limit' => '1'], $idOf('ONCE'));
    okC((int) $pdo->query("SELECT used_count FROM coupons WHERE code='ONCE'")->fetchColumn() === $before
        && (int) $pdo->query("SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = " . $idOf('ONCE'))->fetchColumn() === 1, 'Editing keeps used_count and usage history');
    $ex = $idOf('ALLP');
    $pdo->prepare('INSERT INTO coupon_excluded_products (coupon_id, product_id) VALUES (?, ?)')->execute([$ex, $p2]);
    $mk(['code' => 'ALLP', 'discount_value' => '10'], $ex);
    okC(CouponRepository::excludedProductIds($ex) === [$p2], 'Existing excluded products preserved on edit');
    $v = CouponService::validate('ALLP', 300.0, null, $cartMixed);
    okC($v['ok'] && abs($v['discount'] - 10.0) < 0.01, 'Excluded product still excluded from discount');

    // ---- 20 Concurrency (separate processes) ----
    $mk(['code' => 'RACE', 'usage_limit' => '2']);
    $orders = [];
    for ($i = 0; $i < 6; $i++) {
        $cust = $mkCust('Racer ' . $i, '98222000' . $i . '0', 'racer' . $i . '@example.test');
        $orders[] = [$mkOrder($cust, 'confirmed', 'paid'), $cust];
    }
    $procs = [];
    foreach ($orders as [$oid, $cust]) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($freshDb) . ' ' . $oid . ' RACE ' . $cust;
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
    $okCount = 0;
    foreach ($procs as $p) {
        $okCount += proc_close($p) === 0 ? 1 : 0;
    }
    $usedCount = (int) $pdo->query("SELECT used_count FROM coupons WHERE code='RACE'")->fetchColumn();
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ' . $idOf('RACE'))->fetchColumn();
    okC($usedCount === 2 && $rows === 2 && $okCount === 2, "Concurrent usage capped at limit (used_count={$usedCount}, rows={$rows}, successes={$okCount} of 6)");
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlC($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo $fail === 0 ? "PASS: coupon management ({$pass} checks).\n" : "Failures: {$fail} (passed {$pass})\n";
exit($fail === 0 ? 0 : 1);
