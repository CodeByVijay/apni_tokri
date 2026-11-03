<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('inventory');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'sku' => 'sku', 'change_qty' => 'change_qty',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

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
    admin_flash_persistence_blocked('Stock adjusted (demo UI only).');
    header('Location: ' . url_base() . '/admin/inventory/index.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Inventory', 'activeAdminNav' => 'inventory']);
$pageHeading = 'Inventory & stock movements';
$pageDescription = 'Adjustments are logged with quantity before/after. Row locks prevent negative stock.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$variants = $useDb ? InventoryService::variantsForSelect() : [];
$movementList = $useDb
    ? InventoryService::searchMovementsForAdmin($listQ, $listPage, $listPerPage, $sort, $dir)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
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
        <?php if ($useDb): ?>
        <form method="get" class="mb-3 flex flex-wrap gap-2 items-end">
            <div class="flex-1 min-w-[10rem]">
                <label class="at-label" for="mov-q">Search movements</label>
                <input id="mov-q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="SKU, product, reason">
            </div>
            <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
            <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Search</button>
        </form>
        <?php endif;
        $baseMov = url_base() . '/admin/inventory/index.php';
        $columns = [
            ['label' => 'When', 'label_html' => AdminServerList::sortHeaderLink('created_at', 'When', $sort, $dir, $baseMov, $queryBase), 'key' => 'when'],
            ['label' => 'SKU', 'label_html' => AdminServerList::sortHeaderLink('sku', 'SKU', $sort, $dir, $baseMov, $queryBase), 'key' => 'sku'],
            ['label' => 'Change', 'label_html' => AdminServerList::sortHeaderLink('change_qty', 'Change', $sort, $dir, $baseMov, $queryBase), 'key' => 'change', 'align' => 'num'],
            ['label' => 'After', 'key' => 'after', 'align' => 'num'],
            ['label' => 'Reason', 'key' => 'reason'],
        ];
        $rows = [];
        if ($useDb) {
            foreach ($movementList['rows'] as $m) {
                $chg = (int) $m['change_qty'];
                $rows[] = [
                    'when' => date('d M H:i', strtotime((string) $m['created_at'])),
                    'sku' => (string) ($m['sku'] ?? $m['variant_name']),
                    'change' => ($chg > 0 ? '+' : '') . $chg,
                    'after' => (string) $m['qty_after'],
                    'reason' => (string) $m['reason'],
                ];
            }
        }
        if ($rows === [] && !$useDb): ?>
            <p class="px-5 py-4 text-sm at-muted">Connect the database to view stock movements.</p>
        <?php else:
        require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
        if ($useDb && (int) ($movementList['total_pages'] ?? 1) > 1) {
            admin_list_pagination((int) $movementList['page'], (int) $movementList['total'], $listPerPage, $queryBase, $baseMov);
        }
        endif;
        ?>
    </div>
</div>
<?php admin_layout_end(); ?>
