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
            unset($redirGet['edit']);
            header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
            exit;
        }
        admin_flash_set(implode(' ', $result['errors']), 'error');
        $redirGet = $_GET;
        $redirGet['edit'] = (string) $delId;
        header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
        exit;
    }
    if ($action === 'create') {
        $formInput = $_POST;
        $result = PushTemplateAdmin::create($_POST);
        if (!empty($result['ok'])) {
            admin_flash_set('Template created.');
            $redirGet = $_GET;
            unset($redirGet['create']);
            $redirGet['edit'] = (string) (int) ($result['id'] ?? 0);
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
            $redirGet['edit'] = (string) $id;
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

<?php if ($formErrors !== []): ?>
<div class="at-alert at-alert--error mb-4">
    <ul class="list-disc ml-5"><?php foreach ($formErrors as $err): ?>
        <li><?= Security::escape($err) ?></li>
    <?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($showCreate && $editRow === null): ?>
<div class="at-admin-card mb-4 at-firebase-form-wrap">
    <div class="at-admin-card__head"><h2 class="text-base font-bold m-0">Create template</h2></div>
    <form method="post" class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="create">
        <div class="space-y-3">
        <div>
            <label class="at-label" for="tpl-key-create">Template key</label>
            <input class="at-input at-focusable w-full" id="tpl-key-create" name="template_key" required maxlength="64"
                   pattern="[a-z0-9][a-z0-9._-]*" placeholder="e.g. marketing.summer.push"
                   value="<?= Security::escape(push_template_field(null, 'template_key', $formInput)) ?>">
            <p class="at-muted text-xs mt-1">Lowercase letters, numbers, dots, underscores, hyphens. Must be unique.</p>
        </div>
        <div>
            <label class="at-label" for="tpl-event-create">Event type</label>
            <select class="at-select at-focusable w-full" id="tpl-event-create" name="event_type" required>
                <option value="">— Select event —</option>
                <?php foreach (PushTemplateCatalog::allowedEventTypes() as $ev): ?>
                    <option value="<?= Security::escape($ev) ?>"<?= push_template_field(null, 'event_type', $formInput) === $ev ? ' selected' : '' ?>>
                        <?= Security::escape($ev) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php $formRow = null;
        require __DIR__ . '/_template_form_fields.php'; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
            <?php
            $closeGet = $_GET;
            unset($closeGet['create']);
            ?>
            <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($listSelf . PushAdminList::listRedirectQuery($closeGet)) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php elseif (is_array($editRow)): ?>
<?php
    $isSystem = PushTemplateCatalog::isSystemTemplateKey((string) $editRow['template_key']);
?>
<div class="at-admin-card mb-4 at-firebase-form-wrap">
    <div class="at-admin-card__head">
        <h2 class="text-base font-bold m-0">Edit template</h2>
        <?php if ($isSystem): ?>
            <p class="text-xs at-muted mt-1 mb-0">System template — key and event type are fixed. Deactivate instead of deleting.</p>
        <?php endif; ?>
    </div>
    <form method="post" class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="update">
        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
        <p class="text-sm"><code><?= Security::escape((string) $editRow['template_key']) ?></code>
            <span class="at-muted">· <?= Security::escape((string) $editRow['event_type']) ?></span>
            <?php if ($isSystem): ?><span class="at-status at-status--info ml-1">System</span><?php endif; ?>
        </p>
        <?php
        $formRow = $editRow;
        require __DIR__ . '/_template_form_fields.php';
        ?>
        <div class="flex flex-wrap gap-2 items-center">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
            <?php
            $closeGet = $_GET;
            unset($closeGet['edit']);
            ?>
            <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($listSelf . PushAdminList::listRedirectQuery($closeGet)) ?>">Close</a>
        </div>
    </form>
    <?php if (!$isSystem): ?>
    <div class="at-admin-card__foot border-t border-[var(--at-border)] px-4 py-3">
        <form method="post" onsubmit="return confirm('Delete this custom template permanently?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <button type="submit" class="at-btn at-btn--ghost at-focusable" style="color:var(--at-danger)">Delete template</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

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
    $actions = '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="'
        . Security::escape($listSelf . '?' . $editQs) . '">Edit</a>';
    if (!$isSystem) {
        $actions .= ' <form method="post" class="inline" onsubmit="return confirm(\'Delete this custom template?\');">'
            . Csrf::field()
            . '<input type="hidden" name="form_action" value="delete">'
            . '<input type="hidden" name="id" value="' . $tid . '">'
            . '<button type="submit" class="at-btn at-btn--sm at-btn--ghost at-focusable" style="color:var(--at-danger)">Delete</button></form>';
    } elseif (empty($tpl['is_active'])) {
        $actions .= ' <span class="at-muted text-xs">System</span>';
    }
    $updated = (string) ($tpl['updated_at'] ?? '');
    if ($updated === '') {
        $updated = '—';
    }
    $tableRows[] = [
        'key' => '<code>' . Security::escape((string) $tpl['template_key']) . '</code>'
            . ($isSystem ? ' <span class="at-status at-status--info text-xs">System</span>' : ''),
        'event' => Security::escape((string) $tpl['event_type']),
        'title' => Security::escape((string) $tpl['title_template']),
        'active' => !empty($tpl['is_active']) ? 'active' : 'inactive',
        'updated' => Security::escape($updated),
        'actions' => '<span class="at-admin-actions">' . $actions . '</span>',
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
endif;
admin_layout_end();
