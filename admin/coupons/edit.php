<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('coupons');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$coupon = ($useDb && $id) ? CouponRepository::find($id) : null;
if ($useDb && $id && $coupon === null) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'Coupon not found', 'activeAdminNav' => 'coupons']);
    echo '<div class="at-alert at-alert--error">Coupon not found.</div>';
    admin_layout_end();
    exit;
}

$errors = [];
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_persistence_blocked('Coupon saved (demo UI).');
        header('Location: ' . url_base() . '/admin/coupons/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    if ($postId !== null && $postId !== $id) {
        $postId = $id;
    }
    $result = CouponAdminService::save($postId, $_POST);
    if ($result['ok']) {
        admin_flash_set('Coupon saved.');
        header('Location: ' . url_base() . '/admin/coupons/index.php', true, 302);
        exit;
    }
    $errors = $result['errors'];
    $posted = $_POST;
    admin_flash_set($result['message'] ?? 'Please fix the highlighted fields.', 'error');
}

/** Build form state from POST (after error) or from the stored coupon. */
$f = [
    'code' => '', 'internal_name' => '', 'discount_type' => 'percent', 'discount_value' => '10',
    'min_cart_value' => '', 'max_discount' => '', 'usage_limit' => '', 'per_customer_limit' => '1',
    'priority' => '0', 'starts_at' => '', 'ends_at' => '', 'is_active' => 1, 'storefront_visible' => 1, 'stackable_with_offers' => 0,
    'eligibility_mode' => CouponAdminService::MODE_ALL, 'customer_mode' => CouponAdminService::CUSTOMERS_ALL,
    'first_order_also' => 0, 'category_ids' => [], 'product_ids' => [], 'customer_ids' => [],
];
$toLocal = static function ($v): string {
    $ts = $v ? strtotime((string) $v) : false;

    return $ts ? date('Y-m-d\TH:i', $ts) : '';
};
if ($posted !== null) {
    foreach (['code', 'internal_name', 'discount_type', 'discount_value', 'min_cart_value', 'max_discount',
        'usage_limit', 'per_customer_limit', 'priority', 'starts_at', 'ends_at', 'eligibility_mode', 'customer_mode'] as $k) {
        $f[$k] = trim((string) ($posted[$k] ?? ''));
    }
    $f['is_active'] = !empty($posted['is_active']) ? 1 : 0;
    $f['storefront_visible'] = !empty($posted['storefront_visible']) ? 1 : 0;
    $f['stackable_with_offers'] = !empty($posted['stackable_with_offers']) ? 1 : 0;
    $f['first_order_also'] = !empty($posted['first_order_also']) ? 1 : 0;
    foreach (['category_ids', 'product_ids', 'customer_ids'] as $k) {
        $f[$k] = array_values(array_filter(array_map('intval', (array) ($posted[$k] ?? [])), static function ($v) {
            return $v > 0;
        }));
    }
} elseif ($coupon !== null && $id) {
    $f['code'] = (string) $coupon['code'];
    $f['internal_name'] = (string) ($coupon['internal_name'] ?? '');
    $f['discount_type'] = (string) $coupon['discount_type'];
    $f['discount_value'] = (string) $coupon['discount_value'];
    foreach (['min_cart_value', 'max_discount', 'usage_limit', 'per_customer_limit'] as $k) {
        $f[$k] = $coupon[$k] !== null ? (string) $coupon[$k] : '';
    }
    $f['priority'] = (string) (int) ($coupon['priority'] ?? 0);
    $f['starts_at'] = $toLocal($coupon['starts_at'] ?? null);
    $f['ends_at'] = $toLocal($coupon['ends_at'] ?? null);
    $f['is_active'] = (int) $coupon['is_active'];
    $f['storefront_visible'] = isset($coupon['storefront_visible']) ? (int) $coupon['storefront_visible'] : 1;
    $f['stackable_with_offers'] = (int) ($coupon['stackable_with_offers'] ?? 0);
    $f['category_ids'] = CouponRepository::categoryIds($id);
    $f['product_ids'] = CouponRepository::productIds($id);
    $f['customer_ids'] = CouponRepository::assignedCustomerIds($id);
    $f['eligibility_mode'] = CouponAdminService::eligibilityModeFor($f['category_ids'], $f['product_ids']);
    if ($f['customer_ids'] !== []) {
        $f['customer_mode'] = CouponAdminService::CUSTOMERS_SELECTED;
        $f['first_order_also'] = !empty($coupon['first_order_only']) ? 1 : 0;
    } elseif (!empty($coupon['first_order_only'])) {
        $f['customer_mode'] = CouponAdminService::CUSTOMERS_FIRST_ORDER;
    }
}

$categoryItems = [];
$categoryById = [];
if ($useDb) {
    foreach (CouponAdminService::categoriesForPicker() as $cat) {
        $label = $cat['parent'] !== '' ? $cat['parent'] . ' › ' . $cat['name'] : $cat['name'];
        $item = ['id' => $cat['id'], 'label' => $label, 'sub' => ''];
        $categoryItems[] = $item;
        $categoryById[$cat['id']] = $item;
    }
}
$selCategories = [];
foreach ($f['category_ids'] as $cid) {
    if (isset($categoryById[$cid])) {
        $selCategories[] = $categoryById[$cid];
    }
}
$selProducts = [];
if ($useDb && $f['product_ids'] !== []) {
    foreach (CouponAdminService::searchProducts('', 50, $f['product_ids']) as $p) {
        $selProducts[] = ['id' => $p['id'], 'label' => $p['name'], 'sub' => $p['sku'] !== '' ? 'SKU ' . $p['sku'] : ''];
    }
}
$selCustomers = [];
if ($useDb && $f['customer_ids'] !== []) {
    foreach (CouponAdminService::searchCustomers('', 50, $f['customer_ids']) as $cu) {
        $selCustomers[] = [
            'id' => $cu['id'],
            'label' => $cu['name'] !== '' ? $cu['name'] : ($cu['mobile'] !== '' ? $cu['mobile'] : 'Customer #' . $cu['id']),
            'sub' => trim($cu['mobile'] . ' ' . $cu['email']),
        ];
    }
}

$esc = static function ($v): string {
    return Security::escape((string) $v);
};
$jsonAttr = static function ($v): string {
    return Security::escape((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};
$err = static function (string $key) use (&$errors): string {
    return isset($errors[$key])
        ? '<p class="at-field-error" role="alert">' . Security::escape($errors[$key]) . '</p>'
        : '';
};
$inv = static function (string $key) use (&$errors): string {
    return isset($errors[$key]) ? ' is-invalid' : '';
};
$lookupUrl = url_base() . '/admin/ajax/coupon-lookup.php';

admin_layout_start(['pageTitle' => $id ? 'Edit coupon' : 'New coupon', 'activeAdminNav' => 'coupons']);
$pageHeading = $id ? 'Edit coupon' : 'New coupon';
$pageDescription = 'Create a discount code customers can enter in the cart. Discounts apply to product amounts only — never to delivery charges.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-coupon-form" novalidate>
    <?= Csrf::field() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

    <!-- 1. Coupon information -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">1. Coupon information</h2>
            <div class="at-coupon-form__grid at-coupon-form__grid--2 mt-3">
                <div>
                    <label class="at-label" for="coupon-code">Coupon code</label>
                    <div class="at-coupon-code-row">
                        <input id="coupon-code" name="code" class="at-input at-focusable<?= $inv('code') ?>" maxlength="32" required
                               autocomplete="off" placeholder="e.g. FRESH10" value="<?= $esc($f['code']) ?>">
                        <button type="button" id="coupon-generate" class="at-btn at-btn--secondary at-focusable"
                                data-url="<?= $esc($lookupUrl) ?>">Generate code</button>
                    </div>
                    <p class="at-field-help">What customers type at checkout. Letters, numbers, - and _ only. Saved in capitals. You can edit a generated code.</p>
                    <?= $err('code') ?>
                </div>
                <div>
                    <label class="at-label" for="coupon-internal">Internal name (optional)</label>
                    <input id="coupon-internal" name="internal_name" class="at-input at-focusable<?= $inv('internal_name') ?>" maxlength="191"
                           placeholder="e.g. Diwali fruits campaign" value="<?= $esc($f['internal_name']) ?>">
                    <p class="at-field-help">Only visible to your team, to help you recognise the coupon.</p>
                    <?= $err('internal_name') ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 2. Discount details -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">2. Discount details</h2>
            <div class="at-coupon-form__grid at-coupon-form__grid--2 mt-3">
                <div>
                    <label class="at-label" for="coupon-discount-type">Discount type</label>
                    <select id="coupon-discount-type" name="discount_type" class="at-select at-focusable w-full">
                        <option value="percent"<?= $f['discount_type'] === 'percent' ? ' selected' : '' ?>>Percentage (%) of eligible items</option>
                        <option value="fixed"<?= $f['discount_type'] === 'fixed' ? ' selected' : '' ?>>Fixed amount (₹)</option>
                    </select>
                    <p class="at-field-help">A fixed discount never exceeds the value of the eligible items.</p>
                </div>
                <div>
                    <label class="at-label" for="coupon-value">Discount value</label>
                    <input id="coupon-value" name="discount_value" type="number" step="0.01" min="0" class="at-input at-focusable<?= $inv('discount_value') ?>"
                           value="<?= $esc($f['discount_value']) ?>">
                    <p class="at-field-help">10 means 10% (percentage) or ₹10 (fixed). Percentages cannot exceed 100.</p>
                    <?= $err('discount_value') ?>
                </div>
                <div>
                    <label class="at-label" for="coupon-min">Minimum cart amount (₹)</label>
                    <input id="coupon-min" name="min_cart_value" type="number" step="0.01" min="0" class="at-input at-focusable<?= $inv('min_cart_value') ?>"
                           placeholder="No minimum" value="<?= $esc($f['min_cart_value']) ?>">
                    <p class="at-field-help">Measured on the <strong>eligible items</strong> only. If the coupon covers all products this is the whole cart value (before delivery).</p>
                    <?= $err('min_cart_value') ?>
                </div>
                <div id="coupon-max-discount-wrap">
                    <label class="at-label" for="coupon-max">Maximum discount (₹)</label>
                    <input id="coupon-max" name="max_discount" type="number" step="0.01" min="0" class="at-input at-focusable<?= $inv('max_discount') ?>"
                           placeholder="No cap" value="<?= $esc($f['max_discount']) ?>">
                    <p class="at-field-help">Caps a percentage discount (e.g. 10% off, up to ₹200).</p>
                    <?= $err('max_discount') ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 3. Product and category eligibility -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">3. Product and category eligibility</h2>
            <p class="at-field-help">Choose which items in the cart can receive the discount. Other items are charged full price. If both categories and products are chosen, an item qualifies when it matches <strong>either</strong>.</p>
            <div class="at-coupon-form__grid mt-3">
                <?php
                $modes = [
                    CouponAdminService::MODE_ALL => ['All products', 'Every item in the cart can be discounted.'],
                    CouponAdminService::MODE_CATEGORIES => ['Selected categories', 'Only products in the chosen categories.'],
                    CouponAdminService::MODE_PRODUCTS => ['Selected products', 'Only the specific products you pick.'],
                    CouponAdminService::MODE_BOTH => ['Selected categories and products', 'Products in the chosen categories plus the chosen products.'],
                ];
                foreach ($modes as $val => $m): ?>
                <label class="at-coupon-radio">
                    <input type="radio" name="eligibility_mode" value="<?= $esc($val) ?>"<?= $f['eligibility_mode'] === $val ? ' checked' : '' ?>>
                    <span><strong><?= $esc($m[0]) ?></strong><small><?= $esc($m[1]) ?></small></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="mt-4" data-show-for-mode="categories both">
                <label class="at-label">Categories</label>
                <div data-at-picker data-input-name="category_ids[]" data-source="static"
                     data-items="<?= $jsonAttr($categoryItems) ?>" data-selected="<?= $jsonAttr($selCategories) ?>"
                     data-placeholder="Search categories…" data-none="No categories selected." data-empty="No matching categories."
                     data-hint="Click a category to select it; click it again (or ×) to remove."></div>
                <?= $err('category_ids') ?>
            </div>
            <div class="mt-4" data-show-for-mode="products both">
                <label class="at-label">Products</label>
                <div data-at-picker data-input-name="product_ids[]" data-source="ajax" data-type="products" data-url="<?= $esc($lookupUrl) ?>"
                     data-selected="<?= $jsonAttr($selProducts) ?>"
                     data-placeholder="Search by product name, SKU or variant…" data-none="No products selected." data-empty="No matching products."
                     data-hint="Search results load as you type. Click a result to select; click again to remove."></div>
                <?= $err('product_ids') ?>
            </div>
        </div>
    </section>

    <!-- 4. Customer eligibility -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">4. Customer eligibility</h2>
            <div class="at-coupon-form__grid mt-3">
                <?php
                $cmodes = [
                    CouponAdminService::CUSTOMERS_ALL => ['All customers', 'Anyone (including guests, subject to usage limits) can use it.'],
                    CouponAdminService::CUSTOMERS_SELECTED => ['Selected customers', 'Only the customers you pick can use it.'],
                    CouponAdminService::CUSTOMERS_FIRST_ORDER => ['First paid order only', 'Only customers who have never completed a paid order. Customers must be logged in.'],
                ];
                foreach ($cmodes as $val => $m): ?>
                <label class="at-coupon-radio">
                    <input type="radio" name="customer_mode" value="<?= $esc($val) ?>"<?= $f['customer_mode'] === $val ? ' checked' : '' ?>>
                    <span><strong><?= $esc($m[0]) ?></strong><small><?= $esc($m[1]) ?></small></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-4" data-show-for-customers="selected">
                <label class="at-label">Customers</label>
                <div data-at-picker data-input-name="customer_ids[]" data-source="ajax" data-type="customers" data-url="<?= $esc($lookupUrl) ?>"
                     data-selected="<?= $jsonAttr($selCustomers) ?>"
                     data-placeholder="Search by name, mobile number or email…" data-none="No customers selected." data-empty="No matching customers."
                     data-hint="Type at least 2 characters. Click a customer to select; click again to remove."></div>
                <?= $err('customer_ids') ?>
                <label class="flex gap-2 text-sm items-start mt-3">
                    <input type="checkbox" name="first_order_also" value="1"<?= !empty($f['first_order_also']) ? ' checked' : '' ?>>
                    <span>Also require the customer's first paid order<span class="at-field-help block">Keeps an existing "first order" restriction on this selected-customer coupon.</span></span>
                </label>
            </div>
        </div>
    </section>

    <!-- 5. Usage limits and validity -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">5. Usage limits and validity</h2>
            <div class="at-coupon-form__grid at-coupon-form__grid--2 mt-3">
                <div>
                    <label class="at-label" for="coupon-usage">Total usage limit</label>
                    <input id="coupon-usage" name="usage_limit" type="number" min="1" step="1" class="at-input at-focusable<?= $inv('usage_limit') ?>"
                           placeholder="Unlimited" value="<?= $esc($f['usage_limit']) ?>">
                    <p class="at-field-help">How many orders can use this coupon in total. Blank = unlimited.</p>
                    <?= $err('usage_limit') ?>
                </div>
                <div>
                    <label class="at-label" for="coupon-per">Per-customer limit</label>
                    <input id="coupon-per" name="per_customer_limit" type="number" min="1" step="1" class="at-input at-focusable<?= $inv('per_customer_limit') ?>"
                           placeholder="Unlimited" value="<?= $esc($f['per_customer_limit']) ?>">
                    <p class="at-field-help">How many times one customer can use it. Blank = unlimited.</p>
                    <?= $err('per_customer_limit') ?>
                </div>
                <div>
                    <label class="at-label" for="coupon-starts">Valid from</label>
                    <input id="coupon-starts" type="datetime-local" name="starts_at" class="at-input at-focusable<?= $inv('starts_at') ?>" value="<?= $esc($f['starts_at']) ?>">
                    <p class="at-field-help">Blank = valid immediately.</p>
                    <?= $err('starts_at') ?>
                </div>
                <div>
                    <label class="at-label" for="coupon-ends">Valid until</label>
                    <input id="coupon-ends" type="datetime-local" name="ends_at" class="at-input at-focusable<?= $inv('ends_at') ?>" value="<?= $esc($f['ends_at']) ?>">
                    <p class="at-field-help">Blank = never expires.</p>
                    <?= $err('ends_at') ?>
                </div>
                <label class="flex gap-2 text-sm items-start md:col-span-2">
                    <input type="checkbox" name="is_active" value="1"<?= !empty($f['is_active']) ? ' checked' : '' ?>>
                    <span><strong>Active</strong><span class="at-field-help block">Untick to switch the coupon off without deleting it.</span></span>
                </label>
                <label class="flex gap-2 text-sm items-start md:col-span-2">
                    <input type="checkbox" name="storefront_visible" value="1"<?= !empty($f['storefront_visible']) ? ' checked' : '' ?>>
                    <span><strong>Show in cart “Available Offers”</strong><span class="at-field-help block">When off, customers can still enter the code manually but the coupon will not appear in the offers carousel.</span></span>
                </label>
            </div>
        </div>
    </section>

    <!-- 6. Advanced settings -->
    <section class="at-card">
        <div class="at-card__body">
            <h2 class="at-card__title">6. Advanced settings</h2>
            <div class="at-coupon-form__grid at-coupon-form__grid--2 mt-3">
                <label class="flex gap-2 text-sm items-start">
                    <input type="checkbox" name="stackable_with_offers" value="1"<?= !empty($f['stackable_with_offers']) ? ' checked' : '' ?>>
                    <span><strong>Allow with automatic offers</strong><span class="at-field-help block">If an automatic store offer is already active, this coupon may be used on top of it. Otherwise the coupon is rejected.</span></span>
                </label>
                <div>
                    <label class="at-label" for="coupon-priority">Priority</label>
                    <input id="coupon-priority" name="priority" type="number" step="1" class="at-input at-focusable<?= $inv('priority') ?>" value="<?= $esc($f['priority']) ?>">
                    <p class="at-field-help">Higher numbers are listed first in reports. Does not change the discount. Default 0.</p>
                    <?= $err('priority') ?>
                </div>
            </div>
        </div>
    </section>

    <div class="at-coupon-actions">
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save coupon</button>
        <a href="<?= $esc(url_base()) ?>/admin/coupons/index.php" class="at-btn at-btn--secondary at-focusable">Cancel</a>
    </div>
</form>
<script src="<?= $esc(asset_url('assets/js/admin-coupon-form.js')) ?>"></script>
<?php admin_layout_end(); ?>
