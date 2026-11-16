<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('customers');

$errors = [];
$name = '';
$mobile = '';
$email = '';
$isActive = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
    $mobile = isset($_POST['mobile']) && is_string($_POST['mobile']) ? trim($_POST['mobile']) : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $isActive = !isset($_POST['is_active']) || (string) $_POST['is_active'] !== '0';

    $adminId = AdminAuth::id();
    if ($adminId === null) {
        admin_flash_set('Admin session required.', 'error');
    } else {
        $result = AdminCustomerService::createFromAdmin($name, $mobile, $email, $isActive, (int) $adminId);
        if (!empty($result['ok']) && !empty($result['customer_id'])) {
            admin_flash_set((string) ($result['message'] ?? 'Customer created.'), 'success');
            header('Location: ' . url_base() . '/admin/customers/view.php?id=' . (int) $result['customer_id'], true, 302);
            exit;
        }
        $errors = $result['errors'] ?? [];
        admin_flash_set((string) ($result['message'] ?? 'Could not create customer.'), 'error');
    }
}

admin_layout_start(['pageTitle' => 'Add customer', 'activeAdminNav' => 'customers']);
$pageHeading = 'Add customer';
$pageDescription = 'Manually provision a storefront customer. Login remains mobile OTP only — no password is created.';
$breadcrumbs = [
    ['label' => 'Customers', 'href' => url_base() . '/admin/customers/index.php'],
    ['label' => 'Add customer'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/customers/index.php">← Back to customers</a></p>

<div class="at-admin-card max-w-lg"><div class="at-admin-card__body">
    <form method="post" class="space-y-3">
        <?= Csrf::field() ?>
        <div>
            <label class="at-label" for="cust-name">Full name <span class="text-red-600">*</span></label>
            <input id="cust-name" name="name" class="at-input at-focusable w-full" required maxlength="100" autocomplete="name"
                   value="<?= Security::escape($name) ?>" placeholder="Customer full name">
            <?php if (!empty($errors['name'])): ?><p class="text-xs text-red-700 mt-1"><?= Security::escape($errors['name']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="cust-mobile">Mobile number <span class="text-red-600">*</span></label>
            <input id="cust-mobile" name="mobile" type="tel" class="at-input at-focusable w-full" required inputmode="numeric" maxlength="14" autocomplete="tel-national"
                   value="<?= Security::escape($mobile) ?>" placeholder="10-digit Indian mobile">
            <p class="text-xs at-muted mt-1">Stored in normalized 10-digit form (same as OTP login and App Review Access).</p>
            <?php if (!empty($errors['mobile'])): ?><p class="text-xs text-red-700 mt-1"><?= Security::escape($errors['mobile']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="cust-email">Email address</label>
            <input id="cust-email" name="email" type="email" class="at-input at-focusable w-full" maxlength="191" autocomplete="email"
                   value="<?= Security::escape($email) ?>" placeholder="Optional">
            <?php if (!empty($errors['email'])): ?><p class="text-xs text-red-700 mt-1"><?= Security::escape($errors['email']) ?></p><?php endif; ?>
        </div>
        <fieldset class="space-y-2">
            <legend class="at-label">Account status</legend>
            <label class="flex gap-2 text-sm items-center">
                <input type="radio" name="is_active" value="1" class="mt-0"<?= $isActive ? ' checked' : '' ?>>
                <span>Active</span>
            </label>
            <label class="flex gap-2 text-sm items-center">
                <input type="radio" name="is_active" value="0" class="mt-0"<?= !$isActive ? ' checked' : '' ?>>
                <span>Inactive</span>
            </label>
        </fieldset>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Create customer</button>
    </form>
</div></div>
<?php admin_layout_end(); ?>
