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

$profile = CustomerAccountService::profile($customerId) ?? [];
$form = ['name' => (string) ($profile['name'] ?? ''), 'email' => (string) ($profile['email'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    // Identity always comes from the session; any customer_id/mobile in POST is ignored.
    $form['name'] = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
    $form['email'] = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
    $res = CustomerAccountService::updateProfile($customerId, $form['name'], $form['email']);
    if ($res['ok']) {
        $_SESSION['account_flash'] = 'Profile updated.';
        header('Location: ' . $base . '/my-profile.php', true, 303);
        exit;
    }
    $errors = $res['errors'];
}

layout_public_start(['pageTitle' => 'My profile — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'profile';
$accountTitle = 'My profile';
$accountSub = 'Your name and contact details';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($flash !== ''): ?>
    <div class="at-alert at-alert--success" role="status"><span><?= Security::escape($flash) ?></span></div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
    <div class="at-alert at-alert--error" role="alert"><span><?= Security::escape($errors['form']) ?></span></div>
<?php endif; ?>
<section class="at-card">
    <div class="at-card__body">
        <h2 class="at-h3">Personal details</h2>
        <form method="post" action="<?= Security::escape($base) ?>/my-profile.php" class="mt-4 grid gap-4 max-w-xl" novalidate>
            <?= Csrf::field() ?>
            <div>
                <label class="at-label" for="pf-name">Full name</label>
                <input id="pf-name" name="name" class="at-input at-focusable" maxlength="100" required value="<?= Security::escape($form['name']) ?>" autocomplete="name">
                <?php if (isset($errors['name'])): ?><p class="at-field-hint" style="color:var(--at-danger)" role="alert"><?= Security::escape($errors['name']) ?></p><?php endif; ?>
            </div>
            <div>
                <label class="at-label" for="pf-email">Email <span class="at-muted font-normal">(optional)</span></label>
                <input id="pf-email" name="email" type="email" class="at-input at-focusable" maxlength="191" value="<?= Security::escape($form['email']) ?>" autocomplete="email">
                <?php if (isset($errors['email'])): ?><p class="at-field-hint" style="color:var(--at-danger)" role="alert"><?= Security::escape($errors['email']) ?></p><?php endif; ?>
            </div>
            <div>
                <label class="at-label" for="pf-mobile">Registered mobile</label>
                <input id="pf-mobile" class="at-input" value="<?= Security::escape((string) ($profile['mobile'] ?? '')) ?>" readonly disabled aria-describedby="pf-mobile-hint">
                <p class="at-field-hint" id="pf-mobile-hint">Your mobile number is your login and is verified by OTP, so it cannot be edited here. Contact support to change it.</p>
            </div>
            <div><button type="submit" class="at-btn at-btn--primary at-focusable">Save changes</button></div>
        </form>
    </div>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
