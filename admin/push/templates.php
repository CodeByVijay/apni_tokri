<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');

$listSelf = url_base() . '/admin/push/templates.php';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$showCreate = isset($_GET['create']) && (string) $_GET['create'] === '1';
$formErrors = [];
$formInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && PushSchema::tableReady('push_notification_templates')) {
    Csrf::requireValid();
    $action = (string) ($_POST['form_action'] ?? 'update');
    if ($action === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        $result = PushTemplateAdmin::delete($delId);
        if (!empty($result['ok'])) {
            admin_flash_set('Template deleted.');
            $redirGet = $_GET;
            unset($redirGet['edit'], $redirGet['create']);
            header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
            exit;
        }
        admin_flash_set(implode(' ', $result['errors']), 'error');
        $redirGet = $_GET;
        unset($redirGet['edit'], $redirGet['create']);
        header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
        exit;
    }
    if ($action === 'create') {
        $formInput = $_POST;
        $result = PushTemplateAdmin::create($_POST);
        if (!empty($result['ok'])) {
            admin_flash_set('Template created.');
            $redirGet = $_GET;
            unset($redirGet['create'], $redirGet['edit']);
            header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
            exit;
        }
        $formErrors = $result['errors'];
        $showCreate = true;
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $result = PushTemplateAdmin::update($id, $_POST);
        if (!empty($result['ok'])) {
            admin_flash_set('Template updated.');
            $redirGet = $_GET;
            unset($redirGet['edit'], $redirGet['create']);
            header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
            exit;
        }
        $formErrors = $result['errors'];
        $editId = $id;
        $formInput = $_POST;
    }
}

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$filterActive = isset($_GET['active']) ? (string) $_GET['active'] : '';
if (!in_array($filterActive, ['', 'active', 'inactive'], true)) {
    $filterActive = '';
}
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'active' => $filterActive !== '' ? $filterActive : null,
]);
$list = PushAdminList::templates($listQ, $filterActive, $listPage, $listPerPage);

$editRow = null;
if ($editId > 0 && PushSchema::tableReady('push_notification_templates')) {
    $editRow = PushTemplateAdmin::findById($editId);
    if ($editRow === null) {
        admin_flash_set('Template not found.', 'error');
        $redirGet = $_GET;
        unset($redirGet['edit']);
        header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
        exit;
    }
}

$createHrefQs = http_build_query(array_merge($queryBase, [
    'page' => (int) $list['page'],
    'per_page' => $listPerPage,
    'create' => '1',
]));
$pageActionsHtml = '<a class="at-btn at-btn--primary at-focusable" href="'
    . Security::escape($listSelf . '?' . $createHrefQs) . '">+ Create Template</a>';

admin_layout_start(['pageTitle' => 'Push templates', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification templates';
$pageDescription = 'Manage FCM title/body templates. Placeholders are validated per event type; unsupported tokens are stripped at send time.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('templates');

/**
 * @param array<string, mixed>|null $row
 * @param array<string, mixed> $overrides
 */
function push_template_field(?array $row, string $key, array $overrides = []): string
{
    if (array_key_exists($key, $overrides)) {
        return (string) $overrides[$key];
    }
    if ($row !== null && array_key_exists($key, $row)) {
        return (string) $row[$key];
    }

    return '';
}

/**
 * @param array<string, mixed>|null $row
 * @param array<string, mixed> $overrides
 */
function push_template_is_active_checked(?array $row, array $overrides): bool
{
    if (array_key_exists('is_active', $overrides)) {
        return !empty($overrides['is_active']);
    }
    if ($row !== null) {
        return !empty($row['is_active']);
    }

    return true;
}
?>

<details class="at-admin-card mb-4 max-w-3xl text-sm at-firebase-variables-panel">
    <summary class="at-admin-card__head cursor-pointer list-none flex items-center justify-between gap-2">
        <span class="font-medium">Supported variables (by event)</span>
        <span class="at-muted text-xs">Show</span>
    </summary>
    <div class="at-admin-card__body">
        <ul class="list-disc ml-5 space-y-1">
            <li>Orders: <code>{customer_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_id}</code>, <code>{status_label}</code></li>
            <li>Refunds: <code>{customer_name}</code>, <code>{request_number}</code>, <code>{status_label}</code></li>
            <li>Subscriptions: <code>{customer_name}</code></li>
            <li>Manual / announcement: <code>{title}</code>, <code>{body}</code>, <code>{click_url}</code></li>
            <li>Promotional coupon (<code>coupon.promotional.push</code>): <code>{customer_name}</code>, <code>{coupon_code}</code>, <code>{discount_value}</code>, <code>{minimum_order}</code>, <code>{expiry_date}</code>, <code>{click_url}</code></li>
            <li>Offer promo (<code>offer.promo.push</code>): <code>{title}</code>, <code>{body}</code>, <code>{click_url}</code></li>
        </ul>
    </div>
</details>

<?php if (!PushSchema::tableReady('push_notification_templates')): ?>
<div class="at-alert at-alert--warning">Templates table is not available.</div>
<?php else: ?>

<?php
firebase_admin_list_toolbar($listSelf, $listQ, $queryBase, $listPerPage, [
    [
        'name' => 'active',
        'label' => 'Active',
        'value' => $filterActive,
        'options' => [
            '' => 'All templates',
            'active' => 'Active only',
            'inactive' => 'Inactive only',
        ],
    ],
]);
$tableRows = [];
foreach ($list['rows'] as $tpl) {
    $tid = (int) $tpl['id'];
    $editQs = http_build_query(array_merge($queryBase, [
        'page' => (int) $list['page'],
        'per_page' => $listPerPage,
        'edit' => $tid,
    ]));
    $isSystem = PushTemplateCatalog::isSystemTemplateKey((string) $tpl['template_key']);
    $tplKey = (string) $tpl['template_key'];
    $tplTitle = (string) $tpl['title_template'];
    $actions = '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="'
        . Security::escape($listSelf . '?' . $editQs) . '">Edit</a>';
    if (!$isSystem) {
        $actions .= ' <button type="button" class="at-btn at-btn--sm at-btn--ghost at-focusable"'
            . ' style="color:var(--at-danger)"'
            . ' data-at-push-template-delete-trigger'
            . ' data-template-id="' . $tid . '"'
            . ' data-template-key="' . Security::escape($tplKey) . '"'
            . ' data-template-title="' . Security::escape($tplTitle) . '">Delete</button>';
    }
    $updated = (string) ($tpl['updated_at'] ?? '');
    if ($updated === '') {
        $updated = '—';
    }
    $tableRows[] = [
        'key' => '<code>' . Security::escape($tplKey) . '</code>'
            . ($isSystem ? ' <span class="at-status at-status--info text-xs">System</span>' : ''),
        'event' => Security::escape((string) $tpl['event_type']),
        'title' => Security::escape($tplTitle),
        'active' => !empty($tpl['is_active']) ? 'active' : 'inactive',
        'updated' => Security::escape($updated),
        'actions' => '<span class="at-admin-actions at-admin-actions--firebase">' . $actions . '</span>',
    ];
}
$columns = [
    ['label' => 'Template key', 'key' => 'key', 'raw' => true],
    ['label' => 'Event type', 'key' => 'event'],
    ['label' => 'Title', 'key' => 'title', 'class' => 'max-w-[14rem] truncate'],
    ['label' => 'Active', 'key' => 'active'],
    ['label' => 'Updated', 'key' => 'updated'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
$hasFilters = $listQ !== '' || $filterActive !== '';
if ($list['total'] === 0 && !$hasFilters):
    firebase_admin_empty_state(
        'No notification templates',
        'Run migration 1.39.0 (and later push template migrations) or create a custom template.',
        $listSelf . '?' . http_build_query(array_merge($queryBase, ['create' => '1'])),
        '+ Create Template'
    );
else:
    $emptyMessage = $hasFilters ? 'No templates match your search or filters.' : 'No notification templates.';
    $rows = $tableRows;
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    admin_list_pagination(
        (int) $list['page'],
        (int) $list['total'],
        $listPerPage,
        $queryBase,
        $listSelf,
        AdminServerList::PER_PAGE_OPTIONS,
        'templates',
        $list['total'] > 0
    );
endif;

require __DIR__ . '/_template_modals.php';
echo '<script src="' . Security::escape(asset_url('assets/js/admin-push-templates.js')) . '"></script>';
endif;
admin_layout_end();
