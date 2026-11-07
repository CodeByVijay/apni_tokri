<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$self = url_base() . '/admin/settings/orders.php';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = OrderNumberSettings::saveFromPost($_POST);
    if (!empty($result['ok'])) {
        admin_flash_set((string) $result['message']);
    } else {
        $_SESSION['order_number_form_errors'] = $result['errors'] ?? [];
        $_SESSION['order_number_form_post'] = $_POST;
        admin_flash_set((string) ($result['message'] ?? 'Could not save.'), 'error');
    }
    header('Location: ' . $self, true, 302);
    exit;
}

$form = OrderNumberSettings::adminFormState();
if (!empty($_SESSION['order_number_form_post']) && is_array($_SESSION['order_number_form_post'])) {
    foreach ($_SESSION['order_number_form_post'] as $k => $v) {
        if (is_string($v) && array_key_exists($k, $form)) {
            $form[$k] = trim($v);
        }
    }
    $form['custom_format_enabled'] = !empty($_SESSION['order_number_form_post']['custom_format_enabled']) ? '1' : '0';
    unset($_SESSION['order_number_form_post']);
}
if (!empty($_SESSION['order_number_form_errors']) && is_array($_SESSION['order_number_form_errors'])) {
    $errors = $_SESSION['order_number_form_errors'];
    unset($_SESSION['order_number_form_errors']);
}

$previewNext = OrderNumberService::preview('storefront');
$fyLabel = InvoiceFormat::financialYear(date('Y-m-d'));
$h = static function ($v): string {
    return Security::escape((string) $v);
};
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<p class="text-xs text-red-600 mt-1">' . Security::escape((string) $errors[$k]) . '</p>' : '';
};

admin_layout_start(['pageTitle' => 'Order settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'Order settings';
$pageDescription = 'How new order IDs are generated at checkout. Existing order numbers are never changed. Invoice numbering is configured separately.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'orders';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>
<form method="post" class="grid gap-4 max-w-2xl" id="order-settings-form">
    <?= Csrf::field() ?>
    <div class="at-card"><div class="at-card__body space-y-4">
        <label class="flex gap-2 text-sm items-start">
            <input type="checkbox" name="custom_format_enabled" value="1" id="custom_format_enabled"
                <?= ($form['custom_format_enabled'] ?? '0') === '1' ? ' checked' : '' ?> class="mt-1">
            <span>
                <span class="font-medium">Use custom order ID format</span>
                <span class="block text-xs at-muted">When off, new orders keep the legacy pattern <code>AT-<?= $h(date('ymd')) ?>-RANDOM</code> (subscription: <code>AT-SUB-…</code>).</span>
            </span>
        </label>

        <div id="order-custom-fields" class="space-y-4 <?= ($form['custom_format_enabled'] ?? '0') === '1' ? '' : 'hidden' ?>">
            <div>
                <label class="at-label" for="prefix">Order prefix</label>
                <input id="prefix" name="prefix" class="at-input at-focusable" maxlength="8" autocomplete="off"
                    value="<?= $h($form['prefix'] ?? '') ?>" placeholder="VT">
                <p class="text-xs at-muted mt-1">1–8 letters or digits. Suggested for Veggie Tokri: <code>VT</code> → e.g. <code>VT-<?= $h($fyLabel) ?>-000125</code>.</p>
                <?= $err('prefix') ?>
            </div>
            <div>
                <label class="at-label" for="format">Format</label>
                <select id="format" name="format" class="at-input at-focusable">
                    <option value="<?= OrderNumberSettings::FORMAT_LEGACY_RANDOM ?>"<?= ($form['format'] ?? '') === OrderNumberSettings::FORMAT_LEGACY_RANDOM ? ' selected' : '' ?>>Prefix + date + random suffix</option>
                    <option value="<?= OrderNumberSettings::FORMAT_FY_SEQUENTIAL ?>"<?= ($form['format'] ?? '') === OrderNumberSettings::FORMAT_FY_SEQUENTIAL ? ' selected' : '' ?>>Prefix + financial year + sequential number</option>
                    <option value="<?= OrderNumberSettings::FORMAT_CY_SEQUENTIAL ?>"<?= ($form['format'] ?? '') === OrderNumberSettings::FORMAT_CY_SEQUENTIAL ? ' selected' : '' ?>>Prefix + calendar year + sequential number</option>
                </select>
                <?= $err('format') ?>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="at-label" for="start_sequence">Starting sequence</label>
                    <input id="start_sequence" name="start_sequence" class="at-input at-focusable" inputmode="numeric"
                        value="<?= $h($form['start_sequence'] ?? '1') ?>">
                    <?= $err('start_sequence') ?>
                </div>
                <div>
                    <label class="at-label" for="sequence_digits">Sequence digit length</label>
                    <input id="sequence_digits" name="sequence_digits" class="at-input at-focusable" inputmode="numeric"
                        value="<?= $h($form['sequence_digits'] ?? '6') ?>">
                    <?= $err('sequence_digits') ?>
                </div>
            </div>
            <div>
                <label class="at-label" for="sequence_reset">Reset sequence</label>
                <select id="sequence_reset" name="sequence_reset" class="at-input at-focusable">
                    <option value="<?= OrderNumberSettings::RESET_NEVER ?>"<?= ($form['sequence_reset'] ?? '') === OrderNumberSettings::RESET_NEVER ? ' selected' : '' ?>>Never</option>
                    <option value="<?= OrderNumberSettings::RESET_FISCAL_YEAR ?>"<?= ($form['sequence_reset'] ?? '') === OrderNumberSettings::RESET_FISCAL_YEAR ? ' selected' : '' ?>>Each financial year (Apr–Mar)</option>
                    <option value="<?= OrderNumberSettings::RESET_CALENDAR_YEAR ?>"<?= ($form['sequence_reset'] ?? '') === OrderNumberSettings::RESET_CALENDAR_YEAR ? ' selected' : '' ?>>Each calendar year</option>
                </select>
                <?= $err('sequence_reset') ?>
            </div>
        </div>

        <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-600 p-3">
            <p class="text-xs at-muted mb-1">Live preview (next storefront order)</p>
            <p class="font-mono text-lg font-semibold" id="order-id-preview" data-fy="<?= $h($fyLabel) ?>" data-cy="<?= $h(date('Y')) ?>" data-seq-sample="<?= $h($previewNext) ?>"><?= $h($previewNext) ?></p>
        </div>
    </div></div>
    <div>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save order settings</button>
    </div>
</form>
<script>
(function () {
    var form = document.getElementById('order-settings-form');
    if (!form) return;
    var toggle = document.getElementById('custom_format_enabled');
    var panel = document.getElementById('order-custom-fields');
    var preview = document.getElementById('order-id-preview');
    var ymd = '<?= $h(date('ymd')) ?>';

    function padSeq(n, digits) {
        var s = String(Math.max(1, parseInt(n, 10) || 1));
        while (s.length < digits) s = '0' + s;
        return s;
    }

    function updatePreview() {
        var custom = toggle && toggle.checked;
        if (!custom) {
            preview.textContent = 'AT-' + ymd + '-XXXXXX';
            return;
        }
        var prefix = (document.getElementById('prefix').value || 'AT').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8) || 'AT';
        var format = document.getElementById('format').value;
        var digits = Math.min(10, Math.max(4, parseInt(document.getElementById('sequence_digits').value, 10) || 6));
        if (format === '<?= OrderNumberSettings::FORMAT_LEGACY_RANDOM ?>') {
            preview.textContent = prefix + '-' + ymd + '-XXXXXX';
            return;
        }
        var sample = preview.getAttribute('data-seq-sample') || '';
        var tail = sample.indexOf('-') >= 0 ? sample.split('-').pop() : '';
        var seq = tail && /^\d+$/.test(tail) ? tail : padSeq(document.getElementById('start_sequence').value || '1', digits);
        if (seq.length > digits) seq = seq.slice(-digits);
        if (seq.length < digits) seq = padSeq(seq, digits);
        var yearPart = format === '<?= OrderNumberSettings::FORMAT_CY_SEQUENTIAL ?>'
            ? preview.getAttribute('data-cy')
            : preview.getAttribute('data-fy');
        preview.textContent = prefix + '-' + yearPart + '-' + seq;
    }

    function syncPanel() {
        if (!panel || !toggle) return;
        panel.classList.toggle('hidden', !toggle.checked);
        updatePreview();
    }

    form.addEventListener('input', updatePreview);
    form.addEventListener('change', function () { syncPanel(); });
    if (toggle) toggle.addEventListener('change', syncPanel);
    syncPanel();
})();
</script>
<?php
admin_layout_end();
