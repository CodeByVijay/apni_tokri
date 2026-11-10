<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/firebase-ui.php';

AdminDemoPerms::denyIfCannot('firebase');

$listSelf = url_base() . '/admin/push/templates.php';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && PushSchema::tableReady('push_notification_templates')) {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE push_notification_templates
             SET title_template = ?, body_template = ?, click_url_template = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            trim((string) ($_POST['title_template'] ?? '')),
            trim((string) ($_POST['body_template'] ?? '')),
            trim((string) ($_POST['click_url_template'] ?? '')),
            !empty($_POST['is_active']) ? 1 : 0,
            $id,
        ]);
        admin_flash_set('Template updated.');
    }
    $redirGet = $_GET;
    $redirGet['edit'] = (string) $id;
    header('Location: ' . $listSelf . PushAdminList::listRedirectQuery($redirGet), true, 302);
    exit;
}

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);
$list = PushAdminList::templates($listQ, $listPage, $listPerPage);

$editRow = null;
if ($editId > 0 && PushSchema::tableReady('push_notification_templates')) {
    Install::ensureConnection();
    $st = Database::pdo()->prepare('SELECT * FROM push_notification_templates WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

admin_layout_start(['pageTitle' => 'Push templates', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification templates';
$pageDescription = 'Use {placeholders} replaced at send time. Unsupported placeholders are removed automatically.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
firebase_admin_subnav('templates');
?>

<div class="at-alert at-alert--info mb-4 max-w-3xl text-sm">
    <p class="font-medium mb-1">Supported variables (by event)</p>
    <ul class="list-disc ml-5 space-y-1">
        <li>Orders: <code>{customer_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_id}</code>, <code>{status_label}</code></li>
        <li>Refunds: <code>{customer_name}</code>, <code>{request_number}</code>, <code>{status_label}</code></li>
        <li>Subscriptions: <code>{customer_name}</code></li>
        <li>Manual / announcement: <code>{title}</code>, <code>{body}</code>, <code>{click_url}</code></li>
    </ul>
</div>

<?php if (!PushSchema::tableReady('push_notification_templates')): ?>
<div class="at-alert at-alert--warning">Templates table is not available.</div>
<?php else: ?>

<?php if (is_array($editRow)): ?>
<div class="at-admin-card mb-4 at-firebase-form-wrap">
    <div class="at-admin-card__head"><h2 class="text-base font-bold m-0">Edit template</h2></div>
    <form method="post" class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
        <p class="text-sm"><code><?= Security::escape((string) $editRow['template_key']) ?></code>
            <span class="at-muted">· <?= Security::escape((string) $editRow['event_type']) ?></span></p>
        <div>
            <label class="at-label" for="title-edit">Notification title</label>
            <input class="at-input at-focusable w-full" id="title-edit" name="title_template" placeholder="e.g. Order confirmed"
                   value="<?= Security::escape((string) $editRow['title_template']) ?>">
        </div>
        <div>
            <label class="at-label" for="body-edit">Notification body</label>
            <textarea class="at-input at-focusable w-full" id="body-edit" name="body_template" rows="3"
                      placeholder="e.g. Hi {customer_name}, order {order_number} is confirmed."><?= Security::escape((string) $editRow['body_template']) ?></textarea>
        </div>
        <div>
            <label class="at-label" for="url-edit">Click URL</label>
            <input class="at-input at-focusable w-full" id="url-edit" name="click_url_template" placeholder="e.g. /order-details.php?id={order_id}"
                   value="<?= Security::escape((string) ($editRow['click_url_template'] ?? '')) ?>">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" <?= !empty($editRow['is_active']) ? 'checked' : '' ?>> Active
        </label>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
            <?php
            $closeGet = $_GET;
            unset($closeGet['edit']);
            ?>
            <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($listSelf . PushAdminList::listRedirectQuery($closeGet)) ?>">Close</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php
firebase_admin_list_toolbar($listSelf, $listQ, $queryBase, $listPerPage);
$tableRows = [];
foreach ($list['rows'] as $tpl) {
    $tid = (int) $tpl['id'];
    $editQs = http_build_query(array_merge($queryBase, [
        'page' => (int) $list['page'],
        'per_page' => $listPerPage,
        'edit' => $tid,
    ]));
    $tableRows[] = [
        'key' => '<code>' . Security::escape((string) $tpl['template_key']) . '</code>',
        'event' => Security::escape((string) $tpl['event_type']),
        'title' => Security::escape((string) $tpl['title_template']),
        'active' => !empty($tpl['is_active']) ? 'active' : 'inactive',
        'updated' => Security::escape((string) ($tpl['updated_at'] ?? '—')),
        'actions' => '<a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="'
            . Security::escape($listSelf . '?' . $editQs) . '">Edit</a>',
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
if ($list['total'] === 0 && $listQ === ''):
    firebase_admin_empty_state('No notification templates', 'Apply migration 1.39.0 to seed default templates.');
else:
    $emptyMessage = $listQ !== '' ? 'No templates match your search.' : 'No notification templates.';
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
