<?php

declare(strict_types=1);

/**
 * My orders list + mobile Account Hub regressions.
 * CLI: php scripts/test-my-orders-list-ui.php
 * Static markup checks always run; repository filter/count checks run when the schema is installed.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okOrd(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$src = (string) file_get_contents(APNI_TOKRI_ROOT . '/my-orders.php');
okOrd(!preg_match('/cancel=1/i', $src), 'Listing does not expose cancel=1 links');
okOrd(!preg_match('/OrderCancellationService/', $src), 'Listing does not call cancellation service');
okOrd(str_contains($src, 'data-at-order-card'), 'Order cards use data-at-order-card');
okOrd(str_contains($src, 'at-order-card__cta'), 'Desktop View details CTA retained');
okOrd(str_contains($src, 'at-order-card__chevron'), 'Mobile right chevron present');
okOrd(str_contains($src, 'chevron-right'), 'Chevron icon used');
okOrd(str_contains($src, 'at-order-card__link'), 'Stretched link makes whole card keyboard-accessible');
okOrd(str_contains($src, 'data-at-order-card-stop'), 'Invoice action stops card navigation');
okOrd(preg_match('/data-order-href="<\?= Security::escape\(\$detailUrl\) \?>"/', $src) === 1, 'Card data-order-href matches detail URL variable');
okOrd(preg_match('/href="<\?= Security::escape\(\$detailUrl\) \?>"/', $src) === 1, 'Card link href uses same detail URL');
okOrd(str_contains($src, 'customer_status_badge'), 'Status badges preserved');
okOrd(str_contains($src, 'Track deliveries, download invoices and view details.'), 'Page description per spec');
okOrd(str_contains($src, 'at-order-filters') && str_contains($src, 'aria-label="Filter orders by status"'), 'Status filter chips rendered');
foreach (['All', 'Processing', 'Confirmed', 'Delivered', 'Cancelled'] as $chipLabel) {
    okOrd(str_contains($src, "'" . $chipLabel . "'"), 'Filter chip: ' . $chipLabel);
}
okOrd(preg_match('/customer_account_pagination\(\s*\$orderList\[\'page\'\][\s\S]*?\$activeBucket/', $src) === 1, 'Pagination preserves status filter');
okOrd(str_contains($src, 'statusCountsForCustomer((int) CustomerAuth::id())'), 'Counts scoped to logged-in customer');
okOrd(str_contains($src, 'listForCustomer(CustomerAuth::id()'), 'Listing scoped to logged-in customer');

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/apni-tokri.js');
okOrd(str_contains($js, '[data-at-order-card]') && str_contains($js, 'data-at-order-card-stop'), 'JS handles card click without nested action duplication');

$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
okOrd(str_contains($css, 'at-account-nav--responsive'), 'Account nav responsive styles');
okOrd(str_contains($css, 'at-order-card__head'), 'Order card hierarchy styles');
okOrd(str_contains($css, '.at-order-filters'), 'Filter chip styles');
okOrd(str_contains($css, '.at-hub-grid') && str_contains($css, '.at-hub-desktop-only { display: none !important; }'), 'Hub styles; desktop-only blocks hidden on mobile');
okOrd(preg_match('/@media \(max-width: 767px\) \{\s*\.at-hub \{/', $css) === 1, 'Hub is scoped below 768px');
okOrd(str_contains($css, 'env(safe-area-inset-bottom'), 'Bottom safe-area spacing present');

$shell = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php');
okOrd(str_contains($shell, 'at-account-nav--responsive'), 'Account shell keeps responsive sidebar nav (desktop unchanged)');
okOrd(str_contains($shell, 'at-account-nav--overview'), 'Account shell flags overview nav for mobile hub');

$hub = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/account-hub.php');
okOrd(substr_count($hub, "'/my-") + substr_count($hub, "'/submit-review.php'") >= 8, 'Hub links to 8 account sections');
foreach (['Orders', 'Subscriptions', 'Addresses', 'Invoices', 'Refunds', 'Reviews', 'Profile', 'Support'] as $hubLabel) {
    okOrd(str_contains($hub, "'" . $hubLabel . "'"), 'Hub item: ' . $hubLabel);
}
okOrd(str_contains($hub, 'View All') && str_contains($hub, '/my-orders.php'), 'Recent orders View All -> /my-orders');
okOrd(str_contains($hub, 'at-hub-profile') && str_contains($hub, '/my-profile.php'), 'Profile card navigates to profile');
okOrd(!preg_match('/\b(demo|dummy|lorem)\b/i', $hub), 'Hub has no dummy data');

$acct = (string) file_get_contents(APNI_TOKRI_ROOT . '/my-account.php');
okOrd(str_contains($acct, 'account-hub.php'), 'my-account renders hub');
okOrd(str_contains($acct, 'OrderRepository::listForCustomer($customerId, 1, 2)'), 'Recent orders = latest 2 from repository');
okOrd(substr_count($acct, 'at-hub-desktop-only') >= 3, 'Desktop sections kept (hidden only on mobile)');

$footer = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/footer-public.php');
okOrd(str_contains($footer, "\$activeNav === 'account'"), 'Bottom nav highlights Account');

if (!Install::isSchemaReady()) {
    echo "SKIP: repository filter/count checks (schema not ready)\n";
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$mkCustomer = static function (string $suffix) use ($pdo): int {
    $mobile = '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO customers (mobile, name, created_at) VALUES (?, ?, NOW())')->execute([$mobile, 'MyOrders UI ' . $suffix]);

    return (int) $pdo->lastInsertId();
};
$mkOrder = static function (int $cid, string $number, string $status, string $payment) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, NOW())'
    )->execute([$number, $cid, $status, $payment]);
};

$cidA = $mkCustomer('A');
$cidB = $mkCustomer('B');
$numbers = [];
foreach ([
    ['PEND', 'pending_payment', 'pending'],
    ['CONF', 'confirmed', 'paid'],
    ['DELV', 'delivered', 'paid'],
    ['CANC', 'cancelled', 'paid'],
    ['FAIL', 'payment_failed', 'failed'],
] as [$k, $st, $pay]) {
    $n = 'UI-' . $tok . '-' . $k;
    $numbers[$k] = $n;
    $mkOrder($cidA, $n, $st, $pay);
}
$otherNum = 'UI-' . $tok . '-OTHER';
$mkOrder($cidB, $otherNum, 'confirmed', 'paid');

try {
    $counts = OrderRepository::statusCountsForCustomer($cidA);
    okOrd($counts['all'] === 5, 'Counts: all = 5');
    okOrd($counts['processing'] === 1, 'Counts: processing = 1');
    okOrd($counts['confirmed'] === 1, 'Counts: confirmed = 1');
    okOrd($counts['delivered'] === 1, 'Counts: delivered = 1');
    okOrd($counts['cancelled'] === 1, 'Counts: cancelled = 1');

    $ids = static function (array $list): array {
        return array_map(static fn ($r) => (string) $r['id'], $list['rows']);
    };
    okOrd($ids(OrderRepository::listForCustomer($cidA, 1, 20, 'processing')) === [$numbers['PEND']], 'Filter processing returns only processing order');
    okOrd($ids(OrderRepository::listForCustomer($cidA, 1, 20, 'confirmed')) === [$numbers['CONF']], 'Filter confirmed returns only confirmed order');
    okOrd($ids(OrderRepository::listForCustomer($cidA, 1, 20, 'delivered')) === [$numbers['DELV']], 'Filter delivered returns only delivered order');
    okOrd($ids(OrderRepository::listForCustomer($cidA, 1, 20, 'cancelled')) === [$numbers['CANC']], 'Filter cancelled returns only cancelled order');
    $all = $ids(OrderRepository::listForCustomer($cidA, 1, 20, null));
    okOrd(count($all) === 5 && !in_array($otherNum, $all, true), 'All lists own 5 orders and never another customer\'s');
    okOrd(count($ids(OrderRepository::listForCustomer($cidA, 1, 20, 'bogus'))) === 5, 'Unknown filter falls back to all');
    okOrd(OrderRepository::listForCustomer($cidA, 1, 20, 'confirmed')['total'] === 1, 'Filtered total drives pagination');
    okOrd(OrderRepository::listForCustomer($cidA, 1, 2, null)['total_pages'] === 3, 'Unfiltered pagination unchanged (5 orders / 2 per page = 3)');
    okOrd(OrderRepository::statusCountsForCustomer($cidB)['all'] === 1, 'Other customer counts are isolated');
    okOrd(OrderRepository::forCustomerByNumber($numbers['CONF'], $cidB) === null, 'Ownership check unchanged: other customer cannot load order');

    $detailCancelled = OrderRepository::forCustomerDetail($numbers['CANC'], $cidA);
    okOrd($detailCancelled !== null && ($detailCancelled['status'] ?? '') === 'cancelled', 'Detail: cancelled+paid shows cancelled badge status');
    $summaryCancelled = null;
    foreach (OrderRepository::listForCustomer($cidA, 1, 20, 'cancelled')['rows'] as $row) {
        if (($row['id'] ?? '') === $numbers['CANC']) {
            $summaryCancelled = $row;
            break;
        }
    }
    okOrd($summaryCancelled !== null && ($summaryCancelled['status'] ?? '') === 'cancelled', 'List card: cancelled+paid maps to cancelled');

    $cidC = $mkCustomer('C-refund');
    $refundDoneNum = 'UI-' . $tok . '-CANREF';
    $mkOrder($cidC, $refundDoneNum, 'cancelled', 'refunded');
    $countsC = OrderRepository::statusCountsForCustomer($cidC);
    okOrd($countsC['cancelled'] === 1 && $countsC['all'] === 1, 'Cancelled count with payment_status refunded');
    $pdo->prepare('DELETE FROM orders WHERE order_number = ?')->execute([$refundDoneNum]);
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cidC]);
} finally {
    $in = implode(',', array_fill(0, 6, '?'));
    $pdo->prepare('DELETE FROM orders WHERE order_number IN (' . $in . ')')->execute(array_merge(array_values($numbers), [$otherNum]));
    $pdo->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$cidA, $cidB]);
}

exit($fail > 0 ? 1 : 0);
