<?php

declare(strict_types=1);

/**
 * Shared date preset form for admin reports.
 *
 * @param array<string, mixed> $vars preset, from, to, form_action
 */
function admin_report_filters(array $vars): void
{
    $preset = (string) ($vars['preset'] ?? 'last7');
    $from = (string) ($vars['from'] ?? '');
    $to = (string) ($vars['to'] ?? '');
    $action = (string) ($vars['form_action'] ?? '');
    ?>
    <form method="get" action="<?= Security::escape($action) ?>" class="at-admin-toolbar">
        <div class="contents">
            <label class="text-sm">Period
                <select name="preset" class="at-select at-focusable" id="report-preset">
                    <?php foreach (ReportDateRange::PRESETS as $p): ?>
                        <option value="<?= Security::escape($p) ?>" <?= $preset === $p ? 'selected' : '' ?>><?= Security::escape(str_replace('_', ' ', $p)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm custom-range-field">From
                <input type="date" name="from" class="at-input at-focusable" value="<?= Security::escape($from) ?>">
            </label>
            <label class="text-sm custom-range-field">To
                <input type="date" name="to" class="at-input at-focusable" value="<?= Security::escape($to) ?>">
            </label>
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Apply</button>
        </div>
    </form>
    <script <?= Security::cspNonceAttr() ?>>
    (function () {
        var sel = document.getElementById('report-preset');
        if (!sel) return;
        function toggle() {
            var custom = sel.value === 'custom';
            document.querySelectorAll('.custom-range-field').forEach(function (el) {
                el.style.display = custom ? '' : 'none';
            });
        }
        sel.addEventListener('change', toggle);
        toggle();
    })();
    </script>
    <?php
}

/**
 * @param array<string, mixed> $hidden extra hidden fields for export forms
 */
function admin_report_export_form(string $exportType, ReportDateRange $range, array $hidden = []): void
{
    if (!Permission::can('reports.export')) {
        return;
    }
    ?>
    <form method="post" action="<?= Security::escape(url_base()) ?>/admin/reports/export.php" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="type" value="<?= Security::escape($exportType) ?>">
        <input type="hidden" name="preset" value="<?= Security::escape($range->preset()) ?>">
        <?php if ($range->preset() === 'custom'): ?>
            <input type="hidden" name="from" value="<?= Security::escape($range->customFrom()) ?>">
            <input type="hidden" name="to" value="<?= Security::escape($range->customTo()) ?>">
        <?php endif; ?>
        <?php foreach ($hidden as $k => $v): ?>
            <input type="hidden" name="<?= Security::escape((string) $k) ?>" value="<?= Security::escape((string) $v) ?>">
        <?php endforeach; ?>
        <button type="submit" class="at-btn at-btn--sm at-focusable">Export CSV</button>
    </form>
    <?php
}
