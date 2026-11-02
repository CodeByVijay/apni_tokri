<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('coupons');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/coupons';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && $useDb) {
    Csrf::requireValid();
    $delId = (int) $_POST['delete_id'];
    Database::pdo()->prepare('DELETE FROM coupons WHERE id = ?')->execute([$delId]);
    admin_flash_set('Coupon deleted.');
    header('Location: ' . $base . '/index.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Coupons', 'activeAdminNav' => 'coupons']);
admin_flash_render();
$pageHeading = 'Coupons';
$pageDescription = 'Codes, limits, eligibility and stacking rules — validated on the server at checkout.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4"><a href="<?= Security::escape($base) ?>/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">New coupon</a>
<a href="<?= Security::escape(url_base()) ?>/admin/reports/coupons.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Usage report</a></p>
<?php
$rows = [];
if ($useDb) {
    foreach (CouponRepository::all() as $c) {
        $disc = ($c['discount_type'] ?? '') === 'percent'
            ? (float) $c['discount_value'] . '%'
            : '₹' . number_format((float) $c['discount_value'], 0);
        $limit = $c['usage_limit'] !== null ? (int) $c['used_count'] . '/' . (int) $c['usage_limit'] : (int) $c['used_count'] . '/∞';
        $rows[] = [
            'link' => (string) $c['code'],
            'action_href' => $base . '/edit.php?id=' . (int) $c['id'],
            'disc' => $disc,
            'uses' => $limit,
            'active' => !empty($c['is_active']) ? 'Yes' : 'No',
        ];
    }
}
if ($rows === []) {
    $rows = [['code' => 'WELCOME10 (seed)', 'disc' => '10%', 'uses' => 'demo', 'active' => 'Yes', 'edit' => $base . '/edit.php']];
}
$columns = [
    ['label' => 'Code', 'key' => 'link'],
    ['label' => 'Discount', 'key' => 'disc'],
    ['label' => 'Uses', 'key' => 'uses', 'align' => 'num'],
    ['label' => 'Active', 'key' => 'active'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb && $rows !== []): ?>
<p class="text-xs at-muted mt-2">Click a row’s edit link from the list above via <a href="<?= Security::escape($base) ?>/edit.php">coupon editor</a>.</p>
<?php endif;
admin_layout_end();
