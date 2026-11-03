<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$self = url_base() . '/admin/settings/invoices.php';
$settingsBase = url_base() . '/admin/settings';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = InvoiceSettings::saveFromPost($_POST, $_FILES['logo'] ?? null);
    if (!empty($result['ok'])) {
        admin_flash_set((string) $result['message']);
    } else {
        $_SESSION['invoice_form_errors'] = $result['errors'] ?? [];
        $_SESSION['invoice_form_post'] = $_POST;
        admin_flash_set((string) ($result['message'] ?? 'Could not save.'), 'error');
    }
    header('Location: ' . $self, true, 302);
    exit;
}

$form = InvoiceSettings::formState();
if (!empty($_SESSION['invoice_form_post']) && is_array($_SESSION['invoice_form_post'])) {
    foreach ($_SESSION['invoice_form_post'] as $k => $v) {
        if (is_string($v) && array_key_exists($k, $form)) {
            $form[$k] = trim($v);
        }
    }
    foreach (['fy_numbering', 'show_sku', 'show_delivery_slot', 'show_map_link', 'enable_thermal', 'enable_email', 'auto_email', 'admin_resend'] as $b) {
        $form[$b] = !empty($_SESSION['invoice_form_post'][$b]) ? '1' : '0';
    }
    unset($_SESSION['invoice_form_post']);
}
if (!empty($_SESSION['invoice_form_errors']) && is_array($_SESSION['invoice_form_errors'])) {
    $errors = $_SESSION['invoice_form_errors'];
    unset($_SESSION['invoice_form_errors']);
}
$seller = InvoiceSettings::seller();
$h = static function ($v): string {
    return Security::escape((string) $v);
};
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<p class="text-xs text-red-600 mt-1">' . Security::escape((string) $errors[$k]) . '</p>' : '';
};
$text = static function (string $name, string $label, string $placeholder = '', int $max = 160) use ($form, $h, $err): string {
    return '<div><label class="at-label" for="' . $name . '">' . $h($label) . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" class="at-input at-focusable" maxlength="' . $max . '" value="' . $h($form[$name] ?? '') . '" placeholder="' . $h($placeholder) . '">'
        . $err($name) . '</div>';
};
$check = static function (string $name, string $label, string $help = '') use ($form, $h, $err): string {
    return '<label class="flex gap-2 text-sm items-start"><input type="checkbox" name="' . $name . '" value="1"' . (($form[$name] ?? '0') === '1' ? ' checked' : '') . ' class="mt-1">'
        . '<span>' . $h($label) . ($help !== '' ? '<span class="block text-xs at-muted">' . $h($help) . '</span>' : '') . $err($name) . '</span></label>';
};

admin_layout_start(['pageTitle' => 'Invoice settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'Invoice settings';
$pageDescription = 'Numbering, PDF layout and email behaviour for new invoices. Store name, address and contacts come from Business profile and Website branding.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'invoices';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
$logoPath = InvoiceSettings::logoPath();
?>
<form method="post" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <?= Csrf::field() ?>
    <div class="at-card lg:col-span-2"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Seller details on new invoices</h3>
        <p class="text-sm at-muted">These values are read from <a class="underline font-semibold" href="<?= $h($settingsBase) ?>/index.php">Business profile</a> and <a class="underline font-semibold" href="<?= $h($settingsBase) ?>/branding.php">Website branding</a>. Issued invoice PDFs keep the snapshot from when they were generated. GST: not registered — no GSTIN or tax lines are printed.</p>
        <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="at-muted">Invoice brand name</dt><dd class="font-semibold"><?= $h($seller['brand_name'] ?? '—') ?></dd></div>
            <div><dt class="at-muted">Legal / business name</dt><dd><?= $h($seller['legal_name'] ?? '—') ?></dd></div>
            <div><dt class="at-muted">Tagline</dt><dd><?= $h($seller['tagline'] ?? '—') ?></dd></div>
            <div><dt class="at-muted">FSSAI</dt><dd><?= $h($seller['fssai'] ?? '—') ?></dd></div>
            <div class="sm:col-span-2"><dt class="at-muted">Address</dt><dd><?php
                $addr = array_filter([
                    $seller['address'] ?? '',
                    trim(($seller['city'] ?? '') . ($seller['state'] !== null && $seller['state'] !== '' ? ', ' . $seller['state'] : '')),
                    $seller['pincode'] ?? '',
                ]);
                echo $h($addr !== [] ? implode(' — ', $addr) : '—');
            ?></dd></div>
            <div><dt class="at-muted">Support phone</dt><dd><?= $h($seller['phone'] ?? '—') ?></dd></div>
            <div><dt class="at-muted">Support email</dt><dd><?= $h($seller['email'] ?? '—') ?></dd></div>
            <div class="sm:col-span-2"><dt class="at-muted">Website</dt><dd><?= $h($seller['website'] ?? '—') ?></dd></div>
        </dl>
        <div class="flex flex-wrap items-center gap-4 pt-2 border-t border-[var(--at-line)]">
            <?php if ($logoPath !== null): ?>
                <span class="text-sm">Optional invoice logo is set (overrides the on-PDF monogram).</span>
                <label class="flex gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1"> Remove invoice logo</label>
            <?php else: ?>
                <span class="text-sm at-muted">No separate invoice logo — a brand monogram is printed. Upload one below if you need a dedicated print mark.</span>
            <?php endif; ?>
            <div><label class="at-label" for="logo">Invoice logo (JPEG or PNG, no transparency)</label>
                <input id="logo" type="file" name="logo" accept="image/jpeg,image/png" class="at-input at-focusable !py-2"><?= $err('logo') ?></div>
        </div>
    </div></div>

    <div class="at-card"><div class="at-card__body space-y-4">
        <h3 class="font-bold">Numbering</h3>
        <?= $text('prefix', 'Invoice prefix', 'AT', 8) ?>
        <?= $text('start_sequence', 'Starting sequence (new series only)', '1', 8) ?>
        <?= $check('fy_numbering', 'Financial-year numbering', 'e.g. AT/2026-27/00001 — resets each April.') ?>
        <p class="text-xs at-muted">Credit notes use their own series with a "CN" suffix on the prefix.</p>
    </div></div>

    <div class="at-card"><div class="at-card__body space-y-4">
        <h3 class="font-bold">Content</h3>
        <?= $text('footer', 'Footer message', 'Thank you for shopping with Apni Tokri.', 300) ?>
        <?= $text('return_url', 'Return / refund policy URL', '', 191) ?>
        <?= $text('terms_url', 'Terms & conditions URL', '', 191) ?>
        <?= $check('show_sku', 'Show SKU on items') ?>
        <?= $check('show_delivery_slot', 'Show delivery slot') ?>
        <?= $check('show_map_link', 'Show map link under delivery address') ?>
        <?= $check('enable_thermal', 'Enable compact thermal (80mm) layout for admins') ?>
    </div></div>

    <div class="at-card lg:col-span-2"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Email</h3>
        <?= $check('enable_email', 'Allow emailing invoices to customers', 'Uses the SMTP settings.') ?>
        <?= $check('auto_email', 'Email automatically when an invoice is generated', 'Each invoice is emailed at most once automatically.') ?>
        <?= $check('admin_resend', 'Allow admins to resend invoice emails') ?>
    </div></div>

    <div class="lg:col-span-2">
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save invoice settings</button>
        <p class="text-xs at-muted mt-2">Requires the <code>settings.manage</code> permission.</p>
    </div>
</form>
<?php admin_layout_end(); ?>
