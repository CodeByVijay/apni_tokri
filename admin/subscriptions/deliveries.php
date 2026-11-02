<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

admin_layout_start(['pageTitle' => 'Subscription deliveries', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Subscription deliveries';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$rows = Install::isSchemaReady() ? SubscriptionFulfilmentService::adminUpcoming(150) : [];
$base = url_base();
?>
<div class="at-admin-card">
    <?php if ($rows === []): ?>
        <p class="at-muted">No upcoming confirmed subscription deliveries in the queue.</p>
    <?php else: ?>
        <div class="at-admin-table-wrap">
            <table class="at-admin-table">
                <thead>
                <tr>
                    <th>Delivery date</th>
                    <th>Customer</th>
                    <th>Subscription</th>
                    <th class="is-num">Basket total</th>
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
                                <a href="<?= Security::escape($base . '/admin/deliveries/view.php?id=' . $delLinkId) ?>">
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
admin_layout_end();
