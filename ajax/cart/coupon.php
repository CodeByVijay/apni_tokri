<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$code = isset($_POST['coupon_code']) && is_string($_POST['coupon_code']) ? $_POST['coupon_code'] : '';
$result = DemoCart::applyCoupon($code);
if (!$result['ok']) {
    Ajax::error($result['message']);
}

Ajax::success(['totals' => $result['totals'] ?? DemoCart::totals()], $result['message']);
