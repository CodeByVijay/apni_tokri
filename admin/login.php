<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/auth.php';

if (AdminAuth::check()) {
    header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
    exit;
}

$error = '';
$email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
$notice = isset($_SESSION['admin_login_notice']) && is_string($_SESSION['admin_login_notice'])
    ? $_SESSION['admin_login_notice'] : '';
unset($_SESSION['admin_login_notice']);
$next = isset($_GET['next']) && is_string($_GET['next']) ? url_app_relative(trim($_GET['next'])) : '';
if ($next !== '' && (!str_starts_with($next, '/') || str_contains($next, 'logout.php'))) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady()) {
        $error = 'Database schema not installed. Import database/database.sql first.';
    } else {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if ($email === '' || $password === '') {
            $error = 'Enter your email and password.';
        } elseif (!AdminAuth::attempt($email, $password)) {
            $error = 'Invalid email or password.';
        } else {
            $dest = url_to('/admin/dashboard.php');
            $postNext = isset($_POST['next']) && is_string($_POST['next']) ? url_app_relative(trim($_POST['next'])) : '';
            if ($postNext !== '' && str_starts_with($postNext, '/') && !str_contains($postNext, '//')) {
                $dest = url_to($postNext);
            }
            header('Location: ' . $dest, true, 302);
            exit;
        }
    }
}

layout_auth_start('Sign in');
$logo = Security::escape(asset_url('assets/images/logo.svg'));
?>
<div class="auth-shell">
    <aside class="auth-brand" aria-label="Apni Tokri">
        <a class="auth-brand__logo" href="<?= Security::escape(url_base()) ?>/index.php">
            <img src="<?= $logo ?>" alt="" width="46" height="46">
            <span class="auth-brand__name">Apni Tokri<small>Admin console</small></span>
        </a>
        <div class="auth-brand__copy">
            <h2>Fresh groceries, managed with ease.</h2>
            <p>Run orders, inventory, deliveries and customers for your neighbourhood grocery store from one place.</p>
            <div class="auth-brand__chips">
                <span class="auth-chip">Orders</span>
                <span class="auth-chip">Inventory</span>
                <span class="auth-chip">Deliveries</span>
                <span class="auth-chip">Reports</span>
            </div>
        </div>
        <svg class="auth-brand__art" viewBox="0 0 320 260" fill="none" aria-hidden="true">
            <circle cx="160" cy="150" r="120" fill="#fff" fill-opacity=".06"/>
            <path d="M60 120h200l-22 108a16 16 0 0 1-16 12H98a16 16 0 0 1-16-12L60 120Z" fill="#F5A623"/>
            <path d="M60 120h200l-6 30H66l-6-30Z" fill="#fff" fill-opacity=".22"/>
            <path d="M100 120c0-38 26-64 60-64s60 26 60 64" stroke="#fff" stroke-opacity=".85" stroke-width="10" stroke-linecap="round"/>
            <circle cx="120" cy="92" r="22" fill="#e5533d"/><path d="M120 70c2-8 8-12 14-12" stroke="#7bc47f" stroke-width="5" stroke-linecap="round"/>
            <ellipse cx="170" cy="86" rx="16" ry="28" fill="#f08a24" transform="rotate(18 170 86)"/>
            <circle cx="205" cy="98" r="19" fill="#7bc47f"/><path d="M205 80c0-8 5-12 10-14" stroke="#2d7048" stroke-width="5" stroke-linecap="round"/>
            <path d="M100 160h120M104 188h112" stroke="#133421" stroke-opacity=".25" stroke-width="6" stroke-linecap="round"/>
        </svg>
        <p class="auth-brand__foot">&copy; <?= date('Y') ?> Apni Tokri. Authorised staff only.</p>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="auth-card__mobile-logo">
                <img src="<?= $logo ?>" alt="" width="36" height="36"><span>Apni Tokri Admin</span>
            </div>
            <h1>Admin sign in</h1>
            <p class="auth-card__lead">Welcome back. Sign in to manage your store.</p>

            <?php if (!Install::isSchemaReady()): ?>
                <div class="auth-alert auth-alert--warn" role="alert">Import <code>database/database.sql</code> (or restore your dump), then sign in. Admin modules stay locked until the schema is installed.</div>
            <?php endif; ?>
            <?php if ($notice !== ''): ?>
                <div class="auth-alert auth-alert--info" role="status"><?= Security::escape($notice) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="auth-alert auth-alert--error" role="alert"><?= Security::escape($error) ?></div>
            <?php endif; ?>

            <form method="post" id="auth-login-form" novalidate>
                <?= Csrf::field() ?>
                <?php if ($next !== ''): ?>
                    <input type="hidden" name="next" value="<?= Security::escape($next) ?>">
                <?php endif; ?>

                <div class="auth-field">
                    <label class="auth-label" for="email">Email address</label>
                    <div class="auth-input-wrap">
                        <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input id="email" name="email" type="email" class="auth-input<?= $error !== '' ? ' is-invalid' : '' ?>" autocomplete="username" inputmode="email" placeholder="you@example.com" required autofocus value="<?= Security::escape($email) ?>" aria-describedby="email-error">
                    </div>
                    <p class="auth-field-error" id="email-error" hidden></p>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="password">Password</label>
                    <div class="auth-input-wrap">
                        <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        <input id="password" name="password" type="password" class="auth-input auth-input--pw<?= $error !== '' ? ' is-invalid' : '' ?>" autocomplete="current-password" placeholder="Enter your password" required aria-describedby="password-error">
                        <button type="button" class="auth-toggle" id="auth-pw-toggle" aria-label="Show password" aria-pressed="false">
                            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.5 6.9A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                    <p class="auth-field-error" id="password-error" hidden></p>
                </div>

                <div class="auth-row">
                    <label class="auth-check" for="remember"><input type="checkbox" id="remember" name="remember_email" value="1"> Remember my email</label>
                </div>

                <button type="submit" class="auth-btn" id="auth-submit">
                    <span class="auth-btn__spinner" aria-hidden="true"></span>
                    <span class="auth-btn__label">Sign in</span>
                </button>
            </form>

            <p class="auth-card__foot"><a href="<?= Security::escape(url_base()) ?>/index.php">&larr; Back to store</a></p>
        </div>
    </main>
</div>
<?php layout_auth_end(); ?>
