<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Install::denyPublicInstallSurface();

Security::sendSecurityHeaders(Security::isHttps());

$requirements = InstallWizardService::environmentRequirements();
$privateLayout = InstallHostingPaths::defaultPrivateLayout();
$hostingHome = InstallHostingPaths::detectHostingHome();
$error = '';
$success = '';
$testDbMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'test_database') {
        $test = InstallWizardService::testDatabaseConnection([
            'host' => $_POST['db_host'] ?? '',
            'port' => $_POST['db_port'] ?? 3306,
            'name' => $_POST['db_name'] ?? '',
            'user' => $_POST['db_user'] ?? '',
            'password' => $_POST['db_password'] ?? '',
        ]);
        $testDbMessage = $test['ok']
            ? 'Database connection successful.'
            : (string) ($test['message'] ?? 'Connection failed.');
        if ($test['ok']) {
            $empty = InstallWizardService::assertDatabaseEmpty([
                'host' => $_POST['db_host'] ?? '',
                'port' => $_POST['db_port'] ?? 3306,
                'name' => $_POST['db_name'] ?? '',
                'user' => $_POST['db_user'] ?? '',
                'password' => $_POST['db_password'] ?? '',
            ]);
            if (!$empty['ok']) {
                $testDbMessage = (string) ($empty['message'] ?? 'Database is not empty.');
            }
        }
    }

    if ($action === 'install') {
        if (!InstallWizardService::requirementsMet()) {
            $error = 'Fix the server requirements below before installing.';
        } elseif (Install::hasLocalConfig() && is_file(InstallWizardService::configLocalPath())) {
            $error = 'config.local.php already exists. Remove it manually only on a fresh host, or use Admin → System repair for an existing site.';
        } else {
            $result = InstallWizardService::completeInstallation([
                'host' => $_POST['db_host'] ?? '',
                'port' => $_POST['db_port'] ?? 3306,
                'name' => $_POST['db_name'] ?? '',
                'user' => $_POST['db_user'] ?? '',
                'password' => $_POST['db_password'] ?? '',
                'admin_email' => $_POST['admin_email'] ?? '',
                'admin_password' => $_POST['admin_password'] ?? '',
                'admin_password_confirm' => $_POST['admin_password_confirm'] ?? '',
                'admin_name' => $_POST['admin_name'] ?? 'Administrator',
                'master_key_path' => $_POST['master_key_path'] ?? $privateLayout['master_key_path'],
                'invoices_path' => $_POST['invoices_path'] ?? $privateLayout['invoices_dir'],
                'production_env' => !empty($_POST['production_env']),
            ]);
            if (!empty($result['ok'])) {
                header('Location: ' . url_base() . '/admin/login.php?installed=1', true, 302);
                exit;
            }
            $error = (string) ($result['message'] ?? 'Installation failed.');
        }
    }
}

layout_public_start([
    'pageTitle' => 'Install Veggie Tokri',
    'showBottomNav' => false,
]);
?>
<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-brand-green">Veggie Tokri installation</h1>
    <p class="mt-2 text-sm text-neutral-600">Guided setup for shared hosting. Use an <strong>empty</strong> MySQL database. Credentials and encryption keys are stored on the server only — never shown in the browser after submit.</p>

    <?php if ($error !== ''): ?>
        <div class="at-alert at-alert--error mt-4" role="alert"><?= Security::escape($error) ?></div>
    <?php endif; ?>
    <?php if ($testDbMessage !== ''): ?>
        <div class="at-alert <?= str_contains($testDbMessage, 'successful') ? 'at-alert--success' : 'at-alert--warning' ?> mt-4" role="status"><?= Security::escape($testDbMessage) ?></div>
    <?php endif; ?>

    <section class="mt-6">
        <h2 class="font-bold text-lg">1. Server requirements</h2>
        <ul class="mt-3 space-y-2">
            <?php foreach ($requirements as $req): ?>
                <li class="flex gap-2 text-sm rounded-lg border p-3 <?= !empty($req['ok']) ? 'border-green-200' : 'border-amber-300' ?>">
                    <span><?= !empty($req['ok']) ? '✓' : '!' ?></span>
                    <span><strong><?= Security::escape((string) $req['label']) ?></strong>
                        <span class="at-muted"> — <?= Security::escape((string) ($req['detail'] ?? '')) ?></span></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <form method="post" class="mt-8 space-y-6" autocomplete="off">
        <?= Csrf::field() ?>

        <section>
            <h2 class="font-bold text-lg">2. Database</h2>
            <p class="text-sm at-muted mt-1">Create an empty database and user in cPanel → MySQL® Databases.</p>
            <div class="grid sm:grid-cols-2 gap-3 mt-3">
                <div><label class="at-label">Host</label><input name="db_host" class="at-input at-focusable" value="<?= Security::escape((string) ($_POST['db_host'] ?? 'localhost')) ?>" required></div>
                <div><label class="at-label">Port</label><input name="db_port" type="number" class="at-input at-focusable" value="<?= Security::escape((string) ($_POST['db_port'] ?? '3306')) ?>"></div>
                <div><label class="at-label">Database name</label><input name="db_name" class="at-input at-focusable" required value="<?= Security::escape((string) ($_POST['db_name'] ?? '')) ?>"></div>
                <div><label class="at-label">Username</label><input name="db_user" class="at-input at-focusable" required value="<?= Security::escape((string) ($_POST['db_user'] ?? '')) ?>"></div>
                <div class="sm:col-span-2"><label class="at-label">Password</label><input name="db_password" type="password" class="at-input at-focusable" autocomplete="new-password"></div>
            </div>
            <button type="submit" name="action" value="test_database" class="at-btn at-btn--secondary at-focusable mt-3">Test connection &amp; check empty</button>
        </section>

        <section>
            <h2 class="font-bold text-lg">3. Private storage (outside public_html)</h2>
            <p class="text-sm at-muted mt-1">Detected home: <code><?= Security::escape($hostingHome ?? 'unknown — parent of document root used') ?></code></p>
            <div class="grid gap-3 mt-3">
                <div><label class="at-label">Invoice encryption key file</label>
                    <input name="master_key_path" class="at-input at-focusable font-mono text-xs" value="<?= Security::escape((string) ($_POST['master_key_path'] ?? $privateLayout['master_key_path'])) ?>">
                    <p class="text-xs at-muted mt-1">Created with mode 0600 only when missing and no invoices exist yet.</p></div>
                <div><label class="at-label">Invoice PDF storage directory</label>
                    <input name="invoices_path" class="at-input at-focusable font-mono text-xs" value="<?= Security::escape((string) ($_POST['invoices_path'] ?? $privateLayout['invoices_dir'])) ?>">
                    <p class="text-xs at-muted mt-1">Directory mode 0750. Must not be inside your public website folder.</p></div>
            </div>
        </section>

        <section>
            <h2 class="font-bold text-lg">4. Administrator account</h2>
            <div class="grid sm:grid-cols-2 gap-3 mt-3">
                <div class="sm:col-span-2"><label class="at-label">Admin email</label><input name="admin_email" type="email" class="at-input at-focusable" required value="<?= Security::escape((string) ($_POST['admin_email'] ?? '')) ?>"></div>
                <div><label class="at-label">Admin name</label><input name="admin_name" class="at-input at-focusable" value="<?= Security::escape((string) ($_POST['admin_name'] ?? 'Administrator')) ?>"></div>
                <div></div>
                <div><label class="at-label">Password</label><input name="admin_password" type="password" class="at-input at-focusable" autocomplete="new-password" required minlength="10"></div>
                <div><label class="at-label">Confirm password</label><input name="admin_password_confirm" type="password" class="at-input at-focusable" autocomplete="new-password" required minlength="10"></div>
            </div>
            <p class="text-xs at-muted mt-2">Minimum 10 characters with letters and numbers.</p>
        </section>

        <section>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="production_env" value="1" <?= !empty($_POST['production_env']) ? 'checked' : '' ?>> Production environment (disables demo commerce and enforces stricter payment rules)</label>
        </section>

        <button type="submit" name="action" value="install" class="at-btn at-btn--primary at-focusable" <?= InstallWizardService::requirementsMet() ? '' : 'disabled' ?>>Install Veggie Tokri</button>
        <p class="text-xs at-muted">Imports <?= Security::escape(basename(InstallWizardService::productionSqlPath())) ?> once, writes config.local.php (0640), and locks the installer.</p>
    </form>
</div>
<?php layout_public_end(); ?>
