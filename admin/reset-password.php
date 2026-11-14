<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (AdminAuth::check()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : $token;
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

$status = $done ? 'ok' : AdminPasswordRecovery::tokenStatus($token);
$linkBad = in_array($status, ['invalid', 'used', 'expired', 'unavailable'], true);
if ($linkBad && $error === '') {
    $error = match ($status) {
        'expired' => 'This password reset link has expired. Request a new one below.',
        'used' => 'This password reset link has already been used. Request a new one below.',
        'unavailable' => 'Password reset is temporarily unavailable. Try again later.',
        default => 'This password reset link is invalid. Request a new one below.',
    };
}

layout_auth_start('Reset password');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell auth-shell--compact">
    <main class="auth-main auth-main--solo">
        <div class="auth-card">
            <div class="auth-card__brand">
                <img src="<?= $logo ?>" alt="" width="40" height="40">
                <span class="auth-card__brand-name">Veggie Tokri<small>Admin console</small></span>
            </div>
            <h1>Set New Password</h1>
            <?php if ($done): ?>
                <div class="auth-alert auth-alert--ok" role="status">Your password has been updated. You can now sign in with your new password.</div>
                <p class="auth-card__foot"><a class="auth-btn auth-btn--block" href="<?= Security::escape(url_to('/admin/login.php')) ?>">Sign In</a></p>
            <?php elseif ($linkBad): ?>
                <div class="auth-alert auth-alert--warn" role="alert"><?= Security::escape($error) ?></div>
                <p class="auth-card__foot"><a class="auth-btn auth-btn--block" href="<?= Security::escape(url_to('/admin/forgot-password.php')) ?>">Request New Reset Link</a></p>
                <p class="auth-card__foot"><a href="<?= Security::escape(url_to('/admin/login.php')) ?>">&larr; Back to Sign In</a></p>
            <?php else: ?>
                <p class="auth-card__lead">Choose a new password for your admin account.</p>
                <?php if ($error !== ''): ?>
                    <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
                <?php endif; ?>
                <form method="post" id="auth-reset-form" novalidate>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="token" value="<?= Security::escape($token) ?>">
                    <div class="auth-field">
                        <label class="auth-label" for="new_password">New Password</label>
                        <div class="auth-input-wrap">
                            <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                            <input id="new_password" name="new_password" type="password" class="auth-input auth-input--pw" autocomplete="new-password" placeholder="Enter new password" required autofocus aria-describedby="new_password-error">
                            <button type="button" class="auth-toggle" data-pw-toggle="new_password" aria-label="Show password" aria-pressed="false">
                                <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.5 6.9A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                            </button>
                        </div>
                        <p class="auth-field-error" id="new_password-error" hidden></p>
                        <ul class="auth-req">
                            <li>At least 10 characters</li>
                            <li>At least one letter and one number</li>
                            <li>Must differ from your current password</li>
                        </ul>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label" for="confirm_password">Confirm New Password</label>
                        <div class="auth-input-wrap">
                            <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                            <input id="confirm_password" name="confirm_password" type="password" class="auth-input auth-input--pw" autocomplete="new-password" placeholder="Re-enter new password" required aria-describedby="confirm_password-error">
                            <button type="button" class="auth-toggle" data-pw-toggle="confirm_password" aria-label="Show password" aria-pressed="false">
                                <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.5 6.9A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                            </button>
                        </div>
                        <p class="auth-field-error" id="confirm_password-error" hidden></p>
                    </div>
                    <button type="submit" class="auth-btn" id="auth-submit">
                        <span class="auth-btn__spinner" aria-hidden="true"></span>
                        <span class="auth-btn__label">Save New Password</span>
                    </button>
                </form>
                <p class="auth-card__foot"><a href="<?= Security::escape(url_to('/admin/login.php')) ?>">&larr; Back to Sign In</a></p>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
