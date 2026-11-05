<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$result = CartCouponHandler::process($_POST);
if (!$result['ok']) {
    Ajax::error($result['message'] ?? 'Coupon could not be applied.');
}

$totals = $result['totals'] ?? DemoCart::totals();
$data = ['totals' => $totals];
try {
    $offers = CouponOfferService::forCurrentCart($totals);
    ob_start();
    require APNI_TOKRI_ROOT . '/includes/partials/cart-offers.php';
    $data['offers_html'] = (string) ob_get_clean();
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('cart coupon offers refresh: ' . get_class($e));
}

Ajax::success($data, $result['message'] ?? 'Coupon applied.');
