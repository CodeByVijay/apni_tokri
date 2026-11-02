<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('reports');

admin_layout_start(['pageTitle' => 'Coupon report', 'activeAdminNav' => 'reports']);
$pageHeading = 'Coupon usage report';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$rows = [];
if (Install::isSchemaReady() && AdminAuth::check()) {
    foreach (CouponRepository::usageReport() as $r) {
        $rows[] = [
            'code' => (string) $r['code'],
            'used' => (string) ($r['used_count'] ?? 0),
            'limit' => $r['usage_limit'] !== null ? (string) $r['usage_limit'] : '∞',
            'discount' => catalogue_format_price((float) ($r['discount_given'] ?? 0)),
        ];
    }
}
$columns = [
    ['label' => 'Code', 'key' => 'code'],
    ['label' => 'Used count', 'key' => 'used', 'align' => 'num'],
    ['label' => 'Limit', 'key' => 'limit', 'align' => 'num'],
    ['label' => 'Discount on orders', 'key' => 'discount', 'align' => 'num'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
?>
<p class="mt-4"><a href="<?= Security::escape(url_base()) ?>/admin/reports/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">All reports</a></p>
<?php admin_layout_end(); ?>
