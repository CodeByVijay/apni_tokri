<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
Install::ensureConnection();
$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$badSort = AdminServerList::resolveSort("id; DROP TABLE coupons--", ['code' => 'code', 'id' => 'id'], 'code');
ok($badSort === 'code', 'Invalid sort column falls back to default');

$order = AdminServerList::orderBySql('code', 'desc', ['code' => 'code', 'id' => 'id'], 'id');
ok($order === 'code DESC', 'Sort direction whitelisted');

$couponPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/coupons/index.php');
ok(str_contains($couponPhp, "'key' => 'code'") && !str_contains($couponPhp, "'key' => 'link'"), 'Coupons index uses code column not link');
ok(str_contains($couponPhp, 'admin_table_actions') && str_contains($couponPhp, "'actions'"), 'Coupons actions column wired');

$all = CouponRepository::searchForAdmin('', null, 1, 5);
ok(isset($all['total'], $all['rows'], $all['total_pages']), 'Coupon searchForAdmin structure');
if ($all['total'] > 0) {
    $code = (string) ($all['rows'][0]['code'] ?? '');
    ok($code !== '', 'Coupon row includes code field');
    $filtered = CouponRepository::searchForAdmin($code, null, 1, 50);
    ok($filtered['total'] >= 1, 'Coupon search filters by code');
}

if ($all['total'] >= 3) {
    $page2 = CouponRepository::searchForAdmin('', null, 2, 2);
    ok($page2['page'] === 2 && count($page2['rows']) <= 2, 'Coupon pagination page 2');
} else {
    ok($all['page'] === 1, 'Coupon pagination single page when total < 3');
}

$offers = OfferRepository::searchForAdmin('', null, 1, 10);
ok($offers['total'] >= 0, 'Offer searchForAdmin');

$products = ProductRepository::searchForAdmin('', '', '', 1, 10);
ok($products['total'] >= 0, 'Product searchForAdmin');

$orders = AdminOrderService::searchForAdmin('', 1, 10);
ok(isset($orders['rows'][0]['actions']) || $orders['total'] === 0, 'Orders list uses actions column not link href in id column');

$pay = PaymentRepository::searchForAdmin('', 1, 10);
ok($pay['total'] >= 0, 'Payment searchForAdmin');

$cust = AdminDashboardService::searchCustomersForAdmin('', 1, 10);
ok($cust['total'] >= 0, 'Customer searchForAdmin');

$json = AdminServerList::dataTablesJson(1, 100, 50, [['a' => 1]]);
ok(str_contains($json, '"recordsTotal":100'), 'DataTables JSON shape available');

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
