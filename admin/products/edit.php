<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$product = ($useDb && $id) ? ProductRepository::find($id) : null;
$variants = ($useDb && $id) ? ProductRepository::variants($id) : [];
$images = ($useDb && $id) ? ProductRepository::images($id) : [];
$attributes = ($useDb && $id) ? ProductRepository::attributes($id) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'save');

    if (!$useDb) {
        admin_flash_persistence_blocked('Product saved (demo UI only).');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }

    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

    if ($action === 'delete_image' && $postId) {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        ProductRepository::deleteImage($postId, $imageId);
        CatalogueGateway::reset();
        admin_flash_set('Image removed.');
        header('Location: ' . url_base() . '/admin/products/edit.php?id=' . $postId . '#images', true, 302);
        exit;
    }

    if ($action === 'upload_image' && $postId && isset($_FILES['image'])) {
        $upload = Upload::storeImage($_FILES['image'], 'products/' . $postId);
        if ($upload['ok'] && isset($upload['path'])) {
            ProductRepository::addImage($postId, $upload['path'], count($images) === 0);
            CatalogueGateway::reset();
            admin_flash_set('Image uploaded.');
        } else {
            admin_flash_set($upload['message'] ?? 'Upload failed.', 'error');
        }
        header('Location: ' . url_base() . '/admin/products/edit.php?id=' . $postId . '#images', true, 302);
        exit;
    }

    if ($action === 'save') {
        $catId = $_POST['category_id'] ?? '';
        $result = ProductRepository::saveGeneral($postId, [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'slug_manual' => !empty($_POST['slug_manual']),
            'sku' => $_POST['sku'] ?? '',
            'category_id' => $catId,
            'short_description' => $_POST['short_description'] ?? '',
            'description' => $_POST['description'] ?? '',
            'product_type' => $_POST['product_type'] ?? 'fresh',
            'status' => $_POST['status'] ?? 'draft',
            'is_featured' => $_POST['is_featured'] ?? null,
            'is_bestseller' => $_POST['is_bestseller'] ?? null,
            'is_new_arrival' => $_POST['is_new_arrival'] ?? null,
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'robots_index' => $_POST['robots_index'] ?? null,
        ]);
        if (!$result['ok']) {
            $errors = $result['errors'] ?? ['form' => $result['message'] ?? 'Save failed.'];
        } else {
            $savedId = (int) $result['id'];
            $variantRows = [];
            $vNames = $_POST['variant_name'] ?? [];
            $vIds = $_POST['variant_id'] ?? [];
            $vSkus = $_POST['variant_sku'] ?? [];
            $vPrices = $_POST['variant_price'] ?? [];
            $vSales = $_POST['variant_sale'] ?? [];
            $vStocks = $_POST['variant_stock'] ?? [];
            $vLows = $_POST['variant_low'] ?? [];
            if (is_array($vNames)) {
                foreach ($vNames as $i => $vname) {
                    $variantRows[] = [
                        'id' => $vIds[$i] ?? '',
                        'name' => $vname,
                        'sku' => $vSkus[$i] ?? '',
                        'price' => $vPrices[$i] ?? 0,
                        'sale_price' => $vSales[$i] ?? '',
                        'stock_qty' => $vStocks[$i] ?? 0,
                        'low_stock_threshold' => $vLows[$i] ?? 5,
                        'is_active' => 1,
                        'sort_order' => $i,
                    ];
                }
            }
            $vRes = ProductRepository::saveVariants($savedId, $variantRows);
            if (!$vRes['ok']) {
                admin_flash_set($vRes['message'] ?? 'Variant save failed.', 'error');
            }

            $attrs = [];
            $labels = $_POST['attr_label'] ?? [];
            $values = $_POST['attr_value'] ?? [];
            if (is_array($labels)) {
                foreach ($labels as $i => $label) {
                    $attrs[] = ['label' => $label, 'value' => $values[$i] ?? ''];
                }
            }
            ProductRepository::saveAttributes($savedId, $attrs);
            CatalogueGateway::reset();
            admin_flash_set('Product saved.');
            header('Location: ' . url_base() . '/admin/products/edit.php?id=' . $savedId, true, 302);
            exit;
        }
    }
}

$defaults = [
    'name' => '',
    'slug' => '',
    'sku' => 'VEG-TOM-001',
    'category_id' => '',
    'short_description' => '',
    'description' => '',
    'product_type' => 'fresh',
    'status' => 'draft',
    'is_featured' => 0,
    'is_bestseller' => 0,
    'is_new_arrival' => 0,
    'meta_title' => '',
    'meta_description' => '',
    'robots_index' => 1,
];
if ($product) {
    $defaults = array_merge($defaults, $product);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($errors !== [])) {
    $defaults['name'] = trim((string) ($_POST['name'] ?? ''));
    $defaults['slug'] = trim((string) ($_POST['slug'] ?? ''));
}
if (!$useDb) {
    $variants = [
        ['id' => 0, 'name' => '500 g', 'sku' => 'VEG-TOM-500', 'price' => 35, 'sale_price' => null, 'stock_qty' => 24, 'low_stock_threshold' => 10],
        ['id' => 0, 'name' => '1 kg', 'sku' => 'VEG-TOM-1K', 'price' => 65, 'sale_price' => 59, 'stock_qty' => 8, 'low_stock_threshold' => 10],
    ];
}

admin_layout_start(['pageTitle' => $product ? 'Edit product' : 'Add product', 'activeAdminNav' => 'products']);
$breadcrumbs = [
    ['label' => 'Products', 'href' => url_base() . '/admin/products/index.php'],
    ['label' => $product ? 'Edit' : 'Add'],
];
$pageHeading = $product ? 'Edit product' : 'Add product';
$pageDescription = $useDb ? 'Published products appear on the storefront. Draft and archived products are hidden.' : 'Sign in with database admin to save live catalogue data.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($errors !== []) {
    echo '<div class="at-alert at-alert--error mb-4">' . Security::escape(implode(' ', $errors)) . '</div>';
}
?>
<div class="at-admin-tabs">
    <button type="button" class="is-active at-focusable" data-admin-tab="general">General</button>
    <button type="button" class="at-focusable" data-admin-tab="variants">Variants & stock</button>
    <button type="button" class="at-focusable" data-admin-tab="attributes">Attributes</button>
    <button type="button" class="at-focusable" data-admin-tab="images">Images</button>
    <button type="button" class="at-focusable" data-admin-tab="seo">SEO</button>
</div>

<form method="post" class="mt-4" data-at-admin-slug data-slug-api="<?= Security::escape(url_base()) ?>/admin/ajax/slug-from-name.php">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($product): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>

    <div data-admin-panel="general">
        <div class="at-admin-card at-admin-form-grid at-admin-form-grid--2">
            <div class="at-admin-card__body space-y-3 lg:col-span-2">
                <?php
                $slugSourceId = 'name';
                $slugSourceName = 'name';
                $slugSourceLabel = 'Product name';
                $slugSourceValue = (string) $defaults['name'];
                $slugTargetId = 'slug';
                $slugTargetValue = (string) $defaults['slug'];
                $slugManualId = 'product-slug-manual';
                $slugError = $errors['slug'] ?? null;
                if (!$product) {
                    $slugSourcePlaceholder = 'e.g. Fresh Red Tomatoes';
                    $slugTargetPlaceholder = 'Auto-generated from name';
                }
                require APNI_TOKRI_ROOT . '/includes/partials/admin/slug-field.php';
                ?>
                <div class="grid md:grid-cols-3 gap-3">
                    <div>
                        <label class="at-label" for="sku">SKU</label>
                        <input id="sku" name="sku" class="at-input at-focusable" value="<?= Security::escape((string) ($defaults['sku'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="at-label" for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="at-select at-focusable">
                            <option value="">—</option>
                            <?php if ($useDb):
                                foreach (CategoryRepository::allForAdmin() as $c):
                                    $sel = (string) ($defaults['category_id'] ?? '') === (string) $c['id'] ? ' selected' : '';
                                    ?>
                                    <option value="<?= (int) $c['id'] ?>"<?= $sel ?>><?= Security::escape((string) $c['name']) ?></option>
                                <?php endforeach;
                            endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="at-label" for="status">Status</label>
                        <select id="status" name="status" class="at-select at-focusable">
                            <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'] as $val => $lab): ?>
                                <option value="<?= $val ?>"<?= ($defaults['status'] ?? '') === $val ? ' selected' : '' ?>><?= $lab ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="at-label" for="product_type">Product type</label>
                    <select id="product_type" name="product_type" class="at-select at-focusable">
                        <?php foreach (['fresh' => 'Fresh produce', 'cut' => 'Cut / prepared', 'packaged' => 'Packaged food'] as $val => $lab): ?>
                            <option value="<?= $val ?>"<?= ($defaults['product_type'] ?? '') === $val ? ' selected' : '' ?>><?= $lab ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="is_featured" value="1"<?= !empty($defaults['is_featured']) ? ' checked' : '' ?>> Featured</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="is_bestseller" value="1"<?= !empty($defaults['is_bestseller']) ? ' checked' : '' ?>> Bestseller</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="is_new_arrival" value="1"<?= !empty($defaults['is_new_arrival']) ? ' checked' : '' ?>> New arrival</label>
                </div>
                <div>
                    <label class="at-label" for="short">Short description</label>
                    <textarea id="short" name="short_description" class="at-textarea at-focusable" rows="2"><?= Security::escape((string) ($defaults['short_description'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="at-label" for="desc">Full description</label>
                    <textarea id="desc" name="description" class="at-textarea at-focusable" rows="4"><?= Security::escape((string) ($defaults['description'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div data-admin-panel="variants" class="hidden">
        <div class="at-admin-card">
            <div class="at-admin-card__body space-y-3">
                <p class="text-sm at-muted">Stock changes from orders use locked decrements (Stage 7). Manual edits here set absolute stock levels.</p>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--fixed">
                        <thead><tr><th>Variant</th><th>SKU</th><th>Price (₹)</th><th>Sale</th><th>Stock</th><th>Low at</th></tr></thead>
                        <tbody id="at-variant-rows">
                        <?php if ($variants === []): $variants = [['id' => '', 'name' => '', 'sku' => '', 'price' => '', 'sale_price' => null, 'stock_qty' => 0, 'low_stock_threshold' => 5]]; endif;
                        foreach ($variants as $v): ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="variant_id[]" value="<?= Security::escape((string) ($v['id'] ?? '')) ?>">
                                    <input name="variant_name[]" class="at-input at-focusable" value="<?= Security::escape((string) ($v['name'] ?? '')) ?>" required>
                                </td>
                                <td><input name="variant_sku[]" class="at-input at-focusable" value="<?= Security::escape((string) ($v['sku'] ?? '')) ?>"></td>
                                <td><input name="variant_price[]" type="number" step="0.01" min="0" class="at-input at-focusable" value="<?= Security::escape((string) ($v['price'] ?? '')) ?>"></td>
                                <td><input name="variant_sale[]" type="number" step="0.01" min="0" class="at-input at-focusable" value="<?= $v['sale_price'] !== null ? Security::escape((string) $v['sale_price']) : '' ?>" placeholder="—"></td>
                                <td><input name="variant_stock[]" type="number" min="0" class="at-input at-focusable" value="<?= (int) ($v['stock_qty'] ?? 0) ?>"></td>
                                <td><input name="variant_low[]" type="number" min="0" class="at-input at-focusable" value="<?= (int) ($v['low_stock_threshold'] ?? 5) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div data-admin-panel="attributes" class="hidden">
        <div class="at-admin-card">
            <div class="at-admin-card__body space-y-3">
                <p class="text-sm at-muted">Category-specific details (storage, ingredients, cut size, etc.).</p>
                <?php
                if ($attributes === []) {
                    $attributes = [['attr_key' => 'Storage', 'attr_value' => '']];
                }
                foreach ($attributes as $a): ?>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <input name="attr_label[]" class="at-input at-focusable" placeholder="Label" value="<?= Security::escape((string) ($a['attr_key'] ?? '')) ?>">
                        <input name="attr_value[]" class="at-input at-focusable" placeholder="Value" value="<?= Security::escape((string) ($a['attr_value'] ?? '')) ?>">
                    </div>
                <?php endforeach; ?>
                <div class="grid sm:grid-cols-2 gap-2">
                    <input name="attr_label[]" class="at-input at-focusable" placeholder="Label">
                    <input name="attr_value[]" class="at-input at-focusable" placeholder="Value">
                </div>
            </div>
        </div>
    </div>

    <div data-admin-panel="seo" class="hidden">
        <div class="at-admin-seo-panel space-y-3">
            <div>
                <label class="at-label" for="meta_title">Meta title</label>
                <input id="meta_title" name="meta_title" class="at-input at-focusable" maxlength="70" value="<?= Security::escape((string) ($defaults['meta_title'] ?? '')) ?>">
            </div>
            <div>
                <label class="at-label" for="meta_desc">Meta description</label>
                <textarea id="meta_desc" name="meta_description" class="at-textarea at-focusable" maxlength="160" rows="2"><?= Security::escape((string) ($defaults['meta_description'] ?? '')) ?></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="robots_index" value="1"<?= !empty($defaults['robots_index']) ? ' checked' : '' ?>> Allow search indexing</label>
        </div>
    </div>

    <div class="flex gap-3 mt-6">
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save product</button>
        <a href="<?= Security::escape(url_base()) ?>/admin/products/index.php" class="at-btn at-btn--ghost at-focusable">Cancel</a>
    </div>
</form>

<?php if ($useDb && $product): ?>
<section id="images" class="mt-8" data-admin-panel="images">
    <h3 class="font-bold mb-3">Product images</h3>
    <div class="flex flex-wrap gap-3 mb-4">
        <?php foreach ($images as $img):
            $src = asset_url((string) $img['file_path']);
            ?>
            <div class="relative w-28">
                <img src="<?= Security::escape($src) ?>" alt="" class="w-28 h-28 object-cover rounded-lg border border-neutral-200">
                <?php if (!empty($img['is_primary'])): ?><span class="absolute top-1 left-1 at-status at-status--ok text-[10px]">Primary</span><?php endif; ?>
                <form method="post" class="mt-1">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_image">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
                    <button type="submit" class="text-xs text-red-700 at-focusable">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <form method="post" enctype="multipart/form-data" class="at-admin-card max-w-md">
        <div class="at-admin-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload_image">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <label class="at-label" for="image">Upload image (JPEG, PNG, WebP — max 5 MB)</label>
            <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="at-input" required>
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Upload</button>
        </div>
    </form>
</section>
<?php elseif (!$product && $useDb): ?>
<p class="text-sm at-muted mt-6">Save the product first, then upload images.</p>
<?php endif; ?>

<?php admin_layout_end(); ?>
