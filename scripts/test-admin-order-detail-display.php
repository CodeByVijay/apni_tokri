<?php

declare(strict_types=1);

/**
 * Admin order detail UI regression (static).
 * CLI: php scripts/test-admin-order-detail-display.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$view = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/orders/view.php');
$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/admin_order_detail.php');

$ok(str_contains($view, 'order-detail-overview.php'), 'view includes overview partial');
$ok(str_contains($view, 'order-detail-customer.php'), 'view includes customer partial');
$ok(str_contains($view, 'order-detail-payments.php'), 'view includes payments partial');
$ok(str_contains($view, 'order-detail-timeline.php'), 'view includes activity timeline');
$ok(str_contains($view, 'order-detail-aside.php'), 'view includes sidebar actions');
$aside = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/order-detail-aside.php');
$ok(str_contains($aside, 'id="admin-order-update-status"'), 'status update form preserved');
$ok(str_contains($aside, 'id="admin-order-reschedule-delivery"'), 'reschedule form preserved');
$ok(str_contains($view, 'refresh_cashfree_payment'), 'Cashfree refresh preserved');
$ok(str_contains($view, 'recover_payment_fulfilment'), 'payment recovery preserved');

$ok(str_contains($svc, 'AdminOrderDetailService'), 'admin order detail service exists');
$ok(str_contains($svc, 'RefundRequestService::displayAmountInr'), 'refund amounts use display helper');
$ok(str_contains($svc, 'CustomerOrderDetailService::addressBlock'), 'address snapshot reuse');

$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
$ok(str_contains($css, '.at-admin-od-kv-grid'), 'admin order detail kv grid CSS');

exit($fail > 0 ? 1 : 0);
