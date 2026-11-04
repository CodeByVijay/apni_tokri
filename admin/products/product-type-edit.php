<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$type = ($useDb && $id && ProductTypeRepository::tableReady()) ? ProductTypeRepository::find($id) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb || !ProductTypeRepository::tableReady()) {
        admin_flash_persistence_blocked('Product type saved (demo UI only).');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $result = ProductTypeRepository::save($postId, [
        'code' => $_POST['code'] ?? '',
        'name' => $_POST['name'] ?? '',
        'sort_order' => $_POST['sort_order'] ?? 0,
        'is_active' => $_POST['is_active'] ?? null,
        'reassign_to_code' => $_POST['reassign_to_code'] ?? '',
    ]);
    if ($result['ok']) {
        admin_flash_set('Product type saved.');
        header('Location: ' . url_base() . '/admin/products/product-type-edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
    $errors = $result['errors'] ?? ['form' => $result['message'] ?? 'Save failed.'];
}

$defaults = [
    'code' => '',
    'name' => '',
    'sort_order' => 0,
    'is_active' => 1,
];
if ($type) {
    $defaults = array_merge($defaults, $type);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaults['code'] = trim((string) ($_POST['code'] ?? ''));
    $defaults['name'] = trim((string) ($_POST['name'] ?? ''));
    $defaults['sort_order'] = (int) ($_POST['sort_order'] ?? 0);
    $defaults['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
}

$productCount = ($type && $useDb) ? ProductTypeRepository::productCountByCode((string) $type['code']) : 0;
$reassignOptions = [];
if ($useDb && ProductTypeRepository::tableReady()) {
    foreach (ProductTypeRepository::allForAdmin() as $r) {
        if (empty($r['is_active'])) {
            continue;
        }
        if ($type && (string) $r['code'] === (string) $type['code']) {
            continue;
        }
        $reassignOptions[] = $r;
    }
}

admin_layout_start(['pageTitle' => $type ? 'Edit product type' : 'Add product type', 'activeAdminNav' => 'products']);
$breadcrumbs = [
    ['label' => 'Products', 'href' => url_base() . '/admin/products/index.php'],
    ['label' => 'Product types', 'href' => url_base() . '/admin/products/product-types.php'],
    ['label' => $type ? 'Edit' : 'Add'],
];
$pageHeading = $type ? 'Edit product type' : 'Add product type';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($errors !== []) {
    echo '<div class="at-alert at-alert--error mb-4">' . Security::escape(implode(' ', $errors)) . '</div>';
}
?>
<form method="post" class="at-admin-card max-w-xl">
    <div class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($type): ?><input type="hidden" name="id" value="<?= (int) $type['id'] ?>"><?php endif; ?>
        <?php if (!$type): ?>
            <div>
                <label class="at-label" for="code">Code</label>
                <input id="code" name="code" class="at-input at-focusable" value="<?= Security::escape((string) $defaults['code']) ?>" pattern="[a-z][a-z0-9_]{0,31}" required placeholder="e.g. fresh">
                <p class="text-sm at-muted mt-1">Lowercase letters, numbers, underscores. Cannot be changed after creation.</p>
            </div>
        <?php else: ?>
            <p class="text-sm">Code: <code><?= Security::escape((string) $type['code']) ?></code></p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="name">Display name</label>
            <input id="name" name="name" class="at-input at-focusable" value="<?= Security::escape((string) $defaults['name']) ?>" required>
        </div>
        <div>
            <label class="at-label" for="sort_order">Sort order</label>
            <input id="sort_order" name="sort_order" type="number" class="at-input at-focusable" value="<?= (int) ($defaults['sort_order'] ?? 0) ?>">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1"<?= !empty($defaults['is_active']) ? ' checked' : '' ?>>
            Active (shown on add product form)
        </label>
        <?php if ($type && $productCount > 0 && !empty($defaults['is_active'])): ?>
            <p class="text-sm at-muted"><?= (int) $productCount ?> product(s) use this type. Unchecking Active requires choosing a replacement type below.</p>
            <div>
                <label class="at-label" for="reassign_to_code">If deactivating, reassign products to</label>
                <select id="reassign_to_code" name="reassign_to_code" class="at-select at-focusable">
                    <option value="">— Select when saving as inactive —</option>
                    <?php foreach ($reassignOptions as $opt): ?>
                        <option value="<?= Security::escape((string) $opt['code']) ?>"><?= Security::escape((string) $opt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/products/product-types.php" class="at-btn at-btn--ghost at-focusable">Back</a>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
