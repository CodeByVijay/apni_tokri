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
Ajax::success(CouponOfferService::cartAjaxPayload($totals), $result['message'] ?? 'Coupon applied.');
