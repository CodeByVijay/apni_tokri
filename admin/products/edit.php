<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_admin_form.php';

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
        try {
            $result = ProductRepository::saveGeneral($postId, [
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'slug_manual' => !empty($_POST['slug_manual']),
                'sku' => $_POST['sku'] ?? '',
                'category_id' => $catId,
                'short_description' => $_POST['short_description'] ?? '',
                'description' => $_POST['description'] ?? '',
                'product_type' => $_POST['product_type'] ?? ProductTypeRepository::defaultCode(),
                'status' => $_POST['status'] ?? 'draft',
                'is_featured' => $_POST['is_featured'] ?? null,
                'is_bestseller' => $_POST['is_bestseller'] ?? null,
                'is_new_arrival' => $_POST['is_new_arrival'] ?? null,
                'meta_title' => $_POST['meta_title'] ?? '',
                'meta_description' => $_POST['meta_description'] ?? '',
                'robots_index' => $_POST['robots_index'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('admin/products/edit.php save: ' . $e->getMessage());
            $errors = ['form' => 'Could not save product. Please try again or contact support.'];
            $result = ['ok' => false];
        }
        if (!empty($result['ok'])) {
            $savedId = (int) $result['id'];
            $variantRows = ProductAdminForm::variantRowsFromPost($_POST);
            $errors = array_merge(
                $errors,
                ProductAdminForm::validateVariantRows($variantRows),
                ProductAdminForm::validateAttributesFromPost($_POST)
            );
            if ($errors === []) {
                $vRes = ProductRepository::saveVariants(
                    $savedId,
                    ProductAdminForm::variantRowsForRepository($variantRows)
                );
                if (!$vRes['ok']) {
                    $errors['variants'] = $vRes['message'] ?? 'Variant save failed.';
                } else {
                    ProductRepository::saveAttributes($savedId, ProductAdminForm::attributesFromPost($_POST));
                    CatalogueGateway::reset();
                    admin_flash_set('Product saved.');
                    header('Location: ' . url_base() . '/admin/products/edit.php?id=' . $savedId, true, 302);
                    exit;
                }
            }
            $id = $savedId;
            $product = ProductRepository::find($savedId);
            $variants = ProductAdminForm::variantsForDisplay($_POST);
            $attributes = ProductAdminForm::attributesForDisplay($_POST);
        }
        if ($errors === [] && empty($result['ok'])) {
            $errors = $result['errors'] ?? ['form' => $result['message'] ?? 'Save failed.'];
        }
    }
}

$defaults = [
    'name' => '',
    'slug' => '',
    'sku' => '',
    'category_id' => '',
    'short_description' => '',
    'description' => '',
    'product_type' => ProductTypeRepository::defaultCode(),
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
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($errors !== []) && (string) ($_POST['action'] ?? '') === 'save') {
    if ($variants === [] || isset($_POST['variant_name'])) {
        $variants = ProductAdminForm::variantsForDisplay($_POST);
    }
    if ($attributes === [] || isset($_POST['attr_label'])) {
        $attributes = ProductAdminForm::attributesForDisplay($_POST);
    }
}
if (!$product && $_SERVER['REQUEST_METHOD'] === 'POST' && ($errors !== [])) {
    $defaults['name'] = trim((string) ($_POST['name'] ?? ''));
    $defaults['slug'] = trim((string) ($_POST['slug'] ?? ''));
    $defaults['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $defaults['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
    $defaults['short_description'] = trim((string) ($_POST['short_description'] ?? ''));
    $defaults['description'] = trim((string) ($_POST['description'] ?? ''));
    $defaults['status'] = trim((string) ($_POST['status'] ?? 'draft'));
    $defaults['product_type'] = trim((string) ($_POST['product_type'] ?? ProductTypeRepository::defaultCode()));
}
$productTypeOptions = ProductTypeRepository::optionsForProductForm((string) ($defaults['product_type'] ?? ''));
$nextSkuHint = ($useDb && !$product) ? ProductSkuService::peekNext() : '';
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
<div class="at-product-edit mt-4" data-at-product-edit>
<div class="at-admin-tabs">
    <button type="button" class="is-active at-focusable" data-admin-tab="general">General</button>
    <button type="button" class="at-focusable" data-admin-tab="variants">Variants & stock</button>
    <button type="button" class="at-focusable" data-admin-tab="attributes">Attributes</button>
    <button type="button" class="at-focusable" data-admin-tab="images">Images</button>
    <button type="button" class="at-focusable" data-admin-tab="seo">SEO</button>
</div>

<form method="post" id="at-product-edit-form" class="mt-4" data-at-admin-slug data-at-admin-tabbed-form data-at-product-form data-slug-api="<?= Security::escape(url_base()) ?>/admin/ajax/slug-from-name.php">
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
                $nameError = $errors['name'] ?? null;
                require APNI_TOKRI_ROOT . '/includes/partials/admin/slug-field.php';
                if (!empty($nameError)): ?>
                    <p class="text-sm text-red-600 -mt-2"><?= Security::escape((string) $nameError) ?></p>
                <?php endif; ?>
                <div class="grid md:grid-cols-3 gap-3">
                    <div>
                        <label class="at-label" for="sku">SKU</label>
                        <input id="sku" name="sku" class="at-input at-focusable" value="<?= Security::escape((string) ($defaults['sku'] ?? '')) ?>" placeholder="Leave blank to auto-assign on save" autocomplete="off">
                        <?php if ($useDb && !$product && $nextSkuHint !== ''): ?>
                            <p class="text-sm at-muted mt-1">Next available: <code><?= Security::escape($nextSkuHint) ?></code>
                                <button type="button" class="at-btn at-btn--ghost at-btn--xs at-focusable ml-1" data-at-fill-sku="<?= Security::escape($nextSkuHint) ?>">Use this SKU</button>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($errors['sku'])): ?>
                            <p class="text-sm text-red-600 mt-1"><?= Security::escape((string) $errors['sku']) ?></p>
                        <?php endif; ?>
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
                        <?php foreach ($productTypeOptions as $opt):
                            $val = (string) $opt['code'];
                            $lab = (string) $opt['name'];
                            ?>
                            <option value="<?= Security::escape($val) ?>"<?= ($defaults['product_type'] ?? '') === $val ? ' selected' : '' ?>><?= Security::escape($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($useDb && ProductTypeRepository::tableReady()): ?>
                        <p class="text-sm at-muted mt-1"><a class="at-link" href="<?= Security::escape(url_base()) ?>/admin/products/product-types.php">Manage product types</a></p>
                    <?php endif; ?>
                    <?php if (!empty($errors['product_type'])): ?>
                        <p class="text-sm text-red-600 mt-1"><?= Security::escape((string) $errors['product_type']) ?></p>
                    <?php endif; ?>
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
                <?php if (!empty($errors['variants'])): ?>
                    <p class="text-sm text-red-600"><?= Security::escape((string) $errors['variants']) ?></p>
                <?php endif; ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--fixed">
                        <thead><tr><th>Variant</th><th>SKU</th><th>Price (₹)</th><th>Sale</th><th>Stock</th><th>Low at</th><th class="text-right"> </th></tr></thead>
                        <tbody id="at-variant-rows">
                        <?php
                        if ($variants === []) {
                            $variants = [['id' => '', 'name' => '', 'sku' => '', 'price' => '', 'sale_price' => null, 'stock_qty' => 0, 'low_stock_threshold' => 5]];
                        }
                        foreach ($variants as $v) {
                            require APNI_TOKRI_ROOT . '/includes/partials/admin/product-variant-row.php';
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-add-variant>+ Add variant</button>
            </div>
        </div>
    </div>

    <div data-admin-panel="attributes" class="hidden">
        <div class="at-admin-card">
            <div class="at-admin-card__body space-y-3">
                <p class="text-sm at-muted">Category-specific details (storage, ingredients, cut size, etc.).</p>
                <?php if (!empty($errors['attributes'])): ?>
                    <p class="text-sm text-red-600"><?= Security::escape((string) $errors['attributes']) ?></p>
                <?php endif; ?>
                <div id="at-attribute-rows" class="space-y-2">
                <?php
                if ($attributes === []) {
                    $attributes = [['attr_key' => '', 'attr_value' => '']];
                }
                foreach ($attributes as $a) {
                    require APNI_TOKRI_ROOT . '/includes/partials/admin/product-attribute-row.php';
                }
                ?>
                </div>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-add-attribute>+ Add attribute</button>
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
</form>

    <div id="images" data-admin-panel="images" class="hidden mt-4">
        <?php if ($useDb && $product): ?>
            <div class="at-admin-card">
                <div class="at-admin-card__body space-y-4">
                    <h3 class="font-bold">Product images</h3>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ($images as $img):
                            $src = asset_url((string) $img['file_path']);
                            ?>
                            <div class="relative w-28 shrink-0">
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
                </div>
            </div>
        <?php elseif ($useDb): ?>
            <div class="at-admin-card">
                <div class="at-admin-card__body">
                    <p class="text-sm at-muted">Save the product first, then upload images.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="at-admin-card">
                <div class="at-admin-card__body">
                    <p class="text-sm at-muted">Sign in with database admin to manage product images.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="flex flex-wrap gap-3 mt-6">
        <button type="submit" form="at-product-edit-form" class="at-btn at-btn--primary at-focusable" data-at-submit-once>Save product</button>
        <a href="<?= Security::escape(url_base()) ?>/admin/products/index.php" class="at-btn at-btn--ghost at-focusable">Cancel</a>
    </div>
</div>

<?php admin_layout_end(); ?>
