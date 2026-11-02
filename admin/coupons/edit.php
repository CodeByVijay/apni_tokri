<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('coupons');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$coupon = ($useDb && $id) ? CouponRepository::find($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('Coupon saved (demo UI).');
        header('Location: ' . url_base() . '/admin/coupons/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    if ($code === '') {
        admin_flash_set('Coupon code is required.', 'error');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }
    $customerIds = [];
    foreach (preg_split('/\s*,\s*/', (string) ($_POST['assigned_customer_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
        if (ctype_digit($p)) {
            $customerIds[] = (int) $p;
        }
    }
    $categoryIds = array_map('intval', (array) ($_POST['category_ids'] ?? []));
    $productIds = array_map('intval', (array) ($_POST['product_ids'] ?? []));
    $excluded = array_map('intval', (array) ($_POST['excluded_product_ids'] ?? []));

    CouponRepository::save($postId, [
        'code' => $code,
        'internal_name' => trim((string) ($_POST['internal_name'] ?? '')),
        'discount_type' => ($_POST['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent',
        'discount_value' => (float) ($_POST['discount_value'] ?? 0),
        'min_cart_value' => $_POST['min_cart_value'] !== '' ? (float) $_POST['min_cart_value'] : null,
        'max_discount' => $_POST['max_discount'] !== '' ? (float) $_POST['max_discount'] : null,
        'usage_limit' => $_POST['usage_limit'] !== '' ? (int) $_POST['usage_limit'] : null,
        'per_customer_limit' => $_POST['per_customer_limit'] !== '' ? (int) $_POST['per_customer_limit'] : null,
        'first_order_only' => !empty($_POST['first_order_only']) ? 1 : 0,
        'stackable_with_offers' => !empty($_POST['stackable_with_offers']) ? 1 : 0,
        'priority' => (int) ($_POST['priority'] ?? 0),
        'starts_at' => $_POST['starts_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $_POST['starts_at'])) : null,
        'ends_at' => $_POST['ends_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $_POST['ends_at'])) : null,
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ], $customerIds, $categoryIds, $productIds, $excluded);

    admin_flash_set('Coupon saved.');
    header('Location: ' . url_base() . '/admin/coupons/index.php', true, 302);
    exit;
}

$categories = $useDb ? CategoryRepository::allForAdmin() : [];
$assigned = ($useDb && $id) ? implode(',', CouponRepository::assignedCustomerIds($id)) : '';
$selCats = ($useDb && $id) ? CouponRepository::categoryIds($id) : [];

admin_layout_start(['pageTitle' => $id ? 'Edit coupon' : 'New coupon', 'activeAdminNav' => 'coupons']);
admin_flash_render();
$pageHeading = $id ? 'Edit coupon' : 'New coupon';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$c = $coupon ?? [
    'code' => '', 'internal_name' => '', 'discount_type' => 'percent', 'discount_value' => 10,
    'min_cart_value' => 100, 'max_discount' => '', 'usage_limit' => '', 'per_customer_limit' => 1,
    'first_order_only' => 0, 'stackable_with_offers' => 0, 'priority' => 0, 'is_active' => 1,
];
?>
<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body at-admin-form-grid at-admin-form-grid--2">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div><label class="at-label">Code</label><input name="code" class="at-input at-focusable" required value="<?= Security::escape((string) $c['code']) ?>"></div>
        <div><label class="at-label">Internal name</label><input name="internal_name" class="at-input at-focusable" value="<?= Security::escape((string) ($c['internal_name'] ?? '')) ?>"></div>
        <div><label class="at-label">Type</label><select name="discount_type" class="at-select at-focusable">
            <option value="percent" <?= ($c['discount_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percentage</option>
            <option value="fixed" <?= ($c['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed ₹</option>
        </select></div>
        <div><label class="at-label">Value</label><input name="discount_value" type="number" step="0.01" class="at-input at-focusable" value="<?= Security::escape((string) ($c['discount_value'] ?? '')) ?>"></div>
        <div><label class="at-label">Min cart (₹)</label><input name="min_cart_value" class="at-input at-focusable" value="<?= Security::escape((string) ($c['min_cart_value'] ?? '')) ?>"></div>
        <div><label class="at-label">Max discount (₹, percent only)</label><input name="max_discount" class="at-input at-focusable" value="<?= Security::escape((string) ($c['max_discount'] ?? '')) ?>"></div>
        <div><label class="at-label">Total usage limit</label><input name="usage_limit" class="at-input at-focusable" value="<?= Security::escape((string) ($c['usage_limit'] ?? '')) ?>"></div>
        <div><label class="at-label">Per-customer limit</label><input name="per_customer_limit" class="at-input at-focusable" value="<?= Security::escape((string) ($c['per_customer_limit'] ?? '')) ?>"></div>
        <div class="md:col-span-2"><label class="at-label">Assigned customer IDs (comma-separated, empty = all)</label><input name="assigned_customer_ids" class="at-input at-focusable" value="<?= Security::escape($assigned) ?>"></div>
        <div class="md:col-span-2">
            <label class="at-label">Eligible categories (empty = all)</label>
            <select name="category_ids[]" class="at-select at-focusable" multiple size="4">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= in_array((int) $cat['id'], $selCats, true) ? 'selected' : '' ?>><?= Security::escape((string) $cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="first_order_only" value="1" <?= !empty($c['first_order_only']) ? 'checked' : '' ?>> First paid order only</label>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="stackable_with_offers" value="1" <?= !empty($c['stackable_with_offers']) ? 'checked' : '' ?>> Stack with automatic offers</label>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= !empty($c['is_active']) ? 'checked' : '' ?>> Active</label>
        <div class="md:col-span-2 flex gap-3">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save coupon</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/coupons/index.php" class="at-btn at-btn--secondary at-focusable">Cancel</a>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
