<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/_init.php';

AdminDemoPerms::denyIfCannot('settings');
if (!Install::isSchemaReady() || !AdminAuth::check()) {
    header('Location: ' . url_base() . '/admin/login.php', true, 302);
    exit;
}

$message = '';
$error = '';
$layout = InstallHostingPaths::defaultPrivateLayout();
$currentKey = InvoiceProductionSecurity::configuredMasterKeyPath();
$currentInv = InvoiceProductionSecurity::configuredInvoicesPath();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $masterKeyPath = trim((string) ($_POST['master_key_path'] ?? ''));
    $invoicesPath = trim((string) ($_POST['invoices_path'] ?? ''));
    if ($masterKeyPath === '' || $invoicesPath === '') {
        $error = 'Both paths are required.';
    } elseif (!InstallHostingPaths::isOutsideDocumentRoot($masterKeyPath) || !InstallHostingPaths::isOutsideDocumentRoot($invoicesPath)) {
        $error = 'Paths must be outside the web document root.';
    } else {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $invDir = InstallHostingPaths::ensurePrivateDirectory($invoicesPath, 0750);
        if (!$invDir['ok']) {
            $error = (string) ($invDir['message'] ?? 'Invoice directory error.');
        } else {
            $keyPrep = InstallWizardService::prepareEncryptionKey($masterKeyPath, $pdo);
            if (!$keyPrep['ok']) {
                $error = (string) ($keyPrep['message'] ?? 'Encryption key error.');
            } else {
                $path = InstallWizardService::configLocalPath();
                if (!is_readable($path)) {
                    $error = 'config.local.php is missing. Restore configuration from backup or complete server setup manually.';
                } else {
                    /** @var array<string, mixed> $cfg */
                    $cfg = require $path;
                    if (!is_array($cfg)) {
                        $error = 'config.local.php is invalid.';
                    } else {
                        $cfg['encryption']['master_key_path'] = $masterKeyPath;
                        $cfg['storage']['invoices_path'] = $invoicesPath;
                        $export = var_export($cfg, true);
                        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $export . ";\n";
                        if (@file_put_contents($path, $php, LOCK_EX) === false) {
                            $error = 'Could not update config.local.php.';
                        } else {
                            @chmod($path, 0640);
                            AuditLog::write('settings.install_repair', null, null, [
                                'master_key_updated' => is_file($masterKeyPath) ? 1 : 0,
                                'invoices_path' => $invoicesPath,
                            ]);
                            $message = 'Storage paths updated. Existing encryption key was preserved when present.';
                            $currentKey = $masterKeyPath;
                            $currentInv = $invoicesPath;
                        }
                    }
                }
            }
        }
    }
}

admin_layout_start(['pageTitle' => 'System storage repair', 'activeAdminNav' => 'settings']);
$pageHeading = 'System storage repair';
$pageDescription = 'Configure private invoice storage and encryption paths without running the public installer.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<div class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <?php if ($message !== ''): ?><div class="at-alert at-alert--success"><?= Security::escape($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="at-alert at-alert--error"><?= Security::escape($error) ?></div><?php endif; ?>
        <p class="text-sm at-muted">Use this when the database is already installed but invoice encryption paths were never set for production. A new master key is <strong>not</strong> created if invoice rows already exist.</p>
        <form method="post" class="space-y-3">
            <?= Csrf::field() ?>
            <div><label class="at-label">Master key path</label>
                <input name="master_key_path" class="at-input at-focusable font-mono text-xs" value="<?= Security::escape($currentKey !== '' ? $currentKey : $layout['master_key_path']) ?>"></div>
            <div><label class="at-label">Invoices directory</label>
                <input name="invoices_path" class="at-input at-focusable font-mono text-xs" value="<?= Security::escape($currentInv !== '' ? $currentInv : $layout['invoices_dir']) ?>"></div>
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save paths</button>
        </form>
    </div>
</div>
<?php admin_layout_end(); ?>
