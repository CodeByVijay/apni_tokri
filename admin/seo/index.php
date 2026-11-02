<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('seo');
admin_handle_demo_post('Redirect saved (demo).');

admin_layout_start(['pageTitle' => 'SEO & redirects', 'activeAdminNav' => 'seo']);
admin_flash_render();
$pageHeading = 'SEO settings & 301 redirects';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card mb-4">
    <div class="at-card__body space-y-3">
        <h3 class="font-bold">Add redirect</h3>
        <?= Csrf::field() ?>
        <div><label class="at-label">Old path</label><input class="at-input at-focusable" name="from" placeholder="/old-product-slug"></div>
        <div><label class="at-label">New path</label><input class="at-input at-focusable" name="to" placeholder="/fresh-red-tomatoes"></div>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save 301</button>
    </div>
</form>
<?php
$columns = [['label' => 'From', 'key' => 'from'], ['label' => 'To', 'key' => 'to']];
$rows = [['from' => '/tomato', 'to' => '/fresh-red-tomatoes']];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
?>
<p class="text-sm text-neutral-600 mt-4">Sitemap generation and structured data controls connect in Stage 13.</p>
<?php admin_layout_end(); ?>
