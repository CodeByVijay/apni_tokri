<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps(), true);

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
if ($orders === [] && $orderList['total'] === 0 && AppEnvironment::allowsSessionDemoCommerce()) {
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

layout_public_start(['pageTitle' => 'My orders — Veggie Tokri', 'activeNav' => 'account']);
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
    <ul class="at-order-list grid gap-3 min-w-0">
        <?php foreach ($orders as $order):
            $st = (string) ($order['status'] ?? '');
            $orderId = (string) ($order['id'] ?? '');
            $detailUrl = url_base() . '/order-details.php?id=' . rawurlencode($orderId);
            $itemCount = (int) ($order['item_qty'] ?? 0);
            if ($itemCount === 0) {
                foreach ($order['items'] ?? [] as $it) {
                    $itemCount += (int) ($it['qty'] ?? 0);
                }
            }
            $placedLabel = customer_format_datetime((string) ($order['created_at'] ?? ''));
            $statusLabel = ucwords(str_replace('_', ' ', $st));
            $invRef = $invoiceRefs[$orderId] ?? null;
            ?>
            <li class="min-w-0">
                <article
                    class="at-card at-order-card"
                    data-at-order-card
                    data-order-href="<?= Security::escape($detailUrl) ?>"
                    tabindex="0"
                    role="link"
                    aria-label="<?= Security::escape('Order ' . $orderId . ', ' . $statusLabel . ', ' . catalogue_format_price((float) (($order['totals']['total'] ?? 0)))) ?>"
                >
                    <div class="at-card__body at-order-card__body">
                        <header class="at-order-card__head">
                            <p class="at-order-card__number font-mono font-bold break-all"><?= Security::escape($orderId) ?></p>
                            <?= customer_status_badge($st, $statusLabel, 'order') ?>
                        </header>

                        <?php if (!empty($order['items'])): ?>
                            <div class="at-order-card__thumbs" aria-hidden="true">
                                <?php foreach (array_slice($order['items'] ?? [], 0, 3) as $it): ?>
                                    <span class="at-order-card__thumb"><?php if (!empty($it['image'])): ?><img src="<?= Security::escape((string) $it['image']) ?>" alt="" class="w-full h-full object-cover"><?php endif; ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <p class="at-order-card__meta text-sm at-muted">
                            <span><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
                            <?php if ($placedLabel !== ''): ?>
                                <span class="at-order-card__meta-sep" aria-hidden="true">·</span>
                                <time datetime="<?= Security::escape((string) ($order['created_at'] ?? '')) ?>"><?= Security::escape($placedLabel) ?></time>
                            <?php endif; ?>
                        </p>

                        <div class="at-order-card__footer">
                            <p class="at-order-card__total font-extrabold text-lg"><?= Security::escape(catalogue_format_price((float) (($order['totals']['total'] ?? 0)))) ?></p>
                            <div class="at-order-card__actions">
                                <?php if ($invRef !== null): ?>
                                    <a href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode($invRef) ?>"
                                        class="at-btn at-btn--ghost at-btn--sm at-focusable at-order-card__side"
                                        data-at-order-card-stop
                                        title="Download invoice">Invoice</a>
                                <?php endif; ?>
                                <a href="<?= Security::escape($detailUrl) ?>"
                                    class="at-btn at-btn--secondary at-btn--sm at-focusable at-order-card__cta">
                                    View details<?= at_icon('chevron-right', 'at-order-card__cta-icon') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </article>
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
