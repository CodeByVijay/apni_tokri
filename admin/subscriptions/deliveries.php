<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'delivery_date'), [
    'delivery_date' => 'delivery_date', 'subscription_number' => 'subscription_number', 'total_inr' => 'total_inr',
], 'delivery_date');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);
$base = url_base() . '/admin/subscriptions/deliveries.php';

admin_layout_start(['pageTitle' => 'Subscription deliveries', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Subscription deliveries';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$list = ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
if (Install::isSchemaReady()) {
    $list = SubscriptionFulfilmentService::searchUpcomingForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
}
$rows = $list['rows'];
?>
<?php if (Install::isSchemaReady()): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Subscription #, customer, order">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php endif; ?>
<div class="at-admin-card">
    <?php if ($rows === []): ?>
        <p class="at-muted px-5 py-4">No upcoming confirmed subscription deliveries in the queue.</p>
    <?php else: ?>
        <div class="at-admin-table-wrap">
            <table class="at-admin-table">
                <thead>
                <tr>
                    <th><?= AdminServerList::sortHeaderLink('delivery_date', 'Delivery date', $sort, $dir, $base, $queryBase) ?></th>
                    <th>Customer</th>
                    <th><?= AdminServerList::sortHeaderLink('subscription_number', 'Subscription', $sort, $dir, $base, $queryBase) ?></th>
                    <th class="is-num"><?= AdminServerList::sortHeaderLink('total_inr', 'Basket total', $sort, $dir, $base, $queryBase) ?></th>
                    <th>Fulfilment</th>
                    <th>Order</th>
                    <th>Delivery status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="is-nowrap"><?= Security::escape((string) $r['delivery_date']) ?></td>
                        <td><?= Security::escape((string) ($r['customer_name'] ?: $r['mobile'])) ?></td>
                        <td><?= Security::escape((string) $r['subscription_number']) ?></td>
                        <td class="is-num">₹<?= number_format((float) ($r['total_inr'] ?? 0), 2) ?></td>
                        <td><?= admin_status_badge((string) ($r['fulfilment_status'] ?? 'none')) ?></td>
                        <td>
                            <?php if (!empty($r['order_number'])): ?>
                                <?php
                                $delLinkId = (int) ($r['delivery_id'] ?? 0);
                                if ($delLinkId <= 0) {
                                    $dRow = DeliveryAssignmentService::findByOrderNumber((string) $r['order_number']);
                                    $delLinkId = $dRow ? (int) $dRow['id'] : 0;
                                }
                                ?>
                                <?php if ($delLinkId > 0): ?>
                                <a href="<?= Security::escape(url_base() . '/admin/deliveries/view.php?id=' . $delLinkId) ?>">
                                    <?= Security::escape((string) $r['order_number']) ?>
                                </a>
                                <?php else: ?>
                                    <?= Security::escape((string) $r['order_number']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= Security::escape((string) ($r['delivery_status'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $base);
}
admin_layout_end();
