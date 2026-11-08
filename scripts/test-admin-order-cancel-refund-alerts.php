<?php

declare(strict_types=1);

/**
 * Static + optional DB checks for cancel → refund request → admin alert wiring.
 * CLI: php scripts/test-admin-order-cancel-refund-alerts.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function oka(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$cancelPhp = (string) file_get_contents($root . '/includes/commerce/order_cancellation.php');
oka(str_contains($cancelPhp, 'notifyOrderCancellationRefundRequired'), 'Cancellation calls admin refund notification');
oka(str_contains($cancelPhp, 'createRefundRequestIfPaidOnline'), 'Cancellation auto refund request helper');
oka(str_contains($cancelPhp, 'availableRefundAmountForOrder'), 'Uses available refund balance');

$adminNotif = (string) file_get_contents($root . '/includes/notifications/admin_notifications.php');
oka(str_contains($adminNotif, 'EVENT_ORDER_CANCEL_REFUND'), 'Admin notification event type defined');
oka(method_exists(AdminNotificationService::class, 'notifyOrderCancellationRefundRequired'), 'notifyOrderCancellationRefundRequired exists');

$refundPhp = (string) file_get_contents($root . '/includes/trust/refund.php');
oka(str_contains($refundPhp, 'adminCancellationRefundDashboardCounts'), 'Dashboard counts helper');
oka(str_contains($refundPhp, 'adminOrderCancellationRefundBlock'), 'Order detail block helper');

oka(str_contains((string) file_get_contents($root . '/admin/dashboard.php'), 'Cancellation &amp; refund overview'), 'Dashboard section');
oka(str_contains((string) file_get_contents($root . '/admin/orders/index.php'), 'refund_facet'), 'Orders listing refund facet');
oka(str_contains((string) file_get_contents($root . '/includes/partials/admin/order-detail-cancellation-refund.php'), 'Review &amp; Approve Refund'), 'Order detail approval link');

oka(method_exists(AdminOrderService::class, 'refundFacetLabels'), 'AdminOrderService refund facets');

if (Install::isSchemaReady()) {
    $counts = RefundRequestService::adminCancellationRefundDashboardCounts();
    oka(isset($counts['approval_required']), 'Dashboard counts structure');
}

echo $fail === 0 ? "PASS: admin order cancel refund alerts (static).\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
