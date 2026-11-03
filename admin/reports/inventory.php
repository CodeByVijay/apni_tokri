<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/report-filters.php';

AdminDemoPerms::denyIfCannot('reports');

$parsed = ReportDateRange::fromRequest($_GET);
if (empty($parsed['ok'])) {
    admin_flash_set($parsed['message'] ?? 'Invalid filters.', 'error');
    header('Location: ' . url_base() . '/admin/reports/inventory.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$page = max(1, (int) ($_GET['page'] ?? 1));

$stock = Install::isSchemaReady() ? InventoryReportService::currentStock() : [];
$low = Install::isSchemaReady() ? InventoryReportService::lowStock() : [];
$out = Install::isSchemaReady() ? InventoryReportService::outOfStock() : [];
$movements = Install::isSchemaReady() ? InventoryReportService::movementSummary($range, $page) : ['totals' => [], 'rows' => [], 'total' => 0];

admin_layout_start(['pageTitle' => 'Inventory report', 'activeAdminNav' => 'reports']);
$pageHeading = 'Inventory & stock report';
$pageDescription = 'Live stock_qty from product_variants. No stock valuation (no cost data).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/inventory.php',
]);
?>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <?php admin_report_export_form('inventory_stock', $range); ?>
    <?php admin_report_export_form('inventory_movements', $range); ?>
</div>
<div class="grid md:grid-cols-3 gap-3 mb-6">
    <div class="at-card"><div class="at-card__body">
        <p class="font-semibold">Low stock (<?= count($low) ?>)</p>
        <ul class="text-xs mt-2 space-y-1 max-h-40 overflow-y-auto">
            <?php foreach (array_slice($low, 0, 8) as $r): ?>
                <li><?= Security::escape((string) $r['product_name']) ?> — <?= (int) $r['stock_qty'] ?></li>
            <?php endforeach; ?>
            <?php if ($low === []): ?><li class="at-muted">None</li><?php endif; ?>
        </ul>
    </div></div>
    <div class="at-card"><div class="at-card__body">
        <p class="font-semibold">Out of stock (<?= count($out) ?>)</p>
        <ul class="text-xs mt-2 space-y-1 max-h-40 overflow-y-auto">
            <?php foreach (array_slice($out, 0, 8) as $r): ?>
                <li><?= Security::escape((string) $r['product_name']) ?></li>
            <?php endforeach; ?>
            <?php if ($out === []): ?><li class="at-muted">None</li><?php endif; ?>
        </ul>
    </div></div>
    <div class="at-card"><div class="at-card__body">
        <p class="font-semibold">Movements (<?= Security::escape($range->label()) ?>)</p>
        <p class="text-sm mt-1">Received: <?= (int) ($movements['totals']['stock_received'] ?? 0) ?></p>
        <p class="text-sm">Deducted: <?= (int) ($movements['totals']['stock_deducted'] ?? 0) ?></p>
        <p class="text-sm">Admin adjustments (qty): <?= (int) ($movements['totals']['stock_adjustments_qty'] ?? 0) ?></p>
    </div></div>
</div>
<h2 class="text-lg font-semibold mb-2">Current stock</h2>
<div class="at-admin-table-wrap mb-6">
    <table class="at-admin-table">
        <thead><tr><th>Product</th><th>Variant</th><th>SKU</th><th class="is-num">Qty</th><th class="is-num">Low threshold</th></tr></thead>
        <tbody>
        <?php foreach ($stock as $r): ?>
            <tr>
                <td><?= Security::escape((string) $r['product_name']) ?></td>
                <td><?= Security::escape((string) $r['variant_name']) ?></td>
                <td class="is-id"><?= Security::escape((string) ($r['sku'] ?? '')) ?></td>
                <td class="is-num"><?= (int) $r['stock_qty'] ?></td>
                <td class="is-num"><?= (int) $r['low_stock_threshold'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($stock === []): ?><tr><td colspan="5" class="at-admin-table__empty">No published variants.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<h2 class="text-lg font-semibold mb-2">Movement history</h2>
<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead><tr><th>When</th><th>Product</th><th class="is-num">Change</th><th class="is-num">After</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($movements['rows'] as $m): ?>
            <tr>
                <td><?= Security::escape((string) $m['created_at']) ?></td>
                <td><?= Security::escape((string) $m['product_name']) ?> / <?= Security::escape((string) $m['variant_name']) ?></td>
                <td class="is-num"><?= (int) $m['change_qty'] ?></td>
                <td class="is-num"><?= (int) $m['qty_after'] ?></td>
                <td><?= Security::escape((string) $m['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (($movements['rows'] ?? []) === []): ?><tr><td colspan="5" class="at-admin-table__empty">No movements in range.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
