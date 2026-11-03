<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (AdminAuth::check()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : $token;
}

$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $result = AdminPasswordRecovery::resetWithToken($token, $new, $confirm);
    if ($result['ok']) {
        $done = true;
        $_SESSION['admin_login_notice'] = 'Password updated. Sign in with your new password.';
    } else {
        $error = $result['message'] ?? 'Could not reset password.';
    }
}

layout_auth_start('Reset password');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell auth-shell--compact">
    <main class="auth-main auth-main--solo">
        <div class="auth-card">
            <div class="auth-card__mobile-logo">
                <img src="<?= $logo ?>" alt="" width="36" height="36"><span>Apni Tokri Admin</span>
            </div>
            <h1>Set new password</h1>
            <?php if ($done): ?>
                <div class="auth-alert auth-alert--info" role="status">Your password has been updated.</div>
                <p class="auth-card__foot"><a class="auth-btn auth-btn--block" href="<?= Security::escape(url_to('/admin/login.php')) ?>">Sign in</a></p>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
                <?php endif; ?>
                <?php if ($token === ''): ?>
                    <div class="auth-alert auth-alert--warn" role="alert">Missing reset token. Use the link from your email.</div>
                <?php else: ?>
                    <form method="post" novalidate>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="token" value="<?= Security::escape($token) ?>">
                        <div class="auth-field">
                            <label class="auth-label" for="new_password">New password</label>
                            <input id="new_password" name="new_password" type="password" class="auth-input" required autocomplete="new-password">
                        </div>
                        <div class="auth-field">
                            <label class="auth-label" for="confirm_password">Confirm password</label>
                            <input id="confirm_password" name="confirm_password" type="password" class="auth-input" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="auth-btn"><span class="auth-btn__label">Update password</span></button>
                    </form>
                <?php endif; ?>
                <p class="auth-card__foot"><a href="<?= Security::escape(url_to('/admin/login.php')) ?>">&larr; Back to sign in</a></p>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
