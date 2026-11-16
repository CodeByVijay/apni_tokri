<?php

declare(strict_types=1);

/**
 * Homepage min-quantity variant regression (Option C):
 * - variants with min_qty_per_order > 1 are selectable but never qty=1 quick-added
 * - selecting one links to product.php?slug=...&variant_id=... (purchasable only)
 * - PDP preselects a valid requested variant and falls back on invalid/unavailable
 * - cart backends still enforce min/max/stock on the final target quantity
 *
 * php scripts/run-in-disposable-db.php scripts/test-home-variant-min-qty.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

// Buffer all output so product.php's header()/session calls see headers_sent() === false.
ob_start();

$fail = 0;
$check = static function (bool $condition, string $label): void {
    global $fail;
    echo ($condition ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$condition) {
        ++$fail;
    }
};

$pdo = Database::pdo();
$categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$check($categoryId > 0, 'seeded category available');
if ($categoryId <= 0) {
    exit(1);
}

$insertVariant = static function (int $productId, string $name, float $price, int $stock, ?int $max, int $min, int $sort) use ($pdo): int {
    $pdo->prepare(
        'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, max_qty_per_order, min_qty_per_order, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$productId, $name, 'SKU-' . $name . '-' . $productId, $price, $stock, $max, $min, $sort]);

    return (int) $pdo->lastInsertId();
};

// Product A: mixed variants — min2, min1, out of stock, min above sellable cap.
$slugA = 'tmp-min-card';
$pdo->prepare("INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, 'Min Qty Card', ?, 'TMP-MIN-CARD', 'published')")
    ->execute([$categoryId, $slugA]);
$prodA = (int) $pdo->lastInsertId();
$vA = [
    'min1' => $insertVariant($prodA, 'One Pack', 45, 30, null, 1, 0),
    'min2' => $insertVariant($prodA, 'Two Pack', 25, 89, 5, 2, 1),
    'oos' => $insertVariant($prodA, 'No Stock', 30, 0, null, 1, 2),
    'over' => $insertVariant($prodA, 'Over Cap', 60, 3, 5, 5, 3),
];

// Product B: only min-quantity variants, one unsatisfiable.
$slugB = 'tmp-min-only';
$pdo->prepare("INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, 'Min Only', ?, 'TMP-MIN-ONLY', 'published')")
    ->execute([$categoryId, $slugB]);
$prodB = (int) $pdo->lastInsertId();
$vB = [
    'min2' => $insertVariant($prodB, 'Two Pack', 50, 10, null, 2, 0),
    'over' => $insertVariant($prodB, 'Over Stock', 70, 2, null, 3, 1),
];

CatalogueGateway::reset();
$check(AppEnvironment::usePersistedStorefront(), 'database-backed storefront path selected');

$productA = app_catalogue()->product($slugA);
$productB = app_catalogue()->product($slugB);
$check(is_array($productA) && count($productA['variants'] ?? []) === 4, 'product A loads 4 variants');
$check(is_array($productB) && count($productB['variants'] ?? []) === 2, 'product B loads 2 variants');

/* ---------- catalogue_variant_order_state ---------- */

$stateOf = static function (array $product, int $variantId): ?array {
    foreach ($product['variants'] as $v) {
        if ((int) $v['id'] === $variantId) {
            return catalogue_variant_order_state($v);
        }
    }

    return null;
};

$sMin2 = $stateOf($productA, $vA['min2']);
$sMin1 = $stateOf($productA, $vA['min1']);
$sOos = $stateOf($productA, $vA['oos']);
$sOver = $stateOf($productA, $vA['over']);

$check($sMin2 !== null && $sMin2['order_min'] === 2 && $sMin2['order_cap'] === 5 && $sMin2['stock'] === 89, 'min2 state: min=2 cap=5 stock=89');
$check($sMin2 !== null && $sMin2['purchasable'] && !$sMin2['can_quick_add'], 'min2 purchasable but not qty=1 quick-addable');
$check($sMin1 !== null && $sMin1['purchasable'] && $sMin1['can_quick_add'] && $sMin1['order_min'] === 1, 'min1 purchasable and quick-addable');
$check($sOos !== null && !$sOos['purchasable'] && !$sOos['can_quick_add'], 'out-of-stock variant unsatisfiable');
$check($sOver !== null && $sOver['order_cap'] === 3 && !$sOver['purchasable'], 'min above sellable cap is unsatisfiable');

/* ---------- catalogue_variant_from_request ---------- */

$req = catalogue_variant_from_request($productA, (string) $vA['min2']);
$check($req !== null && (int) $req['id'] === $vA['min2'], 'request resolves min2 variant');
$req = catalogue_variant_from_request($productA, (string) $vA['min1']);
$check($req !== null && (int) $req['id'] === $vA['min1'], 'request resolves min1 variant');
$check(catalogue_variant_from_request($productA, '999999') === null, 'unknown variant id rejected');
$check(catalogue_variant_from_request($productA, (string) $vA['over']) === null, 'unsatisfiable variant id rejected');
$check(catalogue_variant_from_request($productA, 'x"><script>') === null, 'hostile variant id rejected');
$check(catalogue_variant_from_request($productA, null) === null, 'missing variant id rejected');
$check(catalogue_variant_from_request($productA, ['1']) === null, 'array variant id rejected');

/* ---------- homepage card markup ---------- */

$renderCard = static function (array $product): string {
    $homeProductCard = true;
    ob_start();
    require APNI_TOKRI_ROOT . '/includes/partials/product-card.php';

    return (string) ob_get_clean();
};

$optionHtml = static function (string $html, int $variantId): string {
    if (preg_match('/<option value="' . $variantId . '"[^>]*>.*?<\/option>/s', $html, $m)) {
        return $m[0];
    }

    return '';
};

$htmlA = $renderCard($productA);
$optMin2 = $optionHtml($htmlA, $vA['min2']);
$optMin1 = $optionHtml($htmlA, $vA['min1']);
$optOos = $optionHtml($htmlA, $vA['oos']);
$optOver = $optionHtml($htmlA, $vA['over']);

$check(strpos($htmlA, 'data-card-variant-picker') !== false, 'mixed card renders variant picker');
$check($optMin2 !== '', 'min2 option rendered');
$check(strpos($optMin2, 'disabled') === false, 'min2 option enabled');
$check(strpos($optMin2, 'data-purchasable="1"') !== false, 'min2 option marked purchasable');
$check(strpos($optMin2, 'data-orderable="0"') !== false, 'min2 option not qty=1 orderable');
$check(strpos($optMin2, 'data-order-min="2"') !== false, 'min2 option carries order-min');
$check(strpos($optMin2, 'data-pdp-url') !== false && strpos($optMin2, 'variant_id=' . $vA['min2']) !== false, 'min2 option carries PDP url');
$check(strpos($optMin2, 'Min 2') !== false, 'min2 option labeled with minimum');
$check(strpos($optMin1, 'data-orderable="1"') !== false && strpos($optMin1, 'disabled') === false, 'min1 option quick-addable');
$check(strpos($optMin1, 'selected') !== false, 'min1 option is default selection');
$check(strpos($optMin1, '· Min ') === false, 'min1 option has no min label');
$check(strpos($optOos, 'disabled') !== false, 'out-of-stock option disabled');
$check(strpos($optOver, 'disabled') !== false, 'unsatisfiable min option disabled');
$check(
    preg_match('/js-demo-add-cart[^>]*data-variant-id="' . $vA['min1'] . '"/', $htmlA) === 1
    || preg_match('/data-variant-id="' . $vA['min1'] . '"[^>]*js-demo-add-cart/', $htmlA) === 1,
    'card add button bound to quick-addable variant'
);

$htmlB = $renderCard($productB);
$optBMin2 = $optionHtml($htmlB, $vB['min2']);
$optBOver = $optionHtml($htmlB, $vB['over']);
$check(strpos($htmlB, 'data-card-variant-picker') !== false, 'all-min product still renders picker');
$check(strpos($optBMin2, 'disabled') === false && strpos($optBMin2, 'data-pdp-url') !== false, 'all-min product keeps purchasable option enabled');
$check(strpos($optBOver, 'disabled') !== false, 'all-min product keeps unsatisfiable option disabled');
$check(preg_match('/>\s*View\s*<\/button>/s', $htmlB) === 1, 'all-min card CTA renders View, not Add');
$check(strpos($htmlB, 'data-pdp-url') !== false, 'all-min card CTA links to PDP');

/* ---------- PDP render with variant preselection ---------- */

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = 'off';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'cli-test';
$_SERVER['DOCUMENT_ROOT'] = APNI_TOKRI_ROOT;
$_SERVER['SCRIPT_FILENAME'] = APNI_TOKRI_ROOT . '/product.php';

$renderPdp = static function (string $slug, $variantId): string {
    $_GET = ['slug' => $slug];
    if ($variantId !== null) {
        $_GET['variant_id'] = $variantId;
    }
    $_SERVER['REQUEST_URI'] = '/product.php?slug=' . $slug . ($variantId !== null ? '&variant_id=' . urlencode((string) $variantId) : '');
    $_POST = [];
    ob_start();
    try {
        include APNI_TOKRI_ROOT . '/product.php';
    } catch (Throwable $e) {
        $partial = (string) ob_get_clean();
        fwrite(STDERR, 'product.php render failed: ' . $e->getMessage() . PHP_EOL);

        return $partial === '' ? '__RENDER_ERROR__' : $partial;
    }

    return (string) ob_get_clean();
};

$pdpMin2 = $renderPdp($slugA, (string) $vA['min2']);
$check(strpos($pdpMin2, 'value="' . $vA['min2'] . '"') !== false, 'pdp renders requested variant radio');
$check(
    preg_match('/name="variant_id"[^>]*value="' . $vA['min2'] . '"[^>]*checked/', $pdpMin2) === 1,
    'pdp checks the requested min2 variant'
);
$check(
    preg_match('/id="qty"[^>]*min="2"[^>]*max="5"[^>]*value="2"/', $pdpMin2) === 1,
    'pdp quantity initialized to min=2 value=2'
);
$check(strpos($pdpMin2, 'Minimum order: 2 units.') !== false, 'pdp shows minimum-order hint');

$pdpMin1 = $renderPdp($slugA, (string) $vA['min1']);
$check(
    preg_match('/name="variant_id"[^>]*value="' . $vA['min1'] . '"[^>]*checked/', $pdpMin1) === 1,
    'pdp checks requested min1 variant'
);
$check(
    preg_match('/id="qty"[^>]*min="1"[^>]*value="1"/', $pdpMin1) === 1,
    'pdp quantity stays 1 for min1 variant'
);

$pdpBad = $renderPdp($slugA, '999999');
$check(
    preg_match('/name="variant_id"[^>]*value="' . $vA['min1'] . '"[^>]*checked/', $pdpBad) === 1,
    'pdp falls back to default variant for unknown id'
);

$pdpOver = $renderPdp($slugA, (string) $vA['over']);
$check(
    preg_match('/name="variant_id"[^>]*value="' . $vA['min1'] . '"[^>]*checked/', $pdpOver) === 1,
    'pdp falls back to default variant for unsatisfiable id'
);

/* ---------- cart backend enforcement ---------- */

$cartRowsFor = static function (int $variantId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT ci.quantity FROM cart_items ci
         INNER JOIN carts c ON c.id = ci.cart_id
         WHERE ci.variant_id = ? AND c.session_id = ?'
    );
    $stmt->execute([$variantId, (string) ($_SESSION['guest_cart_sid'] ?? '')]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$result = CartService::add($slugA, (string) $vA['min2'], 1);
$check(empty($result['ok']) && strpos((string) ($result['message'] ?? ''), 'Minimum 2') !== false, 'db cart rejects qty=1 for min=2 variant');
$check($cartRowsFor($vA['min2']) === [], 'db cart stores nothing on rejected add');

$result = CartService::add($slugA, (string) $vA['min2'], 2);
$check(!empty($result['ok']), 'db cart accepts qty=2 for min=2 variant');
$check((int) ($cartRowsFor($vA['min2'])[0]['quantity'] ?? 0) === 2, 'db cart stores quantity 2');

$result = CartService::add($slugA, (string) $vA['min2'], 2);
$check(!empty($result['ok']), 'db cart accepts second +2 (total 4 within cap 5)');
$check((int) ($cartRowsFor($vA['min2'])[0]['quantity'] ?? 0) === 4, 'db cart accumulates to quantity 4');

$result = CartService::add($slugA, (string) $vA['min2'], 2);
$check(empty($result['ok']), 'db cart rejects add that would exceed cap (4+2 > 5)');
$check((int) ($cartRowsFor($vA['min2'])[0]['quantity'] ?? 0) === 4, 'db cart quantity unchanged after rejected add');

$result = CartService::add($slugA, (string) $vA['oos'], 1);
$check(empty($result['ok']), 'db cart rejects out-of-stock variant');
$result = CartService::add($slugA, (string) $vA['over'], 5);
$check(empty($result['ok']), 'db cart rejects min above sellable stock');

$_SESSION['demo_cart'] = ['items' => [], 'coupon_code' => null];
$result = SessionCartBackend::add($slugA, (string) $vA['min2'], 1);
$check(empty($result['ok']), 'session cart rejects qty=1 for min=2 variant');
$result = SessionCartBackend::add($slugA, (string) $vA['min2'], 2);
$check(!empty($result['ok']), 'session cart accepts qty=2 for min=2 variant');
$result = SessionCartBackend::add($slugA, (string) $vA['min2'], 5);
$check(empty($result['ok']), 'session cart rejects add exceeding cap');

echo json_encode([
    'ok' => $fail === 0,
    'variant_ids' => ['a' => $vA, 'b' => $vB],
    'failures' => $fail,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($fail > 0) {
    exit(1);
}
