<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$useDb = Install::isSchemaReady() && AdminAuth::check();
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);

if ($useDb) {
    admin_process_list_delete('subscriptions', $base . '/menu-templates.php');
}

admin_layout_start(['pageTitle' => 'Advanced day templates', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Advanced day templates';
$pageDescription = 'Technical date-wise, weekly and delivery-index templates (used by the standard veg_7 / veg_15 / veg_30 menus). For new menus use the Menu Planner.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/menu-planner.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to Menu Planner</a> <a href="' . Security::escape($base) . '/menu-edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add template</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Template name">
        </div>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = SubscriptionMenuRepository::listForAdminPaginated($listPage, $listPerPage, $listQ);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    $days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    foreach ($list['rows'] as $t) {
        $tid = (int) $t['id'];
        $label = (string) $t['name'];
        if ($t['planner_type'] === 'date') {
            $label .= ' · ' . ($t['template_date'] ?? '');
        } elseif ($t['planner_type'] === 'weekly') {
            $label .= ' · ' . ($days[(int) ($t['weekday'] ?? 0)] ?? '');
        } else {
            $label .= ' · Day #' . (int) ($t['day_index'] ?? 0);
        }
        $rows[] = [
            'template' => $label,
            'type' => (string) $t['planner_type'],
            'active' => !empty($t['is_active']) ? 'Yes' : 'No',
            'actions' => admin_table_actions(
                $base . '/menu-edit.php?id=' . $tid,
                'Edit',
                'subscription_menu_template',
                $tid,
                (string) $t['name'],
                $listPage,
                $listPerPage,
                $listQ
            ),
        ];
    }
}
$columns = [
    ['label' => 'Template', 'key' => 'template'],
    ['label' => 'Type', 'key' => 'type'],
    ['label' => 'Active', 'key' => 'active'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

if ($useDb && $totalPages > 1) {
    admin_list_pagination(
        $listPage,
        $total,
        $listPerPage,
        array_filter(['q' => $listQ !== '' ? $listQ : null]),
        $base . '/menu-templates.php'
    );
}
?>
<p class="mt-4 text-sm at-muted">
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/customer-baskets.php">Customer baskets</a>
    ·
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/planned-quantities.php">Planned quantities</a>
    ·
    <a class="underline font-semibold" href="<?= Security::escape($base) ?>/basket-override.php">Basket override</a>
</p>
<?php admin_layout_end(); ?>
