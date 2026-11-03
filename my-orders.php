<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$orderList = AccountListPagination::emptyResult(AccountListPagination::DEFAULT_PER_PAGE);
$usingDemoOrders = false;
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $pager = AccountListPagination::fromRequest();
    $orderList = OrderRepository::listForCustomer(CustomerAuth::id(), $pager['page'], $pager['per_page']);
}
$orders = $orderList['rows'];
if ($orders === [] && $orderList['total'] === 0) {
    $orders = DemoCustomer::orders();
    $usingDemoOrders = $orders !== [];
}

$invoiceRefs = [];
if (!$usingDemoOrders && $orders !== [] && CustomerAuth::id() !== null) {
    try {
        $invoiceRefs = InvoiceQuery::refsForOrderNumbers(array_map(static function ($o) {
            return (string) ($o['id'] ?? '');
        }, $orders), (int) CustomerAuth::id());
    } catch (Throwable $e) {
        $invoiceRefs = [];
    }
}

layout_public_start(['pageTitle' => 'My orders — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'orders';
$accountTitle = 'My orders';
$accountSub = 'Track deliveries, request refunds and review what you bought.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($orders === []): ?>
    <div class="at-card"><div class="at-card__body">
        <?php
        $emptyTitle = 'No orders yet';
        $emptyMessage = 'Your orders will appear here with live status once you check out.';
        $emptyIcon = 'receipt';
        $emptyActionHref = url_base() . '/index.php';
        $emptyActionLabel = 'Shop now';
        require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
        ?>
    </div></div>
<?php else: ?>
    <ul class="grid gap-3">
        <?php foreach ($orders as $order):
            $st = (string) ($order['status'] ?? '');
            $itemCount = (int) ($order['item_qty'] ?? 0);
            if ($itemCount === 0) {
                foreach ($order['items'] ?? [] as $it) {
                    $itemCount += (int) ($it['qty'] ?? 0);
                }
            }
            ?>
            <li class="at-card">
                <div class="at-card__body at-order-card__body">
                    <div class="at-order-card__meta">
                        <div class="flex -space-x-3">
                            <?php foreach (array_slice($order['items'] ?? [], 0, 3) as $it): ?>
                                <span class="w-11 h-11 rounded-full overflow-hidden border-2 border-white bg-[var(--at-cream-2)]"><?php if (!empty($it['image'])): ?><img src="<?= Security::escape((string) $it['image']) ?>" alt="" class="w-full h-full object-cover"><?php endif; ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="min-w-0">
                            <p class="font-mono font-bold truncate"><?= Security::escape((string) ($order['id'] ?? '')) ?></p>
                            <p class="text-sm at-muted"><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></p>
                        </div>
                    </div>
                    <?= customer_status_badge($st, ucwords(str_replace('_', ' ', $st)), 'order') ?>
                    <div class="text-left sm:text-right">
                        <p class="font-extrabold"><?= Security::escape(catalogue_format_price((float) (($order['totals']['total'] ?? 0)))) ?></p>
                        <a href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode((string) $order['id']) ?>" class="at-link-arrow !text-sm at-focusable">Details</a>
                        <?php $invRef = $invoiceRefs[(string) ($order['id'] ?? '')] ?? null; if ($invRef !== null): ?>
                            <a href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode($invRef) ?>" class="at-link-arrow !text-sm at-focusable" title="Download invoice">Invoice</a>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$usingDemoOrders): ?>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/account-pagination.php';
        customer_account_pagination($orderList['page'], $orderList['total'], $orderList['per_page']); ?>
    <?php endif; ?>
<?php endif;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
