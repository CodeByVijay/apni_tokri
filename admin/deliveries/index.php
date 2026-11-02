<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('deliveries');

$base = url_base() . '/admin/deliveries';
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';

admin_layout_start(['pageTitle' => 'Deliveries', 'activeAdminNav' => 'deliveries']);
admin_flash_render();
$pageHeading = 'Delivery management';
$pageDescription = 'Manual courier booking (Porter, Rapido, etc.) — record status here after booking outside Apni Tokri.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$filters = ['active_only' => $filterStatus === ''];
if ($filterStatus !== '') {
    $filters['status'] = $filterStatus;
    unset($filters['active_only']);
}
$rows = Install::isSchemaReady() ? DeliveryAssignmentService::listForAdmin($filters, 200) : [];

$counts = [];
if (Install::isSchemaReady()) {
    $pdo = Database::pdo();
    foreach (DeliveryStatus::all() as $st) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE status = ?');
        $stmt->execute([$st]);
        $counts[$st] = (int) $stmt->fetchColumn();
    }
}
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

<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead><tr>
            <th>Order</th><th>Customer</th><th>Slot / date</th><th>Payment</th><th>Status</th><th>Courier</th><th class="is-actions"></th>
        </tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="7" class="at-admin-table__empty">No deliveries match this filter.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="is-id"><a href="<?= Security::escape($base . '/view.php?id=' . (int) $r['id']) ?>"><?= Security::escape((string) $r['order_number']) ?></a></td>
                    <td><?= Security::escape((string) ($r['customer_name'] ?: $r['customer_mobile'] ?: '—')) ?></td>
                    <td><?= Security::escape(trim((string) ($r['delivery_slot'] ?? '') . ' · ' . ($r['delivery_date'] ?? ''))) ?></td>
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
<p class="mt-4"><a class="underline text-sm" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery.php">Delivery zones &amp; slots</a></p>
<?php admin_layout_end(); ?>
