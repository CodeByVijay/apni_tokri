<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (AdminAuth::check()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$message = '';
$error = '';
$email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = AdminPasswordRecovery::requestReset($email);
    if ($result['ok']) {
        $message = $result['message'];
        $email = '';
    } else {
        $error = $result['message'];
    }
}

layout_auth_start('Forgot password');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell auth-shell--compact">
    <main class="auth-main auth-main--solo">
        <div class="auth-card">
            <div class="auth-card__mobile-logo">
                <img src="<?= $logo ?>" alt="" width="36" height="36"><span>Veggie Tokri Admin</span>
            </div>
            <h1>Forgot password</h1>
            <p class="auth-card__lead">Enter your staff email. If an account exists, we will send reset instructions.</p>
            <?php if ($message !== ''): ?>
                <div class="auth-alert auth-alert--info" role="status"><?= Security::escape($message) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <?= Csrf::field() ?>
                <div class="auth-field">
                    <label class="auth-label" for="email">Email address</label>
                    <input id="email" name="email" type="email" class="auth-input" required autocomplete="username" value="<?= Security::escape($email) ?>">
                </div>
                <button type="submit" class="auth-btn"><span class="auth-btn__label">Send reset link</span></button>
            </form>
            <p class="auth-card__foot"><a href="<?= Security::escape(url_to('/admin/login.php')) ?>">&larr; Back to sign in</a></p>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
