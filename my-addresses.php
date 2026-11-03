<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());
require APNI_TOKRI_ROOT . '/includes/partials/account-guard.php';
require APNI_TOKRI_ROOT . '/includes/partials/address-summary.php';

$base = url_base();
$errors = [];
$flash = '';
if (!empty($_SESSION['account_flash']) && is_string($_SESSION['account_flash'])) {
    $flash = $_SESSION['account_flash'];
    unset($_SESSION['account_flash']);
}

$profile = CustomerAccountService::profile($customerId) ?? [];
$form = AddressInput::blankFormValues($profile);
$editId = 0;
$showForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $addrId = isset($_POST['address_id']) && is_string($_POST['address_id']) && ctype_digit($_POST['address_id']) ? (int) $_POST['address_id'] : 0;
    // customerId is from the session (account-guard). POSTed customer ids are never read.
    $redirectMsg = null;

    if ($action === 'add' || $action === 'update') {
        // Same validation + persistence path as checkout.php (CustomerAccountService / AddressInput).
        $form = AddressInput::formValuesFromPost($_POST);
        if ($action === 'add') {
            $res = CustomerAccountService::addAddress($customerId, $_POST, !empty($_POST['make_default']));
            $redirectMsg = 'Address added.';
        } else {
            $editId = $addrId;
            $res = CustomerAccountService::updateAddress($customerId, $addrId, $_POST);
            $redirectMsg = 'Address updated.';
        }
        if ($res['ok']) {
            $_SESSION['account_flash'] = $redirectMsg;
            header('Location: ' . $base . '/my-addresses.php', true, 303);
            exit;
        }
        $errors = $res['errors'];
        $showForm = true;
    } elseif ($action === 'delete') {
        $res = CustomerAccountService::deleteAddress($customerId, $addrId);
        $_SESSION['account_flash'] = $res['message'];
        header('Location: ' . $base . '/my-addresses.php', true, 303);
        exit;
    } elseif ($action === 'default') {
        $res = CustomerAccountService::setDefault($customerId, $addrId);
        $_SESSION['account_flash'] = $res['message'];
        header('Location: ' . $base . '/my-addresses.php', true, 303);
        exit;
    } else {
        $errors['form'] = 'Unknown action.';
    }
} elseif (isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $row = CustomerAccountService::findAddress($customerId, (int) $_GET['edit']);
    if ($row !== null) {
        $editId = (int) $row['id'];
        $form = AddressInput::formValuesFromRow($row, $profile);
        $showForm = true;
    } else {
        $errors['form'] = 'Address not found.';
    }
} elseif (isset($_GET['add'])) {
    $showForm = true;
}

$addresses = CustomerAccountService::listAddresses($customerId);
$anyPin = false;
foreach ($addresses as $a) {
    if (AddressInput::hasPin($a)) {
        $anyPin = true;
        break;
    }
}

layout_public_start([
    'pageTitle' => 'My addresses — Apni Tokri',
    'activeNav' => 'account',
    'loadAddressMap' => $showForm || $anyPin,
]);
$accountActive = 'addresses';
$accountTitle = 'My addresses';
$accountSub = 'Delivery addresses saved to your account';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($flash !== ''): ?>
    <div class="at-alert at-alert--success" role="status"><span><?= Security::escape($flash) ?></span></div>
<?php endif; ?>
<?php if (isset($errors['form']) && !$showForm): ?>
    <div class="at-alert at-alert--error" role="alert"><span><?= Security::escape($errors['form']) ?></span></div>
<?php endif; ?>

<section class="at-card">
    <div class="at-card__body">
        <div class="flex items-center justify-between gap-3">
            <h2 class="at-h3">Saved addresses</h2>
            <?php if (!$showForm): ?><a href="<?= Security::escape($base) ?>/my-addresses.php?add=1" class="at-btn at-btn--primary at-btn--sm at-focusable"><?= at_icon('plus', 'w-4 h-4') ?>Add address</a><?php endif; ?>
        </div>
        <?php if ($addresses === []): ?>
            <p class="mt-3 text-sm at-muted">You have no saved addresses yet. Add one to speed up checkout.</p>
        <?php else: ?>
            <ul class="mt-3 grid sm:grid-cols-2 gap-3">
                <?php foreach ($addresses as $a): $isDef = (int) $a['is_default'] === 1; $hasPin = AddressInput::hasPin($a); ?>
                    <li class="at-addr-card at-addr-card--static" data-address-id="<?= (int) $a['id'] ?>">
                        <div class="flex gap-3">
                            <span class="text-[var(--at-green)] mt-0.5 flex-shrink-0"><?= at_icon('pin', 'w-5 h-5') ?></span>
                            <span class="at-addr-card__body"><?= at_address_summary_html($a) ?></span>
                        </div>
                        <?php if (!empty($a['delivery_instructions'])): ?>
                            <p class="text-xs at-muted">Instructions: <?= Security::escape((string) $a['delivery_instructions']) ?></p>
                        <?php endif; ?>
                        <div class="at-addr-card__actions">
                            <a href="<?= Security::escape($base) ?>/my-addresses.php?edit=<?= (int) $a['id'] ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable"><?= $hasPin ? 'Edit' : 'Add map pin' ?></a>
                            <?php if ($hasPin): ?>
                                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-view-location
                                    data-lat="<?= Security::escape((string) $a['latitude']) ?>" data-lng="<?= Security::escape((string) $a['longitude']) ?>"
                                    data-title="<?= Security::escape((string) $a['label']) ?>">View location</button>
                            <?php endif; ?>
                            <?php if (!$isDef): ?>
                                <form method="post" action="<?= Security::escape($base) ?>/my-addresses.php"><?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Set as default</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?= Security::escape($base) ?>/my-addresses.php" onsubmit="return confirm('Delete this address?');"><?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable" style="color:var(--at-danger)">Delete</button></form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php if ($showForm): ?>
<section class="at-card" id="address-form">
    <div class="at-card__body">
        <h2 class="at-h3 mb-4"><?= $editId > 0 ? 'Edit address' : 'Add a new address' ?></h2>
        <?php
        $addrFormAction = $base . '/my-addresses.php';
        $addrFormMode = $editId > 0 ? 'update' : 'add';
        $addrFormId = $editId;
        $addrFormValues = $form;
        $addrFormErrors = $errors;
        $addrFormCancelUrl = $base . '/my-addresses.php';
        $addrFormShowDefault = true;
        $addrFormHidden = [];
        $addrFormUid = 'ma';
        require APNI_TOKRI_ROOT . '/includes/partials/address-form.php';
        ?>
    </div>
</section>
<?php endif; ?>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
