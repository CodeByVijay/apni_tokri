<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $useDb) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $typeId = (int) ($_POST['id'] ?? 0);
    if ($action === 'activate' && $typeId > 0) {
        $res = ProductTypeRepository::activate($typeId);
        admin_flash_set($res['message'], $res['ok'] ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/products/product-types.php', true, 302);
        exit;
    }
    if ($action === 'deactivate' && $typeId > 0) {
        $reassign = isset($_POST['reassign_to_code']) ? (string) $_POST['reassign_to_code'] : '';
        $res = ProductTypeRepository::deactivate($typeId, $reassign !== '' ? $reassign : null);
        admin_flash_set($res['message'], $res['ok'] ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/products/product-types.php', true, 302);
        exit;
    }
}

admin_layout_start(['pageTitle' => 'Product types', 'activeAdminNav' => 'products']);
$breadcrumbs = [
    ['label' => 'Products', 'href' => url_base() . '/admin/products/index.php'],
    ['label' => 'Product types'],
];
$pageHeading = 'Product types';
$pageDescription = 'Labels for the product type field on each product. Codes are stored on products; deactivating requires reassigning products when any use the type.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/products/product-type-edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add type</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!$useDb || !ProductTypeRepository::tableReady()): ?>
    <div class="at-alert at-alert--warning">Product types require database migration 1.26.0. Run pending migrations from Settings → System or import the latest schema.</div>
<?php
    admin_layout_end();
    exit;
endif;

$rows = ProductTypeRepository::allForAdmin();
$activeOptions = array_values(array_filter($rows, static function (array $r): bool {
    return !empty($r['is_active']);
}));
?>
<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Sort</th>
                <th>Products</th>
                <th>Status</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $tid = (int) $r['id'];
            $code = (string) $r['code'];
            $count = ProductTypeRepository::productCountByCode($code);
            ?>
            <tr>
                <td><?= Security::escape((string) $r['name']) ?></td>
                <td><code><?= Security::escape($code) ?></code></td>
                <td><?= (int) ($r['sort_order'] ?? 0) ?></td>
                <td><?= (int) $count ?></td>
                <td><?= !empty($r['is_active']) ? 'Active' : 'Inactive' ?></td>
                <td class="text-right space-x-2">
                    <a href="<?= Security::escape(url_base()) ?>/admin/products/product-type-edit.php?id=<?= $tid ?>" class="at-btn at-btn--ghost at-btn--xs at-focusable">Edit</a>
                    <?php if (!empty($r['is_active'])): ?>
                        <form method="post" class="inline-block align-middle" onsubmit="return confirm('Deactivate this product type?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="id" value="<?= $tid ?>">
                            <?php if ($count > 0): ?>
                                <select name="reassign_to_code" class="at-select at-select--xs at-focusable" required title="Reassign products to">
                                    <option value="">Reassign products to…</option>
                                    <?php foreach ($activeOptions as $opt):
                                        if ((string) $opt['code'] === $code) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?= Security::escape((string) $opt['code']) ?>"><?= Security::escape((string) $opt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <button type="submit" class="at-btn at-btn--ghost at-btn--xs at-focusable">Deactivate</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="inline-block">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="id" value="<?= $tid ?>">
                            <button type="submit" class="at-btn at-btn--ghost at-btn--xs at-focusable">Activate</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php admin_layout_end(); ?>
