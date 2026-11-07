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
    if (isset($_SESSION['order_number_form_post']['format']) && is_string($_SESSION['order_number_form_post']['format'])) {
        $form['format'] = OrderNumberSettings::normalizeFormat(trim($_SESSION['order_number_form_post']['format']));
    }
    unset($_SESSION['order_number_form_post']);
}
if (!empty($_SESSION['order_number_form_errors']) && is_array($_SESSION['order_number_form_errors'])) {
    $errors = $_SESSION['order_number_form_errors'];
    unset($_SESSION['order_number_form_errors']);
}

$form['format'] = OrderNumberSettings::normalizeFormat((string) ($form['format'] ?? ''));
$isSequential = OrderNumberSettings::isSequentialFormat($form['format']);
$previewNext = OrderNumberService::preview('storefront');
$fyLabel = InvoiceFormat::financialYear(date('Y-m-d'));
$h = static function ($v): string {
    return Security::escape((string) $v);
};
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<p class="text-xs text-red-600 mt-1" id="' . Security::escape($k) . '-error">' . Security::escape((string) $errors[$k]) . '</p>' : '';
};

admin_layout_start(['pageTitle' => 'Order settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'Order settings';
$pageDescription = 'How new order IDs are generated at checkout. Existing order numbers are never changed. Invoice numbering is configured separately.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'orders';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';

$fmtDateRandom = OrderNumberSettings::FORMAT_DATE_RANDOM;
$fmtFy = OrderNumberSettings::FORMAT_FY_SEQUENCE;
$fmtCy = OrderNumberSettings::FORMAT_CY_SEQUENCE;
$resetFy = OrderNumberSettings::RESET_FISCAL_YEAR;
$resetCy = OrderNumberSettings::RESET_CALENDAR_YEAR;
?>
<form method="post" class="grid gap-4 max-w-2xl" id="order-settings-form">
    <?= Csrf::field() ?>
    <div class="at-card"><div class="at-card__body space-y-4">
        <label class="flex gap-2 text-sm items-start" for="custom_format_enabled">
            <input type="checkbox" name="custom_format_enabled" value="1" id="custom_format_enabled"
                <?= ($form['custom_format_enabled'] ?? '0') === '1' ? ' checked' : '' ?> class="mt-1">
            <span>
                <span class="font-medium">Use custom order ID format</span>
                <span class="block text-xs at-muted">When off, new orders keep the legacy pattern <code>AT-<?= $h(date('ymd')) ?>-RANDOM</code> (subscription: <code>AT-SUB-…</code>).</span>
            </span>
        </label>

        <div id="order-custom-fields" class="space-y-4 <?= ($form['custom_format_enabled'] ?? '0') === '1' ? '' : 'hidden' ?>" aria-hidden="<?= ($form['custom_format_enabled'] ?? '0') === '1' ? 'false' : 'true' ?>">
            <div>
                <label class="at-label" for="prefix">Order prefix</label>
                <input id="prefix" name="prefix" class="at-input at-focusable" maxlength="8" autocomplete="off"
                    value="<?= $h($form['prefix'] ?? '') ?>" placeholder="VT"
                    <?= ($form['custom_format_enabled'] ?? '0') !== '1' ? ' disabled' : '' ?>>
                <p class="text-xs at-muted mt-1">1–8 letters or digits. Suggested for Veggie Tokri: <code>VT</code> → e.g. <code>VT-<?= $h($fyLabel) ?>-000125</code>.</p>
                <?= $err('prefix') ?>
            </div>
            <div>
                <label class="at-label" for="format">Format</label>
                <select id="format" name="format" class="at-input at-focusable"
                    <?= ($form['custom_format_enabled'] ?? '0') !== '1' ? ' disabled' : '' ?>>
                    <option value="<?= $h($fmtDateRandom) ?>"<?= ($form['format'] ?? '') === $fmtDateRandom ? ' selected' : '' ?>>Prefix + date + random suffix</option>
                    <option value="<?= $h($fmtFy) ?>"<?= ($form['format'] ?? '') === $fmtFy ? ' selected' : '' ?>>Prefix + financial year + sequential number</option>
                    <option value="<?= $h($fmtCy) ?>"<?= ($form['format'] ?? '') === $fmtCy ? ' selected' : '' ?>>Prefix + calendar year + sequential number</option>
                </select>
                <?= $err('format') ?>
            </div>
            <div id="order-sequential-fields" class="space-y-4 <?= $isSequential ? '' : 'hidden' ?>" aria-hidden="<?= $isSequential ? 'false' : 'true' ?>">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="at-label" for="start_sequence">Starting sequence</label>
                        <input id="start_sequence" name="start_sequence" class="at-input at-focusable" inputmode="numeric"
                            min="1" step="1" pattern="[1-9][0-9]*" autocomplete="off"
                            value="<?= $h($form['start_sequence'] ?? '1') ?>"
                            <?= !$isSequential || ($form['custom_format_enabled'] ?? '0') !== '1' ? ' disabled' : '' ?>>
                        <?= $err('start_sequence') ?>
                    </div>
                    <div>
                        <label class="at-label" for="sequence_digits">Sequence digit length</label>
                        <input id="sequence_digits" name="sequence_digits" class="at-input at-focusable" inputmode="numeric"
                            type="number" min="4" max="10" step="1"
                            value="<?= $h($form['sequence_digits'] ?? (string) OrderNumberSettings::DEFAULT_SEQUENCE_DIGITS) ?>"
                            <?= !$isSequential || ($form['custom_format_enabled'] ?? '0') !== '1' ? ' disabled' : '' ?>>
                        <?= $err('sequence_digits') ?>
                    </div>
                </div>
                <div>
                    <label class="at-label" for="sequence_reset">Reset sequence</label>
                    <select id="sequence_reset" name="sequence_reset" class="at-input at-focusable"
                        <?= !$isSequential || ($form['custom_format_enabled'] ?? '0') !== '1' ? ' disabled' : '' ?>>
                        <option value="<?= OrderNumberSettings::RESET_NEVER ?>"<?= ($form['sequence_reset'] ?? '') === OrderNumberSettings::RESET_NEVER ? ' selected' : '' ?>>Never</option>
                        <option value="<?= $h($resetFy) ?>"<?= ($form['sequence_reset'] ?? '') === $resetFy ? ' selected' : '' ?>>Each financial year (Apr–Mar)</option>
                        <option value="<?= $h($resetCy) ?>"<?= ($form['sequence_reset'] ?? '') === $resetCy ? ' selected' : '' ?>>Each calendar year</option>
                    </select>
                    <?= $err('sequence_reset') ?>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-600 p-3">
            <p class="text-xs at-muted mb-1" id="order-preview-label">Live preview (next storefront order)</p>
            <p class="font-mono text-lg font-semibold" id="order-id-preview" aria-labelledby="order-preview-label"
                data-fy="<?= $h($fyLabel) ?>" data-cy="<?= $h(date('Y')) ?>"
                data-seq-sample="<?= $h($previewNext) ?>"
                data-fmt-date="<?= $h($fmtDateRandom) ?>"
                data-fmt-fy="<?= $h($fmtFy) ?>"
                data-fmt-cy="<?= $h($fmtCy) ?>"
                data-reset-fy="<?= $h($resetFy) ?>"
                data-reset-cy="<?= $h($resetCy) ?>"><?= $h($previewNext) ?></p>
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
    var sequential = document.getElementById('order-sequential-fields');
    var preview = document.getElementById('order-id-preview');
    var formatEl = document.getElementById('format');
    var resetEl = document.getElementById('sequence_reset');
    var ymd = '<?= $h(date('ymd')) ?>';
    var fmtDate = preview.getAttribute('data-fmt-date');
    var fmtFy = preview.getAttribute('data-fmt-fy');
    var fmtCy = preview.getAttribute('data-fmt-cy');
    var resetFy = preview.getAttribute('data-reset-fy');
    var resetCy = preview.getAttribute('data-reset-cy');

    function clampDigits(raw) {
        var d = parseInt(raw, 10);
        if (isNaN(d) || d < 4) return 4;
        if (d > 10) return 10;
        return d;
    }

    function padSeq(n, digits) {
        var num = Math.max(1, parseInt(String(n).replace(/\D/g, ''), 10) || 1);
        var s = String(num);
        while (s.length < digits) s = '0' + s;
        if (s.length > digits) s = s.slice(-digits);
        return s;
    }

    function isSequentialFormat(fmt) {
        return fmt === fmtFy || fmt === fmtCy;
    }

    function setSequentialEnabled(on) {
        if (!sequential) return;
        sequential.classList.toggle('hidden', !on);
        sequential.setAttribute('aria-hidden', on ? 'false' : 'true');
        ['start_sequence', 'sequence_digits', 'sequence_reset'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.disabled = !on || !(toggle && toggle.checked);
        });
    }

    function setCustomFieldsEnabled(on) {
        if (!panel) return;
        panel.classList.toggle('hidden', !on);
        panel.setAttribute('aria-hidden', on ? 'false' : 'true');
        ['prefix', 'format'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.disabled = !on;
        });
        if (!on) {
            setSequentialEnabled(false);
            return;
        }
        syncFormatUi(false);
    }

    function applyResetDefaultForFormat(fmt, force) {
        if (!resetEl || !isSequentialFormat(fmt)) return;
        if (fmt === fmtFy && (force || resetEl.value === resetCy)) {
            resetEl.value = resetFy;
        } else if (fmt === fmtCy && (force || resetEl.value === resetFy)) {
            resetEl.value = resetCy;
        }
    }

    function syncFormatUi(fromUserChange) {
        if (!formatEl || !(toggle && toggle.checked)) return;
        var fmt = formatEl.value;
        var seq = isSequentialFormat(fmt);
        setSequentialEnabled(seq);
        if (fromUserChange) {
            if (fmt === fmtFy) {
                resetEl.value = resetFy;
            } else if (fmt === fmtCy) {
                resetEl.value = resetCy;
            }
        } else {
            applyResetDefaultForFormat(fmt, false);
        }
        updatePreview();
    }

    function updatePreview() {
        if (!preview) return;
        if (!toggle || !toggle.checked) {
            preview.textContent = 'AT-' + ymd + '-XXXXXX';
            return;
        }
        var prefixInput = document.getElementById('prefix');
        var prefix = ((prefixInput && prefixInput.value) || 'AT').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8) || 'AT';
        var fmt = formatEl ? formatEl.value : fmtDate;
        if (fmt === fmtDate) {
            preview.textContent = prefix + '-' + ymd + '-XXXXXX';
            return;
        }
        var digits = clampDigits(document.getElementById('sequence_digits').value);
        var startInput = document.getElementById('start_sequence');
        var startVal = (startInput && startInput.value) ? startInput.value : '1';
        var seq = padSeq(startVal, digits);
        var yearPart = fmt === fmtCy ? preview.getAttribute('data-cy') : preview.getAttribute('data-fy');
        preview.textContent = prefix + '-' + yearPart + '-' + seq;
    }

    if (toggle) {
        toggle.addEventListener('change', function () {
            setCustomFieldsEnabled(toggle.checked);
            updatePreview();
        });
    }
    if (formatEl) {
        formatEl.addEventListener('change', function () {
            syncFormatUi(true);
        });
    }
    form.addEventListener('input', updatePreview);
    form.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'format') return;
        updatePreview();
    });

    setCustomFieldsEnabled(toggle && toggle.checked);
    updatePreview();
})();
</script>
<?php
admin_layout_end();
