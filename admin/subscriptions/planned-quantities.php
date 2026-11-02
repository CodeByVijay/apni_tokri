<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d', strtotime('+7 days'));

admin_layout_start(['pageTitle' => 'Planned quantities', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Aggregated planned quantities';
$pageDescription = 'Sum of basket line quantities by delivery date (confirmed, draft and suggested).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="get" class="flex flex-wrap gap-3 items-end mb-4">
    <div><label class="at-label">From</label><input type="date" class="at-input at-focusable" name="from" value="<?= Security::escape($from) ?>"></div>
    <div><label class="at-label">To</label><input type="date" class="at-input at-focusable" name="to" value="<?= Security::escape($to) ?>"></div>
    <button type="submit" class="at-btn at-btn--secondary at-focusable">Run report</button>
</form>
<?php
$rows = [];
if (Install::isSchemaReady()) {
    foreach (SubscriptionBasketService::plannedQuantityReport($from, $to) as $r) {
        $rows[] = [
            'date' => (string) $r['delivery_date'],
            'product' => (string) $r['product_name'] . ' / ' . $r['variant_name'],
            'qty' => (string) $r['total_qty'] . ' ' . ($r['unit_label'] ?? ''),
            'value' => catalogue_format_price((float) ($r['total_inr'] ?? 0)),
        ];
    }
}
$columns = [
    ['label' => 'Delivery date', 'key' => 'date'],
    ['label' => 'Item', 'key' => 'product'],
    ['label' => 'Planned qty', 'key' => 'qty', 'align' => 'num'],
    ['label' => 'Line value', 'key' => 'value', 'align' => 'num'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
