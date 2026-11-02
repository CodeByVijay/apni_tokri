<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d', strtotime('+14 days'));

admin_layout_start(['pageTitle' => 'Customer baskets', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Customer daily baskets';
$pageDescription = 'Suggested vs customized vs confirmed baskets for prep planning.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="get" class="flex flex-wrap gap-3 items-end mb-4">
    <div><label class="at-label">From</label><input type="date" class="at-input at-focusable" name="from" value="<?= Security::escape($from) ?>"></div>
    <div><label class="at-label">To</label><input type="date" class="at-input at-focusable" name="to" value="<?= Security::escape($to) ?>"></div>
    <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
</form>
<?php
$rows = [];
if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $stmt = Database::pdo()->prepare(
        'SELECT cs.subscription_number, c.mobile, d.delivery_date, d.basket_status, b.status AS basket_row_status,
                b.total_inr, b.allowance_inr, b.source_type
         FROM subscription_delivery_days d
         INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
         INNER JOIN customers c ON c.id = cs.customer_id
         LEFT JOIN subscription_delivery_baskets b ON b.subscription_delivery_day_id = d.id
         WHERE d.delivery_date BETWEEN ? AND ?
         ORDER BY d.delivery_date, cs.subscription_number'
    );
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'date' => (string) $r['delivery_date'],
            'customer' => (string) $r['mobile'],
            'sub' => (string) $r['subscription_number'],
            'mode' => (string) ($r['source_type'] ?? '—'),
            'status' => (string) ($r['basket_row_status'] ?? $r['basket_status'] ?? 'none'),
            'total' => catalogue_format_price((float) ($r['total_inr'] ?? 0)),
            'allowance' => catalogue_format_price((float) ($r['allowance_inr'] ?? 0)),
        ];
    }
}
$columns = [
    ['label' => 'Date', 'key' => 'date'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Subscription', 'key' => 'sub'],
    ['label' => 'Source', 'key' => 'mode'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Total', 'key' => 'total', 'align' => 'num'],
    ['label' => 'Allowance', 'key' => 'allowance'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
