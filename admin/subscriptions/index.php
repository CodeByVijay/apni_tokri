<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('subscriptions');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/subscriptions';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clone_id']) && $useDb) {
    Csrf::requireValid();
    $newId = SubscriptionPlanRepository::clonePlan((int) $_POST['clone_id']);
    admin_flash_set($newId ? 'Plan cloned.' : 'Could not clone plan.', $newId ? 'success' : 'error');
    header('Location: ' . $base . '/index.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Subscription plans', 'activeAdminNav' => 'subscriptions']);
admin_flash_render();
$pageHeading = 'Subscription plans';
$pageDescription = 'Custom durations, delivery count, frequency and basket rules. Default 7/15/30 templates are editable.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/plan-edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add plan</a>'
    . ' <a href="' . Security::escape($base) . '/menu-planner.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Menu planner</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$rows = [];
if ($useDb) {
    foreach (SubscriptionPlanRepository::allForAdmin() as $p) {
        $rows[] = [
            'link' => (string) $p['name'],
            'action_href' => $base . '/plan-edit.php?id=' . (int) $p['id'],
            'calendar' => (int) $p['calendar_duration_days'] . ' days',
            'deliveries' => (string) (int) $p['delivery_count'],
            'freq' => (string) $p['frequency_type'],
            'price' => catalogue_format_price((float) $p['price']),
            'active' => !empty($p['is_active']) ? 'Yes' : 'No',
        ];
    }
}
$columns = [
    ['label' => 'Plan', 'key' => 'link'],
    ['label' => 'Calendar', 'key' => 'calendar'],
    ['label' => 'Deliveries', 'key' => 'deliveries', 'align' => 'num'],
    ['label' => 'Frequency', 'key' => 'freq'],
    ['label' => 'Price', 'key' => 'price', 'align' => 'num'],
    ['label' => 'Active', 'key' => 'active'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb): ?>
<form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
    <?= Csrf::field() ?>
    <div><label class="at-label">Clone plan ID</label><input name="clone_id" class="at-input at-focusable w-24" type="number" min="1"></div>
    <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Clone</button>
</form>
<?php endif;
admin_layout_end();
