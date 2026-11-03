<?php

declare(strict_types=1);

/**
 * Phase 2.4 — admin listing audit regression (server-side search/pagination wiring).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok24(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$checks = [
    'admin/deliveries/index.php' => ['DeliveryAssignmentService::searchForAdmin', 'AdminServerList::', 'listForAdmin($filters, 200)'],
    'admin/refunds/index.php' => ['RefundRequestService::searchForAdmin', 'admin_list_pagination'],
    'admin/support/index.php' => ['SupportTicketService::searchForAdmin', "'key' => 'actions'"],
    'admin/reviews/index.php' => ['ReviewService::searchPendingForAdmin', 'AdminServerList::'],
    'admin/inventory/index.php' => ['InventoryService::searchMovementsForAdmin', 'admin_list_pagination'],
    'admin/subscriptions/deliveries.php' => ['SubscriptionFulfilmentService::searchUpcomingForAdmin', 'admin_list_pagination', 'adminUpcoming(150)'],
    'admin/subscriptions/customer-baskets.php' => ['SubscriptionAdminListService::searchCustomerBaskets', 'AdminServerList::', 'fetchAll(PDO::FETCH_ASSOC)'],
];

foreach ($checks as $file => $spec) {
    $need = $spec[0];
    $need2 = $spec[1];
    $forbidden = $spec[2] ?? null;
    $path = APNI_TOKRI_ROOT . '/' . $file;
    $src = is_readable($path) ? (string) file_get_contents($path) : '';
    ok24($src !== '' && str_contains($src, $need), $file . ' uses ' . $need);
    ok24(str_contains($src, $need2), $file . ' uses ' . $need2);
    if ($forbidden !== null) {
        ok24(!str_contains($src, $forbidden), $file . ' does not call ' . $forbidden);
    }
}

ok24(method_exists(DeliveryAssignmentService::class, 'searchForAdmin'), 'DeliveryAssignmentService::searchForAdmin');
ok24(method_exists(RefundRequestService::class, 'searchForAdmin'), 'RefundRequestService::searchForAdmin');
ok24(method_exists(SupportTicketService::class, 'searchForAdmin'), 'SupportTicketService::searchForAdmin');
ok24(method_exists(ReviewService::class, 'searchPendingForAdmin'), 'ReviewService::searchPendingForAdmin');
ok24(method_exists(InventoryService::class, 'searchMovementsForAdmin'), 'InventoryService::searchMovementsForAdmin');
ok24(method_exists(SubscriptionFulfilmentService::class, 'searchUpcomingForAdmin'), 'SubscriptionFulfilmentService::searchUpcomingForAdmin');
ok24(class_exists(SubscriptionAdminListService::class), 'SubscriptionAdminListService loaded');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $d = DeliveryAssignmentService::searchForAdmin('', ['active_only' => true], 1, 10);
    ok24(isset($d['total'], $d['rows'], $d['total_pages']), 'Delivery search structure');
    $r = RefundRequestService::searchForAdmin('', null, 1, 10);
    ok24(isset($r['total'], $r['rows']), 'Refund search structure');
    $m = InventoryService::searchMovementsForAdmin('', 1, 5);
    ok24(isset($m['total'], $m['rows']), 'Inventory movement search structure');
}

$matrix = APNI_TOKRI_ROOT . '/docs/admin-listing-audit-phase24.md';
ok24(is_readable($matrix) && str_contains((string) file_get_contents($matrix), 'Phase 2.4'), 'Audit matrix document present');

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
