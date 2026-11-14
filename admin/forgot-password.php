<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (AdminAuth::check()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$error = '';
$fieldError = '';
$email = '';

// One-shot flash set only after a successfully processed POST (PRG pattern).
// A fresh GET never shows a confirmation, and stale flashes are cleared here.
$message = '';
$flash = $_SESSION['admin_forgot_notice'] ?? '';
unset($_SESSION['admin_forgot_notice']);
if (is_string($flash)) {
    $message = $flash;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';

    if ($email === '') {
        $fieldError = 'Enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldError = 'Enter a valid email address.';
    } else {
        $result = AdminPasswordRecovery::requestReset($email);
        if ($result['ok']) {
            $_SESSION['admin_forgot_notice'] = $result['message'];
            header('Location: ' . url_to('/admin/forgot-password'), true, 303);
            exit;
        }
        $error = $result['message'];
    }
}

layout_auth_start('Forgot password');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell auth-shell--compact">
    <main class="auth-main auth-main--solo">
        <div class="auth-card">
            <div class="auth-card__brand">
                <img src="<?= $logo ?>" alt="" width="40" height="40">
                <span class="auth-card__brand-name">Veggie Tokri<small>Admin console</small></span>
            </div>
            <h1>Forgot Password</h1>
            <p class="auth-card__lead">Enter your admin email address to receive password reset instructions.</p>
            <?php if ($message !== ''): ?>
                <div class="auth-alert auth-alert--ok" role="status"><?= Security::escape($message) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
            <?php endif; ?>
            <form method="post" id="auth-forgot-form" novalidate>
                <?= Csrf::field() ?>
                <div class="auth-field">
                    <label class="auth-label" for="email">Email Address</label>
                    <div class="auth-input-wrap">
                        <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input id="email" name="email" type="email" class="auth-input<?= $fieldError !== '' ? ' is-invalid' : '' ?>" autocomplete="username" inputmode="email" placeholder="you@example.com" required autofocus value="<?= Security::escape($email) ?>" aria-describedby="email-error"<?= $fieldError !== '' ? ' aria-invalid="true"' : '' ?>>
                    </div>
                    <p class="auth-field-error" id="email-error"<?= $fieldError === '' ? ' hidden' : '' ?>><?= Security::escape($fieldError) ?></p>
                </div>
                <button type="submit" class="auth-btn" id="auth-submit">
                    <span class="auth-btn__spinner" aria-hidden="true"></span>
                    <span class="auth-btn__label">Send Reset Link</span>
                </button>
            </form>
            <p class="auth-card__foot"><a href="<?= Security::escape(url_to('/admin/login')) ?>">&larr; Back to Sign In</a></p>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
