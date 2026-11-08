<?php

declare(strict_types=1);

/**
 * Admin orders list pagination + search (rolled back fixtures when schema available).
 * CLI: php scripts/test-admin-orders-pagination.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okO(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$index = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/orders/index.php');
$pager = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php');
okO(str_contains($index, 'AdminOrderService::PER_PAGE_OPTIONS'), 'orders index uses order-specific page sizes');
okO(str_contains($index, 'perPageFromRequestWithOptions'), 'orders index validates per_page against 20/50/100');
okO(str_contains($index, 'admin_list_pagination(') && !preg_match('/total_pages\s*>\s*1/', $index), 'pagination always rendered for orders');
okO(str_contains($index, 'name="page" value="1"'), 'search form resets to page 1');
okO(str_contains($pager, 'Showing'), 'pager shows X–Y of Z range');
okO(str_contains($pager, 'aria-current="page"'), 'pager highlights current page number');
okO(str_contains($pager, 'Previous') && str_contains($pager, 'Next'), 'pager has prev/next controls');

okO(AdminOrderService::PER_PAGE === 20, 'default page size is 20');
okO(AdminOrderService::PER_PAGE_OPTIONS === [20, 50, 100], 'page size options 20/50/100');

if (!Install::isSchemaReady()) {
    echo "SKIP: schema not ready (DB pagination assertions skipped).\n";
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();

$totalEligible = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
okO($totalEligible >= 0, 'eligible order count from DB: ' . $totalEligible);

$unfiltered = AdminOrderService::searchForAdmin('', 1, 20);
okO($unfiltered['total'] === $totalEligible, 'unfiltered total matches COUNT(orders)');
okO(count($unfiltered['rows']) <= 20, 'first page returns at most 20 rows');
okO($unfiltered['per_page'] === 20, 'default per_page in service response is 20');

if ($totalEligible > 20) {
    $page2 = AdminOrderService::searchForAdmin('', 2, 20);
    okO($page2['page'] === 2, 'page 2 resolves correctly');
    okO(count($page2['rows']) <= 20, 'page 2 at most 20 rows');
    $ids1 = array_column($unfiltered['rows'], 'id');
    $ids2 = array_column($page2['rows'], 'id');
    okO($ids1 !== [] && $ids2 !== [] && count(array_intersect($ids1, $ids2)) === 0, 'pages 1 and 2 do not overlap');
}

$beyond = AdminOrderService::searchForAdmin('', 99999, 20);
okO($beyond['page'] === max(1, (int) $beyond['total_pages']), 'invalid high page clamps to last page');

$tag = 'ADMO-' . substr(bin2hex(random_bytes(3)), 0, 6);
$pdo->beginTransaction();
try {
    $cid = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($cid <= 0) {
        $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(['9000000099', 'Pager Test']);
        $cid = (int) $pdo->lastInsertId();
    }

    $insertedNumbers = [];
    for ($i = 0; $i < 25; $i++) {
        $num = $tag . '-' . sprintf('%02d', $i);
        $pdo->prepare(
            "INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status, created_at)
             VALUES (?, ?, 'pending_payment', 10, 10, 'pending', DATE_SUB(NOW(), INTERVAL ? MINUTE))"
        )->execute([$num, $cid, $i]);
        $insertedNumbers[] = $num;
    }

    $fixtureTotal = AdminOrderService::searchForAdmin($tag, 1, 20);
    okO($fixtureTotal['total'] === 25, 'search total matches 25 inserted fixture orders');
    okO(count($fixtureTotal['rows']) === 20, 'search page 1 returns 20 of 25');

    $fixtureP2 = AdminOrderService::searchForAdmin($tag, 2, 20);
    okO(count($fixtureP2['rows']) === 5, 'search page 2 returns remaining 5');
    $deepNum = $insertedNumbers[24];
    $deepHit = AdminOrderService::searchForAdmin($deepNum, 1, 20);
    okO($deepHit['total'] === 1 && ($deepHit['rows'][0]['id'] ?? '') === $deepNum, 'search finds order beyond page 1');

    $statusHit = AdminOrderService::searchForAdmin('pending payment', 1, 50);
    okO($statusHit['total'] >= 25, 'status search matches underscore status values');

    $empty = AdminOrderService::searchForAdmin($tag . '-ZZZZZ', 1, 20);
    okO($empty['total'] === 0 && $empty['rows'] === [], 'empty search returns zero rows');

    $pdo->rollBack();
    okO(true, 'fixture orders rolled back (no persistent test data)');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    okO(false, 'fixture block: ' . $e->getMessage());
}

echo $fail > 0 ? "FAILURES: $fail\n" : "All admin orders pagination checks passed.\n";
exit($fail > 0 ? 1 : 0);
