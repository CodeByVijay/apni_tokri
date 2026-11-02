<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('offers');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/offers';

admin_layout_start(['pageTitle' => 'Offers', 'activeAdminNav' => 'offers']);
admin_flash_render();
$pageHeading = 'Automatic offers';
$pageDescription = 'One best offer applies per cart (no offer stacking). Coupon stacking controlled per coupon/offer.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4"><a href="<?= Security::escape($base) ?>/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">New offer</a></p>
<?php
$rows = [];
if ($useDb) {
    foreach (OfferRepository::all() as $o) {
        $rows[] = [
            'link' => (string) $o['name'],
            'action_href' => $base . '/edit.php?id=' . (int) $o['id'],
            'scope' => (string) $o['scope'],
            'disc' => ($o['discount_type'] ?? '') === 'percent' ? (float) $o['discount_value'] . '%' : '₹' . (float) $o['discount_value'],
            'active' => !empty($o['is_active']) ? 'Yes' : 'No',
        ];
    }
}
$columns = [
    ['label' => 'Name', 'key' => 'link'],
    ['label' => 'Scope', 'key' => 'scope'],
    ['label' => 'Discount', 'key' => 'disc'],
    ['label' => 'Active', 'key' => 'active'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
