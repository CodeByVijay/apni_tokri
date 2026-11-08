<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('deliveries');

$base = url_base() . '/admin/deliveries';
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'delivery_date'), [
    'delivery_date' => 'delivery_date', 'status' => 'status', 'order_number' => 'order_number', 'updated_at' => 'updated_at',
], 'delivery_date');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
    'sort' => $sort !== 'delivery_date' ? $sort : null,
    'dir' => strtolower($dir) !== 'asc' ? $dir : null,
]);

admin_layout_start(['pageTitle' => 'Deliveries', 'activeAdminNav' => 'deliveries']);
$pageHeading = 'Delivery management';
$pageDescription = 'Manual courier booking (Porter, Rapido, etc.) — record status here after booking outside Veggie Tokri.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$filters = ['active_only' => $filterStatus === ''];
if ($filterStatus !== '') {
    $filters['status'] = $filterStatus;
    unset($filters['active_only']);
}

$counts = [];
$list = ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
if (Install::isSchemaReady()) {
    $pdo = Database::pdo();
    foreach (DeliveryStatus::all() as $st) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE status = ?');
        $stmt->execute([$st]);
        $counts[$st] = (int) $stmt->fetchColumn();
    }
    $list = DeliveryAssignmentService::searchForAdmin($listQ, $filters, $listPage, $listPerPage, $sort, $dir);
}
$rows = $list['rows'];
?>
<div class="flex flex-wrap gap-2 mb-4 text-sm">
    <a class="at-btn at-btn--sm <?= $filterStatus === '' ? 'at-btn--primary' : 'at-btn--secondary' ?> at-focusable" href="<?= Security::escape($base . '/index.php') ?>">Active</a>
    <?php foreach (DeliveryStatus::all() as $st): ?>
        <a class="at-btn at-btn--sm <?= $filterStatus === $st ? 'at-btn--primary' : 'at-btn--secondary' ?> at-focusable"
           href="<?= Security::escape($base . '/index.php?status=' . rawurlencode($st)) ?>">
            <?= Security::escape(str_replace('_', ' ', $st)) ?> (<?= (int) ($counts[$st] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>
<?php if (Install::isSchemaReady()): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <?php if ($filterStatus !== ''): ?>
            <input type="hidden" name="status" value="<?= Security::escape($filterStatus) ?>">
        <?php endif; ?>
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Order #, customer, courier, tracking">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php endif; ?>

<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead><tr>
            <th><?= AdminServerList::sortHeaderLink('order_number', 'Order', $sort, $dir, $base . '/index.php', $queryBase) ?></th>
            <th>Customer</th>
            <th><?= AdminServerList::sortHeaderLink('delivery_date', 'Slot / date', $sort, $dir, $base . '/index.php', $queryBase) ?></th>
            <th>Payment</th>
            <th><?= AdminServerList::sortHeaderLink('status', 'Status', $sort, $dir, $base . '/index.php', $queryBase) ?></th>
            <th>Courier</th>
            <th class="is-actions">Actions</th>
        </tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="7" class="at-admin-table__empty">No deliveries match this filter.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="is-id"><?= Security::escape((string) $r['order_number']) ?></td>
                    <td><?= Security::escape((string) ($r['customer_name'] ?: $r['customer_mobile'] ?: '—')) ?></td>
                    <td><?= Security::escape(trim(((string) ($r['delivery_slot_display'] ?? $r['delivery_slot'] ?? '')) . ' · ' . ($r['delivery_date'] ?? ''))) ?></td>
                    <td><?= admin_status_badge((string) ($r['payment_status'] ?? '')) ?></td>
                    <td><?= admin_status_badge((string) ($r['status'] ?? '')) ?></td>
                    <td><?= Security::escape((string) ($r['courier_service_name'] ?? '—')) ?></td>
                    <td class="is-actions"><a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($base . '/view.php?id=' . (int) $r['id']) ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $base . '/index.php');
}
?>
<p class="mt-4"><a class="underline text-sm" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery.php">Delivery zones &amp; slots</a></p>
<?php admin_layout_end(); ?>
