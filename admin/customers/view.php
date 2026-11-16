<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('customers');

$customerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$customer = $customerId > 0 && Install::isSchemaReady() ? AdminCustomerService::findById($customerId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer !== null) {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $adminId = AdminAuth::id();
    if ($adminId === null) {
        admin_flash_set('Admin session required.', 'error');
    } elseif ($action === 'set_active') {
        $makeActive = isset($_POST['make_active']) && (string) $_POST['make_active'] === '1';
        $result = AdminCustomerService::setActive($customerId, $makeActive, (int) $adminId);
        admin_flash_set((string) ($result['message'] ?? ($result['ok'] ? 'Saved.' : 'Failed.')), !empty($result['ok']) ? 'success' : 'error');
    }
    header('Location: ' . url_base() . '/admin/customers/view.php?id=' . $customerId, true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Customer detail', 'activeAdminNav' => 'customers']);

if ($customer === null) {
    echo '<div class="at-alert at-alert--error">Customer not found.</div>';
    echo '<p class="mt-3"><a class="underline" href="' . Security::escape(url_base()) . '/admin/customers/index.php">← Back to customers</a></p>';
    admin_layout_end();
    exit;
}

$active = !empty($customer['is_active']);
$warnings = AdminCustomerService::deactivationWarnings($customerId);
$addresses = CustomerAccountService::listAddresses($customerId);
$orders = AdminCustomerService::ordersForCustomer($customerId);
$invoices = AdminCustomerService::invoicesForCustomer($customerId);
$payments = AdminCustomerService::paymentsForCustomer($customerId);
$refunds = AdminCustomerService::refundsForCustomer($customerId);
$subscriptions = AdminCustomerService::subscriptionsForCustomer($customerId);
$tickets = AdminCustomerService::ticketsForCustomer($customerId);

$displayName = trim((string) ($customer['name'] ?? '')) !== '' ? (string) $customer['name'] : 'Customer';
$pageHeading = $displayName;
$pageDescription = 'Mobile ' . (string) ($customer['mobile'] ?? '');
$breadcrumbs = [
    ['label' => 'Customers', 'href' => url_base() . '/admin/customers/index.php'],
    ['label' => $displayName],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$base = url_base();
?>
<p class="mb-4 text-sm flex flex-wrap gap-3 items-center">
    <a class="underline at-focusable" href="<?= Security::escape($base) ?>/admin/customers/index.php">← Back to customers</a>
    <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape($base) ?>/admin/customers/edit.php?id=<?= $customerId ?>">Edit customer</a>
</p>

<div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold text-base">Personal details</h3>
            <p><strong>Name:</strong> <?= Security::escape($displayName) ?></p>
            <p><strong>Mobile:</strong> <?= Security::escape((string) ($customer['mobile'] ?? '')) ?> <span class="at-muted text-xs">(not editable here — OTP ownership flow)</span></p>
            <p><strong>Email:</strong>
                <?php if ((string) ($customer['email'] ?? '') !== ''): ?>
                    <?= Security::escape((string) $customer['email']) ?>
                <?php else: ?>
                    <span class="at-muted">—</span>
                <?php endif; ?>
            </p>
            <p><strong>Registered:</strong> <?= Security::escape(customer_format_datetime((string) ($customer['created_at'] ?? ''))) ?></p>
            <p><strong>Status:</strong> <?= admin_status_badge($active ? 'active' : 'inactive', $active ? 'Active' : 'Inactive') ?></p>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Addresses</h3>
            <?php if ($addresses === []): ?>
                <p class="text-sm at-muted">No saved addresses.</p>
            <?php else: ?>
                <div class="space-y-3">
                <?php foreach ($addresses as $addr):
                    $line = trim(AddressInput::fullAddress($addr));
                    ?>
                    <div class="p-3 rounded-lg bg-[var(--at-surface-2)] text-sm">
                        <p class="font-semibold"><?= Security::escape((string) ($addr['label'] ?? 'Address')) ?><?= !empty($addr['is_default']) ? ' · Default' : '' ?></p>
                        <p class="at-muted text-xs mt-1">Receiver: <?= Security::escape((string) ($addr['receiver_name'] ?? '—')) ?> · <?= Security::escape((string) ($addr['receiver_phone'] ?? '—')) ?></p>
                        <p class="mt-1"><?= Security::escape($line !== '' ? $line : (string) ($addr['line1'] ?? '')) ?></p>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Order history</h3>
            <?php if ($orders === []): ?>
                <p class="text-sm at-muted">No orders yet.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead><tr><th>Order</th><th>Date</th><th class="is-num">Amount</th><th>Payment</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><a class="underline" href="<?= Security::escape($base) ?>/admin/orders/view.php?id=<?= (int) $o['id'] ?>"><?= Security::escape((string) $o['order_number']) ?></a></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($o['placed_at'] ?? $o['created_at'] ?? ''))) ?></td>
                                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($o['grand_total'] ?? 0))) ?></td>
                                <td><?= admin_status_badge((string) ($o['payment_status'] ?? '')) ?></td>
                                <td><?= admin_status_badge((string) ($o['status'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Invoices</h3>
            <?php if ($invoices === []): ?>
                <p class="text-sm at-muted">No invoices yet.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead><tr><th>Invoice</th><th>Date</th><th class="is-num">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><a class="underline" href="<?= Security::escape($base) ?>/admin/invoices/view.php?id=<?= (int) $inv['id'] ?>"><?= Security::escape((string) $inv['invoice_number']) ?></a></td>
                                <td><?= Security::escape((string) ($inv['invoice_date'] ?? '')) ?></td>
                                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($inv['grand_total'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">
                Support tickets
                <?php if ($tickets !== []): ?>
                    <span class="at-muted font-normal text-sm">(<?= count($tickets) ?>)</span>
                <?php endif; ?>
            </h3>
            <?php if ($tickets === []): ?>
                <p class="text-sm at-muted">No support tickets submitted by this customer.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead><tr>
                            <th>Ticket</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th class="is-actions">View</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($tickets as $tk):
                            $tid = (int) ($tk['id'] ?? 0);
                            $ticketHref = $base . '/admin/support/index.php?id=' . $tid;
                            ?>
                            <tr>
                                <td class="is-nowrap font-mono text-xs"><?= Security::escape((string) ($tk['ticket_number'] ?? '')) ?></td>
                                <td><?= Security::escape((string) ($tk['subject'] ?? '')) ?></td>
                                <td class="is-nowrap"><?= Security::escape(SupportTicketService::categoryLabel((string) ($tk['category'] ?? ''))) ?></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($tk['created_at'] ?? ''))) ?></td>
                                <td><?= admin_status_badge((string) ($tk['status'] ?? '')) ?></td>
                                <td class="is-actions">
                                    <a class="at-btn at-btn--ghost at-btn--sm at-focusable" href="<?= Security::escape($ticketHref) ?>">View ticket</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div></div>
    </div>

    <aside class="space-y-4">
        <div class="at-card"><div class="at-card__body text-sm">
            <h3 class="font-bold mb-2">Account status</h3>
            <form method="post" onsubmit="return confirm(<?= json_encode($active
                ? 'Deactivate this customer? They cannot log in or place new orders. Paid orders and subscriptions stay unchanged.'
                : 'Reactivate this customer account?', JSON_UNESCAPED_UNICODE) ?>);">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="set_active">
                <input type="hidden" name="make_active" value="<?= $active ? '0' : '1' ?>">
                <?php if ($active && ($warnings['active_subscriptions'] > 0 || $warnings['pending_orders'] > 0)): ?>
                    <p class="at-alert at-alert--warning text-xs mb-3">
                        This customer has <?= (int) $warnings['active_subscriptions'] ?> active/pending subscription(s)
                        and <?= (int) $warnings['pending_orders'] ?> pending payment order(s).
                        Deactivation blocks login and new checkout only.
                    </p>
                <?php endif; ?>
                <button type="submit" class="at-btn at-focusable at-btn--block <?= $active ? 'at-btn--danger' : 'at-btn--primary' ?>">
                    <?= $active ? 'Deactivate account' : 'Reactivate account' ?>
                </button>
            </form>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Payments</h3>
            <?php if ($payments === []): ?>
                <p class="text-sm at-muted">No payment attempts.</p>
            <?php else: ?>
                <ul class="text-sm space-y-2">
                <?php foreach ($payments as $p): ?>
                    <li>
                        <span class="font-mono text-xs"><?= Security::escape((string) ($p['order_number'] ?? '')) ?></span>
                        · <?= admin_status_badge((string) ($p['status'] ?? '')) ?>
                        · <?= Security::escape(catalogue_format_price((float) ($p['amount'] ?? 0))) ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Refunds</h3>
            <?php if ($refunds === []): ?>
                <p class="text-sm at-muted">No gateway refunds on record.</p>
            <?php else: ?>
                <ul class="text-sm space-y-2">
                <?php foreach ($refunds as $r): ?>
                    <li>
                        <?= Security::escape((string) ($r['order_number'] ?? '')) ?>
                        · <?= admin_status_badge((string) ($r['gateway_status'] ?? '')) ?>
                        · <?= Security::escape(catalogue_format_price((float) ($r['amount_inr'] ?? 0))) ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Subscriptions</h3>
            <?php if ($subscriptions === []): ?>
                <p class="text-sm at-muted">No subscriptions.</p>
            <?php else: ?>
                <ul class="text-sm space-y-2">
                <?php foreach ($subscriptions as $s): ?>
                    <li>
                        <strong><?= Security::escape((string) ($s['subscription_number'] ?? '')) ?></strong>
                        · <?= admin_status_badge((string) ($s['status'] ?? '')) ?>
                        <?php if (!empty($s['deliveries_remaining'])): ?>
                            <span class="at-muted text-xs">· <?= (int) $s['deliveries_remaining'] ?> left</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
                <p class="text-xs at-muted mt-2"><a class="underline" href="<?= Security::escape($base) ?>/admin/subscriptions/customer-baskets.php?q=<?= urlencode((string) ($customer['mobile'] ?? '')) ?>">Search subscription baskets</a></p>
            <?php endif; ?>
        </div></div>
    </aside>
</div>
<?php admin_layout_end(); ?>
