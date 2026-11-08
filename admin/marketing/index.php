<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('marketing');
$useDb = AdminPersistence::isAvailable();
$form = MarketingSettings::adminFormState();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_persistence_blocked('Marketing settings saved (demo). Section 32 — ethical conversion only.');
        header('Location: ' . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), true, 302);
        exit;
    }
    $result = MarketingSettings::saveFromPost($_POST);
    if ($result['ok']) {
        AuditLog::write('marketing.settings.save', 'settings', null, ['keys' => 'marketing.*']);
        admin_flash_set((string) ($result['message'] ?? 'Marketing settings saved.'));
        header('Location: ' . url_base() . '/admin/marketing/index.php', true, 302);
        exit;
    }
    admin_flash_set((string) ($result['message'] ?? 'Could not save.'), 'error');
    $errors = $result['errors'] ?? [];
    $form = array_merge($form, [
        'low_stock' => isset($_POST['low_stock']),
        'threshold' => (int) ($_POST['threshold'] ?? $form['threshold']),
        'free_threshold' => trim((string) ($_POST['free_threshold'] ?? '')),
        'offer_end_local' => trim((string) ($_POST['offer_end'] ?? '')),
        'min_order' => isset($_POST['min_order']),
        'min_order_amount' => trim((string) ($_POST['min_order_amount'] ?? '')),
        'min_order_message' => trim((string) ($_POST['min_order_message'] ?? '')),
    ]);
} elseif ($useDb) {
    $form = MarketingSettings::adminFormState();
}

admin_layout_start(['pageTitle' => 'Marketing & conversion', 'activeAdminNav' => 'marketing']);
$pageHeading = 'Marketing & conversion settings';
$pageDescription = 'Real stock, real deadlines, no fake social proof.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
AdminPersistence::renderUnavailableAlert();
?>
<form method="post" class="space-y-4">
    <?= Csrf::field() ?>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Low stock badges</h3>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="low_stock"<?= $form['low_stock'] ? ' checked' : '' ?><?= $useDb ? '' : ' disabled' ?>> Show low stock from actual inventory</label>
        <div>
            <label class="at-label">Default threshold</label>
            <input class="at-input at-focusable w-32" name="threshold" type="number" min="0" max="9999" value="<?= (int) $form['threshold'] ?>"<?= $useDb ? '' : ' disabled' ?>>
            <?php if (isset($errors['threshold'])): ?>
                <p class="at-field-error"><?= Security::escape($errors['threshold']) ?></p>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Free delivery threshold</h3>
        <input class="at-input at-focusable w-40" name="free_threshold" type="number" min="1" step="0.01" value="<?= Security::escape($form['free_threshold']) ?>"<?= $useDb ? '' : ' disabled' ?>>
        <?php if (isset($errors['free_threshold'])): ?>
            <p class="at-field-error"><?= Security::escape($errors['free_threshold']) ?></p>
        <?php endif; ?>
    </div></div>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Minimum order amount</h3>
        <label class="flex gap-2 text-sm">
            <input type="checkbox" name="min_order"<?= !empty($form['min_order']) ? ' checked' : '' ?><?= $useDb ? '' : ' disabled' ?>>
            Enable minimum order amount
        </label>
        <div>
            <label class="at-label">Minimum order amount (INR)</label>
            <input class="at-input at-focusable w-40" name="min_order_amount" type="number" min="1" step="0.01"
                   value="<?= Security::escape((string) ($form['min_order_amount'] ?? '')) ?>"<?= $useDb ? '' : ' disabled' ?>>
            <?php if (isset($errors['min_order_amount'])): ?>
                <p class="at-field-error"><?= Security::escape($errors['min_order_amount']) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <label class="at-label">Customer-facing validation message</label>
            <textarea class="at-textarea at-focusable" name="min_order_message" rows="2" maxlength="500"<?= $useDb ? '' : ' disabled' ?>><?= Security::escape((string) ($form['min_order_message'] ?? '')) ?></textarea>
            <p class="text-xs at-muted mt-1">Use <code>{amount}</code> for the configured minimum. Minimum is compared to merchandise after product sale prices, automatic offers and coupons — delivery fee is excluded.</p>
            <?php if (isset($errors['min_order_message'])): ?>
                <p class="at-field-error"><?= Security::escape($errors['min_order_message']) ?></p>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Offer countdown</h3>
        <p class="text-xs text-neutral-600">Deadline stored server-side (Asia/Kolkata). Leave empty to hide countdown.</p>
        <input type="datetime-local" class="at-input at-focusable" name="offer_end" value="<?= Security::escape($form['offer_end_local']) ?>"<?= $useDb ? '' : ' disabled' ?>>
        <?php if (isset($errors['offer_end'])): ?>
            <p class="at-field-error"><?= Security::escape($errors['offer_end']) ?></p>
        <?php endif; ?>
    </div></div>
    <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save settings</button>
</form>
<?php admin_layout_end(); ?>
