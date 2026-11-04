<?php

declare(strict_types=1);

/**
 * Cart coupon AJAX flow (disposable DB): apply, remove, validation, checkout guard.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

/** Mirrors ajax/cart/coupon.php JSON contract without HTTP exit. */
function couponAjax(array $post): array
{
    $result = CartCouponHandler::process($post);
    if (!$result['ok']) {
        return [
            'success' => false,
            'http' => 400,
            'error' => ['message' => $result['message'] ?? 'Coupon could not be applied.'],
        ];
    }

    return [
        'success' => true,
        'http' => 200,
        'message' => $result['message'] ?? 'Coupon applied.',
        'data' => ['totals' => $result['totals'] ?? DemoCart::totals()],
    ];
}

$tok = strtolower(bin2hex(random_bytes(3)));
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('Test Cat $tok', 'tcat-$tok', 'published')");
$catId = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, [
    'name' => "Coupon Cart $tok",
    'slug' => "coupon-cart-$tok",
    'status' => 'published',
    'category_id' => (string) $catId,
]);
$pid = (int) $prod['id'];
ProductRepository::saveVariants($pid, [[
    'id' => '', 'name' => '1 kg', 'sku' => 'CC-' . strtoupper($tok), 'price' => 100, 'sale_price' => '',
    'stock_qty' => 50, 'low_stock_threshold' => 5, 'is_active' => 1, 'sort_order' => 0,
]]);
$variants = ProductRepository::variants($pid);
$slug = (string) ProductRepository::find($pid)['slug'];
$vid = (string) $variants[0]['id'];

CartService::clear();
$add = CartService::add($slug, $vid, 2);
t($add['ok'] ?? false, 'Add items to cart for coupon flow');

// 3 empty coupon
$empty = couponAjax(['coupon_code' => '   ']);
t(empty($empty['success']) && strpos($empty['error']['message'], 'Please enter') !== false, 'Empty coupon returns visible error message');

// 2 invalid
$bad = couponAjax(['coupon_code' => 'NOTREAL' . $tok]);
t(empty($bad['success']) && ($bad['http'] === 400), 'Invalid coupon returns HTTP 400 JSON error envelope');

// Seed coupons (unique codes per run; WELCOME10 pattern validated via percent coupon)
$codes = [
    'W10' . strtoupper($tok) => ['percent', 10, 0],
    'MIN' . strtoupper($tok) => ['fixed', 50, 500],
    'ONC' . strtoupper($tok) => ['fixed', 20, 0],
    'EXP' . strtoupper($tok) => ['fixed', 10, 0],
    'OFF' . strtoupper($tok) => ['fixed', 10, 0],
];
foreach ($codes as $code => $cfg) {
    $pdo->exec("INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, is_active, stackable_with_offers)
        VALUES (" . $pdo->quote($code) . ", 'T', " . $pdo->quote($cfg[0]) . ", {$cfg[1]}, {$cfg[2]}, 1, 1)");
}
$welcomeCode = 'W10' . strtoupper($tok);
$minCode = 'MIN' . strtoupper($tok);
$onceCode = 'ONC' . strtoupper($tok);
$expCode = 'EXP' . strtoupper($tok);
$offCode = 'OFF' . strtoupper($tok);
$pdo->exec("UPDATE coupons SET ends_at = '2020-01-01 00:00:00' WHERE code = " . $pdo->quote($expCode));
$pdo->exec("UPDATE coupons SET usage_limit = 1, used_count = 1 WHERE code = " . $pdo->quote($onceCode));

// 1 valid (10% on 200 subtotal = 20 off)
$ok = couponAjax(['coupon_code' => $welcomeCode]);
t(!empty($ok['success']) && (float) ($ok['data']['totals']['coupon_discount'] ?? 0) > 0, 'Valid coupon applies server-side discount');
t(strtoupper((string) ($ok['data']['totals']['coupon_code'] ?? '')) === $welcomeCode, 'Applied coupon code stored in totals');

// 12 double apply idempotent
$ok2 = couponAjax(['coupon_code' => $welcomeCode]);
t(!empty($ok2['success']), 'Second apply while already applied succeeds');

// 6 minimum order
CartService::removeCoupon();
$minFail = couponAjax(['coupon_code' => $minCode]);
t(empty($minFail['success']) && strpos($minFail['error']['message'], 'Minimum') !== false, 'Minimum cart not reached message');

// 4 expired
$exp = couponAjax(['coupon_code' => $expCode]);
t(empty($exp['success']), 'Expired coupon rejected');

// 5 inactive
$pdo->exec('UPDATE coupons SET is_active = 0 WHERE code = ' . $pdo->quote($offCode));
$off = couponAjax(['coupon_code' => $offCode]);
t(empty($off['success']), 'Inactive coupon rejected');

// 7 usage limit
$once = couponAjax(['coupon_code' => $onceCode]);
t(empty($once['success']) && stripos($once['error']['message'], 'usage limit') !== false, 'Usage limit message');

// 8 remove
$applyAgain = couponAjax(['coupon_code' => $welcomeCode]);
t(!empty($applyAgain['success']), 'Re-apply valid coupon before remove');
$rem = couponAjax(['remove' => '1']);
t(!empty($rem['success']) && (float) ($rem['data']['totals']['coupon_discount'] ?? 0) === 0.0, 'Remove coupon clears discount');
t(empty(DemoCart::totals()['coupon_code']), 'Stored coupon cleared after remove');

// 9 qty change drops coupon when below minimum
CartService::clear();
CartService::add($slug, $vid, 6);
$minOk = couponAjax(['coupon_code' => $minCode]);
t(!empty($minOk['success']), 'MIN500 applies on eligible cart value');
$lines = CartService::items();
$lineKey = (string) ($lines[0]['line_key'] ?? '');
CartService::updateQty($lineKey, 1);
$afterQty = DemoCart::totals();
t(empty($afterQty['coupon_code']) && (float) ($afterQty['coupon_discount'] ?? 0) === 0.0, 'Coupon cleared when cart no longer eligible after qty change');

// 10 checkout revalidation — invalidate coupon after apply
couponAjax(['coupon_code' => $welcomeCode]);
$pdo->exec('UPDATE coupons SET is_active = 0 WHERE code = ' . $pdo->quote($welcomeCode));
$stale = CartService::totals();
t(empty($stale['coupon_code']) && empty($stale['coupon_error']), 'Invalid stored coupon auto-cleared on totals()');

// 11 error envelope shape (CSRF tested separately)
Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST[Csrf::fieldName()] = Csrf::token();
t(Csrf::validateRequest(), 'CSRF token validates for cart AJAX');
$_POST = [];
t(!Csrf::validateRequest(), 'Missing CSRF fails validation');

// cart.js must handle HTTP errors
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/cart.js');
t(strpos($js, '.fail(') !== false && strpos($js, 'couponBusy') !== false, 'cart.js handles AJAX failures and duplicate clicks');

CartService::clear();
$pdo->exec('DELETE FROM product_variants WHERE product_id = ' . $pid);
$pdo->exec('DELETE FROM products WHERE id = ' . $pid);
$pdo->exec('DELETE FROM categories WHERE id = ' . $catId);

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
