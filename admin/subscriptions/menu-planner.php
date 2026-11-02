<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';

admin_layout_start(['pageTitle' => 'Menu planner', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Subscription menu planner';
$pageDescription = 'Date-wise, weekly and delivery-index templates for suggested vegetable baskets.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/menu-edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add template</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$rows = [];
if (Install::isSchemaReady()) {
    foreach (SubscriptionMenuRepository::allForAdmin() as $t) {
        $label = (string) $t['name'];
        if ($t['planner_type'] === 'date') {
            $label .= ' · ' . ($t['template_date'] ?? '');
        } elseif ($t['planner_type'] === 'weekly') {
            $days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $label .= ' · ' . ($days[(int) ($t['weekday'] ?? 0)] ?? '');
        } else {
            $label .= ' · Day #' . (int) ($t['day_index'] ?? 0);
        }
        $rows[] = [
            'link' => $label,
            'action_href' => $base . '/menu-edit.php?id=' . (int) $t['id'],
            'type' => (string) $t['planner_type'],
            'active' => !empty($t['is_active']) ? 'Yes' : 'No',
        ];
    }
}
$columns = [
    ['label' => 'Template', 'key' => 'link'],
    ['label' => 'Type', 'key' => 'type'],
    ['label' => 'Active', 'key' => 'active'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
?>
<p class="mt-4 text-sm at-muted">
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/customer-baskets.php">Customer baskets</a>
    ·
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/planned-quantities.php">Planned quantities</a>
    ·
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/basket-override.php">Basket override</a>
</p>
<?php admin_layout_end(); ?>
