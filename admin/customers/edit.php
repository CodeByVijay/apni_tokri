<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('customers');

$customerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$customer = $customerId > 0 && Install::isSchemaReady() ? AdminCustomerService::findById($customerId) : null;

$errors = [];
$name = $customer ? trim((string) ($customer['name'] ?? '')) : '';
$email = $customer ? trim((string) ($customer['email'] ?? '')) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
    $customer = $customerId > 0 ? AdminCustomerService::findById($customerId) : null;
    if ($customer === null) {
        admin_flash_set('Customer not found.', 'error');
        header('Location: ' . url_base() . '/admin/customers/index.php', true, 302);
        exit;
    }
    if (isset($_POST['mobile'])) {
        admin_flash_set('Mobile cannot be changed from admin. Use customer OTP verification.', 'error');
        header('Location: ' . url_base() . '/admin/customers/edit.php?id=' . $customerId, true, 302);
        exit;
    }
    $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $adminId = AdminAuth::id();
    if ($adminId === null) {
        admin_flash_set('Admin session required.', 'error');
    } else {
        $result = AdminCustomerService::updateProfile($customerId, $name, $email, (int) $adminId);
        if (!empty($result['ok'])) {
            admin_flash_set((string) ($result['message'] ?? 'Customer updated.'), 'success');
            header('Location: ' . url_base() . '/admin/customers/view.php?id=' . $customerId, true, 302);
            exit;
        }
        $errors = $result['errors'] ?? [];
        admin_flash_set((string) ($result['message'] ?? 'Could not update customer.'), 'error');
    }
}

admin_layout_start(['pageTitle' => 'Edit customer', 'activeAdminNav' => 'customers']);

if ($customer === null) {
    echo '<div class="at-alert at-alert--error">Customer not found.</div>';
    echo '<p class="mt-3"><a class="underline" href="' . Security::escape(url_base()) . '/admin/customers/index.php">← Back</a></p>';
    admin_layout_end();
    exit;
}

$displayName = trim((string) ($customer['name'] ?? '')) !== '' ? (string) $customer['name'] : 'Customer';
$pageHeading = 'Edit — ' . $displayName;
$breadcrumbs = [
    ['label' => 'Customers', 'href' => url_base() . '/admin/customers/index.php'],
    ['label' => $displayName, 'href' => url_base() . '/admin/customers/view.php?id=' . $customerId],
    ['label' => 'Edit'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/customers/view.php?id=<?= $customerId ?>">← Back to customer</a></p>

<div class="at-admin-card max-w-lg"><div class="at-admin-card__body">
    <form method="post" class="space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="customer_id" value="<?= $customerId ?>">
        <div>
            <label class="at-label" for="cust-name">Name</label>
            <input id="cust-name" name="name" class="at-input at-focusable w-full" required maxlength="100" value="<?= Security::escape($name) ?>">
            <?php if (!empty($errors['name'])): ?><p class="text-xs text-red-700 mt-1"><?= Security::escape($errors['name']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="cust-email">Email</label>
            <input id="cust-email" name="email" type="email" class="at-input at-focusable w-full" maxlength="191" value="<?= Security::escape($email) ?>" placeholder="Optional">
            <?php if (!empty($errors['email'])): ?><p class="text-xs text-red-700 mt-1"><?= Security::escape($errors['email']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="cust-mobile">Mobile</label>
            <input id="cust-mobile" class="at-input w-full" readonly value="<?= Security::escape((string) ($customer['mobile'] ?? '')) ?>">
            <p class="text-xs at-muted mt-1">Mobile is the login identity. Changes require customer OTP verification — not editable here.</p>
        </div>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save changes</button>
    </form>
</div></div>
<?php admin_layout_end(); ?>
