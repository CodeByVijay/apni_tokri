<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('inventory');

$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        $reason = (string) ($_POST['reason'] ?? '');
        $result = InventoryService::adjust($variantId, $qty, $reason);
        if ($result['ok']) {
            CatalogueGateway::reset();
            admin_flash_set('Stock updated. New quantity: ' . (int) ($result['qty_after'] ?? 0) . '.');
        } else {
            admin_flash_set($result['message'] ?? 'Adjustment failed.', 'error');
        }
        header('Location: ' . url_base() . '/admin/inventory/index.php', true, 302);
        exit;
    }
    admin_flash_set('Stock adjusted (demo UI only).');
    header('Location: ' . url_base() . '/admin/inventory/index.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Inventory', 'activeAdminNav' => 'inventory']);
admin_flash_render();
$pageHeading = 'Inventory & stock movements';
$pageDescription = 'Adjustments are logged with quantity before/after. Row locks prevent negative stock.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$variants = $useDb ? InventoryService::variantsForSelect() : [];
$movements = $useDb ? InventoryService::recentMovements(30) : [];
?>
<div class="grid lg:grid-cols-3 gap-4">
    <form method="post" class="at-admin-card lg:col-span-1">
        <div class="at-admin-card__body space-y-3">
            <h3 class="font-bold">Adjust stock</h3>
            <?= Csrf::field() ?>
            <div>
                <label class="at-label" for="variant_id">Product / variant</label>
                <select id="variant_id" name="variant_id" class="at-select at-focusable" required<?= !$useDb ? ' disabled' : '' ?>>
                    <?php if ($useDb):
                        foreach ($variants as $v): ?>
                            <option value="<?= (int) $v['id'] ?>"><?= Security::escape($v['product_name'] . ' · ' . $v['name'] . ' (stock ' . $v['stock_qty'] . ')') ?></option>
                        <?php endforeach;
                    else: ?>
                        <option>Tomatoes · 1 kg</option>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label class="at-label" for="qty">Quantity change</label>
                <input id="qty" name="qty" type="number" class="at-input at-focusable" placeholder="+10 or −2" required>
                <p class="at-field-hint">Use negative numbers to reduce stock.</p>
            </div>
            <div>
                <label class="at-label" for="reason">Reason</label>
                <input id="reason" name="reason" class="at-input at-focusable" required placeholder="Purchase, wastage, count correction…">
            </div>
            <button type="submit" class="at-btn at-btn--primary at-focusable at-btn--block">Apply adjustment</button>
        </div>
    </form>
    <div class="lg:col-span-2">
        <?php
        $columns = [
            ['label' => 'When', 'key' => 'when'],
            ['label' => 'SKU', 'key' => 'sku'],
            ['label' => 'Change', 'key' => 'change', 'align' => 'num'],
            ['label' => 'After', 'key' => 'after', 'align' => 'num'],
            ['label' => 'Reason', 'key' => 'reason'],
        ];
        $rows = [];
        if ($useDb) {
            foreach ($movements as $m) {
                $chg = (int) $m['change_qty'];
                $rows[] = [
                    'when' => date('d M H:i', strtotime((string) $m['created_at'])),
                    'sku' => (string) ($m['sku'] ?? $m['variant_name']),
                    'change' => ($chg > 0 ? '+' : '') . $chg,
                    'after' => (string) $m['qty_after'],
                    'reason' => (string) $m['reason'],
                ];
            }
        } else {
            $rows = [
                ['when' => 'Today 10:02', 'sku' => 'CUT-SAL-250', 'change' => '−2', 'after' => '4', 'reason' => 'Demo'],
            ];
        }
        require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
        ?>
    </div>
</div>
<?php admin_layout_end(); ?>
