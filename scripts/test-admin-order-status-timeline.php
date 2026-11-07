<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$fail = 0;

$recon = AdminOrderStatusTimeline::present([[
    'status' => 'Payment reconciliation required',
    'note' => 'Gateway captured ₹498.00 but order fulfilment could not be completed (slot): No capacity',
    'created_at' => '2026-11-07 22:10:00',
]]);
ok($recon[0]['tone'] === AdminOrderStatusTimeline::TONE_FAILURE, 'Open reconciliation is failure tone');
ok($recon[0]['title'] === 'Payment reconciliation required', 'Reconciliation title preserved');

$resolved = AdminOrderStatusTimeline::present([[
    'status' => 'Exception — manual fulfilment',
    'note' => 'Admin slot reassignment confirmed',
    'created_at' => '2026-11-07 23:00:00',
]]);
ok($resolved[0]['tone'] === AdminOrderStatusTimeline::TONE_RESOLVED, 'Resolved exception uses muted resolved tone');
ok(!str_contains($resolved[0]['title'], 'Order Placed'), 'No spurious Order Placed title');

$placed = AdminOrderStatusTimeline::present([[
    'status' => 'Order placed',
    'note' => 'Fulfilment completed after payment reconciliation (slot 2026-11-08, admin).',
    'created_at' => '2026-11-07 23:05:00',
]]);
ok(str_contains($placed[0]['title'], 'Fulfilment completed'), 'Generic Order placed uses note for title');

$paid = AdminOrderStatusTimeline::present([[
    'status' => 'Order placed',
    'note' => 'Payment verified server-side',
    'created_at' => '2026-11-07 10:00:00',
], [
    'status' => 'preparing',
    'note' => 'Automated admin orders test',
    'created_at' => '2026-11-07 11:00:00',
    'admin_name' => 'Demo Admin',
]]);
ok($paid[0]['title'] === 'Payment verified server-side', 'Payment verified note becomes title');
ok($paid[1]['title'] === 'Preparing order', 'Order status maps to readable title');
ok(!empty($paid[1]['is_latest']), 'Latest event flagged');
ok(str_contains($paid[1]['description'], 'Demo Admin'), 'Admin attribution in description');

$partial = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/order-status-timeline.php');
ok(str_contains($partial, 'at-admin-order-timeline'), 'Timeline partial markup');
$view = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/orders/view.php');
ok(str_contains($view, 'order-status-timeline.php'), 'Order view loads timeline partial');
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
ok(str_contains($css, '.at-admin-order-timeline'), 'Timeline styles in design system');

echo $fail === 0 ? "PASS: admin order status timeline.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
