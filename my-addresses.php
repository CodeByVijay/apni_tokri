<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());
require APNI_TOKRI_ROOT . '/includes/partials/account-guard.php';

$base = url_base();
$errors = [];
$flash = '';
if (!empty($_SESSION['account_flash']) && is_string($_SESSION['account_flash'])) {
    $flash = $_SESSION['account_flash'];
    unset($_SESSION['account_flash']);
}

$blank = ['label' => 'Home', 'line1' => '', 'line2' => '', 'pincode' => ''];
$form = $blank;
$editId = 0;
$showForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $addrId = isset($_POST['address_id']) && is_string($_POST['address_id']) && ctype_digit($_POST['address_id']) ? (int) $_POST['address_id'] : 0;
    // customerId is from the session (account-guard). POSTed customer ids are never read.
    $redirectMsg = null;

    if ($action === 'add' || $action === 'update') {
        $form = [
            'label' => isset($_POST['label']) && is_string($_POST['label']) ? $_POST['label'] : '',
            'line1' => isset($_POST['line1']) && is_string($_POST['line1']) ? $_POST['line1'] : '',
            'line2' => isset($_POST['line2']) && is_string($_POST['line2']) ? $_POST['line2'] : '',
            'pincode' => isset($_POST['pincode']) && is_string($_POST['pincode']) ? $_POST['pincode'] : '',
        ];
        if ($action === 'add') {
            $res = CustomerAccountService::addAddress($customerId, $form, !empty($_POST['make_default']));
            $redirectMsg = 'Address added.';
        } else {
            $editId = $addrId;
            $res = CustomerAccountService::updateAddress($customerId, $addrId, $form);
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
        $form = ['label' => (string) $row['label'], 'line1' => (string) $row['line1'], 'line2' => (string) ($row['line2'] ?? ''), 'pincode' => (string) $row['pincode']];
        $showForm = true;
    } else {
        $errors['form'] = 'Address not found.';
    }
} elseif (isset($_GET['add'])) {
    $showForm = true;
}

$addresses = CustomerAccountService::listAddresses($customerId);

layout_public_start(['pageTitle' => 'My addresses — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'addresses';
$accountTitle = 'My addresses';
$accountSub = 'Delivery addresses saved to your account';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
$fe = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<p class="at-field-hint" style="color:var(--at-danger)" role="alert">' . Security::escape($errors[$k]) . '</p>' : '';
};
?>
<?php if ($flash !== ''): ?>
    <div class="at-alert at-alert--success" role="status"><span><?= Security::escape($flash) ?></span></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
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
                <?php foreach ($addresses as $a): $isDef = (int) $a['is_default'] === 1; ?>
                    <li class="at-option !cursor-default flex-col !items-stretch" data-address-id="<?= (int) $a['id'] ?>">
                        <div class="flex gap-3">
                            <span class="text-[var(--at-green)] mt-0.5"><?= at_icon('pin', 'w-5 h-5') ?></span>
                            <span class="text-sm">
                                <strong><?= Security::escape((string) $a['label']) ?></strong>
                                <?php if ($isDef): ?><span class="at-badge at-badge--success ml-1">Default</span><?php endif; ?><br>
                                <?= Security::escape((string) $a['line1']) ?><?= !empty($a['line2']) ? ', ' . Security::escape((string) $a['line2']) : '' ?>,
                                <?= Security::escape((string) $a['city']) ?> — <?= Security::escape((string) $a['pincode']) ?>
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <a href="<?= Security::escape($base) ?>/my-addresses.php?edit=<?= (int) $a['id'] ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable">Edit</a>
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
        <h2 class="at-h3"><?= $editId > 0 ? 'Edit address' : 'Add a new address' ?></h2>
        <form method="post" action="<?= Security::escape($base) ?>/my-addresses.php" class="mt-4 grid sm:grid-cols-2 gap-4 max-w-2xl" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= $editId > 0 ? 'update' : 'add' ?>">
            <?php if ($editId > 0): ?><input type="hidden" name="address_id" value="<?= (int) $editId ?>"><?php endif; ?>
            <div><label class="at-label" for="ad-label">Label</label>
                <input id="ad-label" name="label" class="at-input at-focusable" maxlength="64" value="<?= Security::escape($form['label']) ?>" placeholder="Home, Office…"><?= $fe('label') ?></div>
            <div><label class="at-label" for="ad-pin">Pincode</label>
                <input id="ad-pin" name="pincode" class="at-input at-focusable" inputmode="numeric" maxlength="6" value="<?= Security::escape($form['pincode']) ?>" placeholder="452001"><?= $fe('pincode') ?></div>
            <div class="sm:col-span-2"><label class="at-label" for="ad-line1">Address</label>
                <input id="ad-line1" name="line1" class="at-input at-focusable" maxlength="255" value="<?= Security::escape($form['line1']) ?>" placeholder="House / flat, street, area"><?= $fe('line1') ?></div>
            <div class="sm:col-span-2"><label class="at-label" for="ad-line2">Landmark <span class="at-muted font-normal">(optional)</span></label>
                <input id="ad-line2" name="line2" class="at-input at-focusable" maxlength="255" value="<?= Security::escape($form['line2']) ?>"><?= $fe('line2') ?></div>
            <?php if ($editId === 0): ?>
                <label class="sm:col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" name="make_default" value="1"> Make this my default address</label>
            <?php endif; ?>
            <div class="sm:col-span-2 flex gap-2">
                <button type="submit" class="at-btn at-btn--primary at-focusable"><?= $editId > 0 ? 'Save changes' : 'Save address' ?></button>
                <a href="<?= Security::escape($base) ?>/my-addresses.php" class="at-btn at-btn--secondary at-focusable">Cancel</a>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
