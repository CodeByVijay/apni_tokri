<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');
$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        $save = PaymentGatewaySettingsService::saveFromPost($_POST);
        if (!empty($save['ok'])) {
            admin_flash_set('Payment gateway settings saved.');
        } else {
            admin_flash_set($save['message'] ?? 'Could not save gateway settings.', 'error');
        }
    } else {
        admin_flash_set('Payment gateway settings saved (demo UI).');
    }
    header('Location: ' . url_base() . '/admin/settings/gateways.php', true, 302);
    exit;
}

$active = AppSettings::get('payment.active_gateway', 'simulate') ?? 'simulate';
$testMode = (AppSettings::get('payment.test_mode', '1') ?? '1') !== '0';
$rzpEnabled = (AppSettings::get('razorpay.enabled', '0') ?? '0') === '1';
$cfEnabled = (AppSettings::get('cashfree.enabled', '0') ?? '0') === '1';
$rzpKeyId = AppSettings::get('razorpay.key_id', '') ?? '';
$cfAppId = AppSettings::get('cashfree.app_id', '') ?? '';
$secrets = PaymentGatewaySettingsService::secretPresence();
$gwStatus = PaymentGatewaySettingsService::gatewayStatusSummary();
$renderGwDiag = static function (array $diag): string {
    $cls = 'at-muted';
    if (($diag['api_state'] ?? '') === PaymentGatewayDiagnosticState::API_VERIFIED) {
        $cls = 'text-[var(--at-success)]';
    } elseif (($diag['api_state'] ?? '') === PaymentGatewayDiagnosticState::API_AUTH_FAILED) {
        $cls = 'text-[var(--at-danger)]';
    } elseif (($diag['config_state'] ?? '') !== PaymentGatewayDiagnosticState::CONFIG_READY) {
        $cls = 'text-[var(--at-yellow-ink)]';
    }
    $head = Security::escape((string) ($diag['config_label'] ?? ''));
    if (!empty($diag['api_label'])) {
        $head .= ' · ' . Security::escape((string) $diag['api_label']);
    }
    return '<span class="block font-semibold ' . $cls . '">' . $head . '</span>'
        . '<span class="block at-muted mt-1">' . Security::escape((string) ($diag['detail'] ?? '')) . '</span>';
};

admin_layout_start(['pageTitle' => 'Payment gateways', 'activeAdminNav' => 'settings']);
admin_flash_render();
$pageHeading = 'Payment gateways';
$pageDescription = 'Razorpay & Cashfree — test/live mode, encrypted secrets, webhook URLs below.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$whBase = url_base() . '/webhooks/';
$settingsTab = 'gateways';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<div class="at-card max-w-2xl mb-4">
    <div class="at-card__body text-sm space-y-3">
        <h3 class="font-bold">Status overview</h3>
        <ul class="grid sm:grid-cols-2 gap-2 text-xs">
            <li class="p-3 rounded border border-neutral-200">
                <strong>Active:</strong> <?= Security::escape(PaymentGatewayConfig::gatewayLabel((string) $gwStatus['active'])) ?>
                <?php if (!empty($gwStatus['test_mode'])): ?><span class="at-badge">Test mode</span><?php else: ?><span class="at-badge at-badge--warning">Live mode</span><?php endif; ?>
                <?php if (!empty($gwStatus['simulate_fallback'])): ?><p class="at-muted mt-1">Storefront is using simulated checkout until the active gateway is fully configured.</p><?php endif; ?>
            </li>
            <li class="p-3 rounded border border-neutral-200">
                <strong>Razorpay</strong>
                <?= !empty($gwStatus['razorpay']['enabled']) ? 'Enabled' : 'Disabled' ?>
                <span class="block at-muted">Key ID <?= !empty($gwStatus['razorpay']['key_id_set']) ? 'set' : 'missing' ?> · Secret <?= !empty($gwStatus['razorpay']['secret_set']) ? 'saved (encrypted)' : 'missing' ?></span>
                <?= $renderGwDiag($gwStatus['razorpay']['diagnostic'] ?? []) ?>
            </li>
            <li class="p-3 rounded border border-neutral-200 sm:col-span-2">
                <strong>Cashfree</strong>
                <?= !empty($gwStatus['cashfree']['enabled']) ? 'Enabled' : 'Disabled' ?>
                · API host: <?= PaymentGatewayConfig::isTestMode() ? 'sandbox' : 'production' ?>
                <span class="block at-muted">App ID <?= !empty($gwStatus['cashfree']['app_id_set']) ? 'set' : 'missing' ?> · Secret <?= !empty($gwStatus['cashfree']['secret_set']) ? 'saved (encrypted)' : 'missing' ?></span>
                <?= $renderGwDiag($gwStatus['cashfree']['diagnostic'] ?? []) ?>
            </li>
        </ul>
        <p class="text-xs at-muted">“Configuration ready” means credentials are present and pass format checks only. Gateway authentication is confirmed only after a real customer checkout successfully creates a payment order via the API (or records HTTP 401 on failure). We do not run standalone credential health checks.</p>
        <p><strong>Webhook URLs</strong> (configure in gateway dashboards). Cashfree signs webhooks with your <strong>client secret</strong> unless you set a separate webhook secret below.</p>
        <ul class="list-disc pl-5 at-muted">
            <li>Razorpay: <code class="text-xs"><?= Security::escape($whBase) ?>razorpay.php</code></li>
            <li>Cashfree: <code class="text-xs"><?= Security::escape($whBase) ?>cashfree.php</code></li>
        </ul>
        <p class="text-xs at-muted">Saved secrets are encrypted and never shown after save. Leave secret fields blank to keep existing values.</p>
    </div>
</div>

<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="at-label" for="active_gateway">Active gateway</label>
            <select id="active_gateway" name="active_gateway" class="at-input at-focusable">
                <option value="simulate" <?= $active === 'simulate' ? 'selected' : '' ?>>Simulate (dev / no credentials)</option>
                <option value="razorpay" <?= $active === 'razorpay' ? 'selected' : '' ?>>Razorpay</option>
                <option value="cashfree" <?= $active === 'cashfree' ? 'selected' : '' ?>>Cashfree</option>
            </select>
        </div>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="test_mode" value="1" <?= $testMode ? 'checked' : '' ?>> Test / sandbox mode</label>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Razorpay</h3>
        <label class="flex gap-2 text-sm mb-2"><input type="checkbox" name="razorpay_enabled" value="1" <?= $rzpEnabled ? 'checked' : '' ?>> Enabled</label>
        <div>
            <label class="at-label" for="razorpay_key_id">Key ID</label>
            <input id="razorpay_key_id" class="at-input at-focusable" name="razorpay_key_id" value="<?= Security::escape($rzpKeyId) ?>" autocomplete="off">
        </div>
        <?php
        $credId = 'razorpay_key_secret';
        $credName = 'razorpay_key_secret';
        $credLabel = 'Key secret';
        $credSaved = $secrets['razorpay_key_secret'];
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';

        $credId = 'razorpay_webhook_secret';
        $credName = 'razorpay_webhook_secret';
        $credLabel = 'Webhook secret';
        $credSaved = $secrets['razorpay_webhook_secret'];
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Cashfree</h3>
        <label class="flex gap-2 text-sm mb-2"><input type="checkbox" name="cashfree_enabled" value="1" <?= $cfEnabled ? 'checked' : '' ?>> Enabled</label>
        <div>
            <label class="at-label" for="cashfree_app_id">App ID</label>
            <input id="cashfree_app_id" class="at-input at-focusable" name="cashfree_app_id" value="<?= Security::escape($cfAppId) ?>" autocomplete="off">
        </div>
        <?php
        $credId = 'cashfree_secret_key';
        $credName = 'cashfree_secret_key';
        $credLabel = 'Secret key';
        $credSaved = $secrets['cashfree_secret_key'];
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';

        $credId = 'cashfree_webhook_secret';
        $credName = 'cashfree_webhook_secret';
        $credLabel = 'Webhook secret';
        $credSaved = $secrets['cashfree_webhook_secret'];
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>

        <button type="submit" class="at-btn at-btn--primary at-focusable">Save gateways</button>
    </div>
</form>
<?php admin_layout_end(); ?>
