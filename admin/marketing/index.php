<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('marketing');
admin_handle_demo_post('Marketing settings saved (demo). Section 32 — ethical conversion only.');

admin_layout_start(['pageTitle' => 'Marketing & conversion', 'activeAdminNav' => 'marketing']);
admin_flash_render();
$pageHeading = 'Marketing & conversion settings';
$pageDescription = 'Real stock, real deadlines, no fake social proof.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="space-y-4">
    <?= Csrf::field() ?>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Low stock badges</h3>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="low_stock" checked> Show low stock from actual inventory</label>
        <div><label class="at-label">Default threshold</label><input class="at-input at-focusable w-32" value="10"></div>
    </div></div>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Free delivery threshold</h3>
        <input class="at-input at-focusable w-40" name="free_threshold" value="499">
    </div></div>
    <div class="at-card"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Offer countdown</h3>
        <p class="text-xs text-neutral-600">Deadline stored server-side (Asia/Kolkata).</p>
        <input type="datetime-local" class="at-input at-focusable" name="offer_end">
    </div></div>
    <button type="submit" class="at-btn at-btn--primary at-focusable">Save settings</button>
</form>
<?php admin_layout_end(); ?>
