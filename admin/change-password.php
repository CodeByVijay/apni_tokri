<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (!AdminAuth::requiresMandatoryPasswordChange()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
    $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $result = AdminAuth::changePasswordMandatory($current, $new, $confirm);
    if ($result['ok']) {
        header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
        exit;
    }
    $error = (string) ($result['message'] ?? 'Could not update password.');
}

layout_auth_start('Change password');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell">
    <aside class="auth-brand" aria-label="Veggie Tokri">
        <a class="auth-brand__logo" href="<?= Security::escape(url_base()) ?>/index.php">
            <img src="<?= $logo ?>" alt="" width="46" height="46">
            <span class="auth-brand__name">Veggie Tokri<small>Admin console</small></span>
        </a>
        <div class="auth-brand__copy">
            <h2>Secure your admin account</h2>
            <p>The installer default password must be changed before you can use the admin console.</p>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <h1>Set a new password</h1>
            <p class="auth-card__lead">Use at least 10 characters with letters and numbers. You will need your current (installer) password once.</p>

            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= Csrf::field() ?>
                <?php
                $pwFields = [
                    ['current_password', 'Current password', 'current-password', true],
                    ['new_password', 'New password', 'new-password', false],
                    ['confirm_password', 'Confirm new password', 'new-password', false],
                ];
                foreach ($pwFields as [$fid, $flabel, $fauto, $fautofocus]):
                    ?>
                <div class="auth-field">
                    <label class="auth-label" for="<?= $fid ?>"><?= Security::escape($flabel) ?></label>
                    <div class="auth-input-wrap">
                        <input id="<?= $fid ?>" name="<?= $fid ?>" type="password" class="auth-input auth-input--pw" autocomplete="<?= $fauto ?>" required<?= $fautofocus ? ' autofocus' : '' ?>>
                        <button type="button" class="auth-toggle" data-pw-toggle="<?= $fid ?>" aria-label="Show password" aria-pressed="false">
                            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.5 6.9A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="auth-btn"><span class="auth-btn__label">Update password</span></button>
            </form>

            <form method="post" action="<?= Security::escape(url_to('/admin/logout.php')) ?>" class="auth-card__foot">
                <?= Csrf::field() ?>
                <button type="submit" class="auth-foot-btn">Sign out</button>
            </form>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
