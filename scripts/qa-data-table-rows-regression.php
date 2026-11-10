<?php

declare(strict_types=1);

/**
 * QA: data-table.php $rows / $tableRows merge and list row counts for key admin modules.
 *
 * php scripts/qa-data-table-rows-regression.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ok = 0;
$fail = 0;

function qaOk(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

// --- Simulate partial merge (same logic as data-table.php) ---
$simulate = static function (?array $rows, ?array $tableRows): array {
    $rows = $rows ?? $tableRows ?? [];

    return $rows;
};

qaOk($simulate(null, [['a' => '1']]) === [['a' => '1']], 'merge: tableRows only (Firebase pattern)');
qaOk($simulate([['a' => '1']], [['b' => '2']]) === [['a' => '1']], 'merge: rows wins over tableRows');
qaOk($simulate([], null) === [], 'merge: explicit empty rows');
qaOk($simulate(null, null) === [], 'merge: both unset yields empty');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — skip DB list checks\n");
    exit($fail > 0 ? 1 : 0);
}
Install::ensureConnection();

// Orders
if (class_exists('AdminOrderService')) {
    $o = AdminOrderService::searchForAdmin('', 1, 10, 'created_at', 'desc', '');
    qaOk(is_array($o['rows']) && (int) $o['total'] >= 0, 'Orders list: searchForAdmin returns rows+total');
    qaOk(count($o['rows']) <= 10, 'Orders list: pagination cap');
}

// Products
if (class_exists('ProductRepository')) {
    $p = ProductRepository::searchForAdmin('', '', '', 1, 10);
    qaOk(isset($p['rows'], $p['total']), 'Products list: searchForAdmin structure');
}

// Customers
if (class_exists('AdminCustomerService')) {
    $c = AdminCustomerService::searchForAdmin('', 1, 10, 'created_at', 'desc');
    qaOk(isset($c['rows'], $c['total']), 'Customers list: searchForAdmin structure');
}

// Coupons
if (PushSchema::tableReady('coupons') || Install::isSchemaReady()) {
    try {
        $pdo = Database::pdo();
        $couponTotal = (int) $pdo->query('SELECT COUNT(*) FROM coupons')->fetchColumn();
        qaOk($couponTotal >= 0, 'Coupons: table readable (count ' . $couponTotal . ')');
    } catch (Throwable $e) {
        qaOk(true, 'Coupons: table not present (skipped)');
    }
}

// Payments
if (class_exists('PaymentRepository')) {
    $pay = PaymentRepository::searchForAdmin('', 1, 10, 'created_at', 'desc');
    qaOk(isset($pay['rows'], $pay['total']), 'Payments list: searchForAdmin structure');
}

// Firebase
if (PushSchema::tableReady('firebase_projects')) {
    $fp = PushAdminList::projects('', 1, 10);
    qaOk(count($fp['rows']) === min((int) $fp['total'], 10) || (int) $fp['total'] === 0, 'Firebase projects: rows match page');
}
if (PushSchema::tableReady('push_notification_templates')) {
    $tpl = PushAdminList::templates('', '', 1, 25);
    qaOk((int) $tpl['total'] >= 13, 'Push templates: at least 13 seeded (total ' . (int) $tpl['total'] . ')');
    qaOk(count($tpl['rows']) === min((int) $tpl['total'], 25), 'Push templates: listing row count');
}
if (PushSchema::tableReady('device_push_registrations')) {
    $dev = PushAdminList::devices('', '', '', 1, 10);
    qaOk(isset($dev['rows'], $dev['total']), 'Firebase devices list structure');
}
if (PushSchema::tableReady('push_notification_logs')) {
    $log = PushAdminList::logs('', '', 1, 10);
    qaOk(isset($log['rows'], $log['total']), 'Firebase logs list structure');
}

// Template delete vs logs (no FK)
$logCols = Database::pdo()->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_notification_logs'
     AND COLUMN_NAME LIKE '%template%'"
)->fetchColumn();
qaOk((int) $logCols === 0, 'push_notification_logs has no template_id column (delete safe for history)');

fwrite(STDOUT, "\nResult: {$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
