<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('offers');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$offer = ($useDb && $id) ? OfferRepository::find($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_persistence_blocked('Offer saved (demo UI).');
        header('Location: ' . url_base() . '/admin/offers/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $customerIds = [];
    foreach (preg_split('/\s*,\s*/', (string) ($_POST['assigned_customer_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
        if (ctype_digit($p)) {
            $customerIds[] = (int) $p;
        }
    }
    $target = $_POST['target_id'] !== '' ? (int) $_POST['target_id'] : null;
    OfferRepository::save($postId, [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'scope' => (string) ($_POST['scope'] ?? 'store'),
        'target_id' => $target,
        'discount_type' => ($_POST['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent',
        'discount_value' => (float) ($_POST['discount_value'] ?? 0),
        'max_discount' => $_POST['max_discount'] !== '' ? (float) $_POST['max_discount'] : null,
        'min_cart_value' => $_POST['min_cart_value'] !== '' ? (float) $_POST['min_cart_value'] : null,
        'starts_at' => $_POST['starts_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $_POST['starts_at'])) : null,
        'ends_at' => $_POST['ends_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $_POST['ends_at'])) : null,
        'priority' => (int) ($_POST['priority'] ?? 0),
        'stack_with_coupons' => !empty($_POST['stack_with_coupons']) ? 1 : 0,
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ], $customerIds);
    admin_flash_set('Offer saved.');
    header('Location: ' . url_base() . '/admin/offers/index.php', true, 302);
    exit;
}

$o = $offer ?? [
    'name' => '', 'scope' => 'store', 'target_id' => null, 'discount_type' => 'percent',
    'discount_value' => 5, 'min_cart_value' => '', 'max_discount' => '', 'priority' => 10,
    'stack_with_coupons' => 0, 'is_active' => 1,
];

admin_layout_start(['pageTitle' => 'Edit offer', 'activeAdminNav' => 'offers']);
$pageHeading = $id ? 'Edit offer' : 'New offer';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div><label class="at-label">Name</label><input name="name" class="at-input at-focusable" required value="<?= Security::escape((string) $o['name']) ?>"></div>
        <div><label class="at-label">Scope</label><select name="scope" class="at-select at-focusable">
            <?php foreach (['store', 'category', 'product', 'variant', 'first_order'] as $sc): ?>
                <option value="<?= $sc ?>" <?= ($o['scope'] ?? '') === $sc ? 'selected' : '' ?>><?= Security::escape(ucwords(str_replace('_', ' ', $sc))) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label class="at-label">Target ID (category / product / variant when scoped)</label><input name="target_id" class="at-input at-focusable" value="<?= Security::escape((string) ($o['target_id'] ?? '')) ?>"></div>
        <div class="grid md:grid-cols-2 gap-3">
            <div><label class="at-label">Discount type</label><select name="discount_type" class="at-select at-focusable"><option value="percent">Percent</option><option value="fixed">Fixed</option></select></div>
            <div><label class="at-label">Value</label><input name="discount_value" type="number" step="0.01" class="at-input at-focusable" value="<?= Security::escape((string) ($o['discount_value'] ?? '')) ?>"></div>
        </div>
        <div class="grid md:grid-cols-2 gap-3">
            <div><label class="at-label">Min cart</label><input name="min_cart_value" class="at-input at-focusable" value="<?= Security::escape((string) ($o['min_cart_value'] ?? '')) ?>"></div>
            <div><label class="at-label">Max discount</label><input name="max_discount" class="at-input at-focusable" value="<?= Security::escape((string) ($o['max_discount'] ?? '')) ?>"></div>
        </div>
        <div class="grid md:grid-cols-2 gap-3">
            <div><label class="at-label">Starts</label><input type="datetime-local" name="starts_at" class="at-input at-focusable"></div>
            <div><label class="at-label">Ends</label><input type="datetime-local" name="ends_at" class="at-input at-focusable"></div>
        </div>
        <div><label class="at-label">Priority (higher wins when tied on discount amount)</label><input name="priority" type="number" class="at-input at-focusable" value="<?= (int) ($o['priority'] ?? 0) ?>"></div>
        <div><label class="at-label">Assigned customer IDs (comma, empty = all)</label><input name="assigned_customer_ids" class="at-input at-focusable"></div>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="stack_with_coupons" value="1" <?= !empty($o['stack_with_coupons']) ? 'checked' : '' ?>> Allow stacking with coupon codes</label>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= !empty($o['is_active']) ? 'checked' : '' ?>> Active</label>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save offer</button>
    </div>
</form>
<?php admin_layout_end(); ?>
