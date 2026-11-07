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
$statusCounts = ['all' => 0, 'processing' => 0, 'confirmed' => 0, 'delivered' => 0, 'cancelled' => 0];
$activeBucket = null;
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $pager = AccountListPagination::fromRequest();
    $activeBucket = OrderRepository::normalizeListBucket(isset($_GET['status']) ? (string) $_GET['status'] : null);
    $statusCounts = OrderRepository::statusCountsForCustomer((int) CustomerAuth::id());
    $orderList = OrderRepository::listForCustomer(CustomerAuth::id(), $pager['page'], $pager['per_page'], $activeBucket);
}
$orders = $orderList['rows'];
if ($orders === [] && $statusCounts['all'] === 0 && $orderList['total'] === 0 && AppEnvironment::allowsSessionDemoCommerce()) {
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

$base = url_base();
$filterChips = [
    'all' => 'All',
    'processing' => 'Processing',
    'confirmed' => 'Confirmed',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];
$showFilters = !$usingDemoOrders && $statusCounts['all'] > 0;

layout_public_start(['pageTitle' => 'My orders — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'orders';
$accountTitle = 'My Orders';
$accountSub = 'Track deliveries, download invoices and view details.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($showFilters): ?>
    <nav class="at-order-filters at-no-scrollbar" aria-label="Filter orders by status">
        <?php foreach ($filterChips as $key => $label):
            $isActive = ($key === 'all' && $activeBucket === null) || $key === $activeBucket;
            $href = $base . '/my-orders.php' . ($key === 'all' ? '' : '?status=' . rawurlencode($key));
            ?>
            <a href="<?= Security::escape($href) ?>"
                class="at-order-filters__chip at-focusable<?= $isActive ? ' is-active' : '' ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>>
                <?= Security::escape($label) ?>
                <span class="at-order-filters__count"><?= (int) $statusCounts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($orders === []): ?>
    <div class="at-card"><div class="at-card__body">
        <?php
        if ($activeBucket !== null && $statusCounts['all'] > 0) {
            $emptyTitle = 'No ' . strtolower($filterChips[$activeBucket]) . ' orders';
            $emptyMessage = 'You have no orders with this status right now.';
            $emptyActionHref = $base . '/my-orders.php';
            $emptyActionLabel = 'View all orders';
        } else {
            $emptyTitle = 'No orders yet';
            $emptyMessage = 'Your orders will appear here with live status once you check out.';
            $emptyActionHref = $base . '/index.php';
            $emptyActionLabel = 'Shop now';
        }
        $emptyIcon = 'receipt';
        require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
        ?>
    </div></div>
<?php else: ?>
    <ul class="at-order-list grid gap-3 min-w-0">
        <?php foreach ($orders as $order):
            $st = (string) ($order['status'] ?? '');
            $orderId = (string) ($order['id'] ?? '');
            $detailUrl = $base . '/order-details.php?id=' . rawurlencode($orderId);
            $itemCount = (int) ($order['item_qty'] ?? 0);
            if ($itemCount === 0) {
                foreach ($order['items'] ?? [] as $it) {
                    $itemCount += (int) ($it['qty'] ?? 0);
                }
            }
            $placedRaw = (string) ($order['created_at'] ?? '');
            $placedLabel = trim($placedRaw) !== '' ? customer_format_datetime($placedRaw) : '';
            $statusLabel = ucwords(str_replace('_', ' ', $st));
            $totalLabel = catalogue_format_price((float) (($order['totals']['total'] ?? 0)));
            $thumb = '';
            foreach ($order['items'] ?? [] as $it) {
                if (!empty($it['image'])) {
                    $thumb = (string) $it['image'];
                    break;
                }
            }
            $invRef = $invoiceRefs[$orderId] ?? null;
            ?>
            <li class="min-w-0">
                <article class="at-card at-order-card" data-at-order-card data-order-href="<?= Security::escape($detailUrl) ?>">
                    <div class="at-card__body at-order-card__body">
                        <span class="at-order-card__thumb" aria-hidden="true">
                            <?php if ($thumb !== ''): ?>
                                <img src="<?= Security::escape($thumb) ?>" alt="" loading="lazy" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?= at_icon('bag', 'at-order-card__thumb-icon') ?>
                            <?php endif; ?>
                        </span>

                        <div class="at-order-card__main">
                            <div class="at-order-card__head">
                                <a href="<?= Security::escape($detailUrl) ?>" class="at-order-card__number at-order-card__link at-focusable font-mono font-bold"
                                    aria-label="<?= Security::escape('Order ' . $orderId . ', ' . $statusLabel . ', total ' . $totalLabel . '. View details') ?>"><?= Security::escape($orderId) ?></a>
                                <?= customer_status_badge($st, $statusLabel, 'order') ?>
                            </div>
                            <p class="at-order-card__meta text-sm at-muted">
                                <?php if ($placedLabel !== ''): ?>
                                    <time datetime="<?= Security::escape($placedRaw) ?>"><?= Security::escape($placedLabel) ?></time>
                                    <span class="at-order-card__meta-sep" aria-hidden="true">·</span>
                                <?php endif; ?>
                                <span><?= (int) $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span>
                            </p>
                            <p class="at-order-card__total font-extrabold"><?= Security::escape($totalLabel) ?></p>
                        </div>

                        <div class="at-order-card__aside">
                            <span class="at-order-card__cta at-btn at-btn--secondary at-btn--sm" aria-hidden="true">
                                View details<?= at_icon('chevron-right', 'at-order-card__cta-icon') ?>
                            </span>
                            <span class="at-order-card__chevron" aria-hidden="true"><?= at_icon('chevron-right', 'at-order-card__chevron-icon') ?></span>
                        </div>

                        <?php if ($invRef !== null): ?>
                            <div class="at-order-card__extra">
                                <a href="<?= Security::escape($base) ?>/invoice-download.php?ref=<?= rawurlencode($invRef) ?>"
                                    class="at-btn at-btn--ghost at-btn--sm at-focusable at-order-card__side"
                                    data-at-order-card-stop
                                    title="Download invoice">
                                    <?= at_icon('receipt', 'at-order-card__side-icon') ?>Invoice
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$usingDemoOrders): ?>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/account-pagination.php';
        customer_account_pagination(
            $orderList['page'],
            $orderList['total'],
            $orderList['per_page'],
            $activeBucket !== null ? ['status' => $activeBucket] : []
        ); ?>
    <?php endif; ?>
<?php endif;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
