<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('subscriptions');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$base = url_base() . '/admin/subscriptions';
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);

if ($useDb) {
    admin_process_list_delete('subscriptions', $base . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clone_id']) && $useDb && empty($_POST['admin_delete'])) {
    Csrf::requireValid();
    $newId = SubscriptionPlanRepository::clonePlan((int) $_POST['clone_id']);
    admin_flash_set($newId ? 'Plan cloned.' : 'Could not clone plan.', $newId ? 'success' : 'error');
    header('Location: ' . $base . '/index.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Subscription plans', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Subscription plans';
$pageDescription = 'Custom durations, delivery count, frequency and basket rules. Default 7/15/30 templates are editable.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/plan-edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add plan</a>'
    . ' <a href="' . Security::escape($base) . '/menu-planner.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Menu planner</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($useDb): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Plan name">
        </div>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;

$rows = [];
$total = 0;
$totalPages = 1;
if ($useDb) {
    $list = SubscriptionPlanRepository::listForAdminPaginated($listPage, $listPerPage, $listQ);
    $total = (int) $list['total'];
    $totalPages = (int) $list['total_pages'];
    $listPage = (int) $list['page'];
    foreach ($list['rows'] as $p) {
        $pid = (int) $p['id'];
        $rows[] = [
            'plan' => (string) $p['name'],
            'calendar' => (int) $p['calendar_duration_days'] . ' days',
            'deliveries' => (string) (int) $p['delivery_count'],
            'freq' => (string) $p['frequency_type'],
            'price' => catalogue_format_price((float) $p['price']),
            'active' => !empty($p['is_active']) ? 'Yes' : 'No',
            'actions' => admin_table_actions(
                $base . '/plan-edit.php?id=' . $pid,
                'Edit',
                'subscription_plan',
                $pid,
                (string) $p['name'],
                $listPage,
                $listPerPage,
                $listQ
            ),
        ];
    }
}
$columns = [
    ['label' => 'Plan', 'key' => 'plan'],
    ['label' => 'Calendar', 'key' => 'calendar'],
    ['label' => 'Deliveries', 'key' => 'deliveries', 'align' => 'num'],
    ['label' => 'Frequency', 'key' => 'freq'],
    ['label' => 'Price', 'key' => 'price', 'align' => 'num'],
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
        $base . '/index.php'
    );
}

if ($useDb): ?>
<form method="post" class="mt-4 flex flex-wrap gap-2 items-end">
    <?= Csrf::field() ?>
    <div><label class="at-label">Clone plan ID</label><input name="clone_id" class="at-input at-focusable w-24" type="number" min="1"></div>
    <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Clone</button>
</form>
<?php endif;
admin_layout_end();
