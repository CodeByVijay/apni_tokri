<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$order = null;
$loadedFromDatabase = false;

if ($id !== '' && CustomerService::isLoggedIn()) {
    if (AppEnvironment::usePersistedStorefront() && CustomerAuth::check() && CommerceGateway::useDatabase()) {
        $dbOrder = OrderRepository::forCustomerDetail($id, (int) CustomerAuth::id());
        if ($dbOrder !== null) {
            $order = $dbOrder;
            $loadedFromDatabase = true;
        }
    }
    if ($order === null) {
        $order = CustomerService::order($id);
        $loadedFromDatabase = false;
    }
}
if ($order === null && $id !== '' && AppEnvironment::allowsSessionDemoCommerce()) {
    $order = DemoCustomer::order($id);
    $loadedFromDatabase = false;
}

$orderEnvironmentBadge = $order !== null
    ? OrderPaymentEnvironmentBadge::forOrderConfirmation($id, $loadedFromDatabase)
    : null;

// Status-aware hero messaging — payment state and order state are evaluated
// separately; confirmation is only shown when the order state supports it.
$hero = null;
$retryEligible = false;
if ($order !== null) {
    $overview = is_array($order['overview'] ?? null) ? $order['overview'] : [];
    $current = is_array($order['current_status'] ?? null) ? $order['current_status'] : [];
    if ($loadedFromDatabase) {
        $orderRow = PaymentRepository::orderByNumberForCustomer($id, (int) CustomerAuth::id());
        $retryEligible = $orderRow !== null
            && PaymentGatewayConfig::activeGateway() === 'cashfree'
            && PaymentService::customerCanRetryStorefrontPayment($orderRow);
    }
    $hero = CustomerOrderDetailService::confirmationHero(
        (string) ($current['status'] ?? $overview['status_public'] ?? $order['status'] ?? ''),
        (string) ($overview['payment_status'] ?? ''),
        (string) ($current['title'] ?? ''),
        (string) ($current['explanation'] ?? ''),
        $retryEligible
    );
}
$heroTones = [
    'success' => 'bg-[var(--at-success-bg)] text-[var(--at-success)]',
    'warning' => 'bg-[var(--at-warning-bg)] text-[var(--at-warning-fg,#b45309)]',
    'danger' => 'bg-[var(--at-danger-bg)] text-[var(--at-danger)]',
];
$heroToneClass = $heroTones[(string) ($hero['tone'] ?? 'success')] ?? $heroTones['success'];
$heroCtas = [
    'track' => 'Track this order',
    'details' => 'View order details',
    'retry' => 'Retry payment',
];
$heroCtaLabel = $heroCtas[(string) ($hero['primary_cta'] ?? 'track')] ?? $heroCtas['track'];

layout_public_start([
    'pageTitle' => ($hero['title'] ?? 'Order') . ' — Veggie Tokri',
    'activeNav' => 'account',
    'showBottomNav' => false,
]);

if ($order === null): ?>
    <div class="at-container py-12">
        <?php
        $emptyTitle = 'Order not found';
        $emptyMessage = 'This order link is invalid or has expired.';
        $emptyIcon = 'receipt';
        $emptyActionHref = url_base() . '/my-orders.php';
        $emptyActionLabel = 'My orders';
        require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
        ?>
    </div>
<?php else:
    $totals = $order['totals'] ?? [];
    $slotLabels = ['morning' => 'Today, 9 AM – 12 PM', 'afternoon' => 'Today, 3 PM – 6 PM', 'tomorrow' => 'Tomorrow morning'];
    $displayNumber = (string) ($order['id'] ?? $id);
    ?>
    <div class="at-container py-10 md:py-16 max-w-2xl">
        <div class="text-center">
            <span class="inline-flex w-16 h-16 rounded-full <?= $heroToneClass ?> items-center justify-center"><?= at_icon((string) ($hero['icon'] ?? 'receipt'), 'w-8 h-8') ?></span>
            <h1 class="at-h1 mt-4"><?= Security::escape((string) ($hero['title'] ?? 'Order')) ?></h1>
            <p class="mt-2 at-muted">
                Order <span class="font-mono font-bold text-[var(--at-ink)]"><?= Security::escape($displayNumber) ?></span>
                <?php require APNI_TOKRI_ROOT . '/includes/partials/order-environment-badge.php'; ?>
            </p>
            <?php if ((string) ($hero['lede'] ?? '') !== ''): ?>
                <p class="mt-2 at-muted"><?= Security::escape((string) $hero['lede']) ?></p>
            <?php endif; ?>
        </div>
        <div class="at-card mt-8">
            <div class="at-card__body">
                <dl class="at-summary-list">
                    <div><dt><?= Security::escape((string) ($hero['total_label'] ?? 'Order total')) ?></dt><dd class="font-extrabold"><?= Security::escape(catalogue_format_price((float) ($totals['total'] ?? 0))) ?></dd></div>
                    <div><dt>Delivery slot</dt><dd><?= Security::escape($slotLabels[(string) ($order['slot'] ?? '')] ?? (string) ($order['slot'] ?? '')) ?></dd></div>
                </dl>
                <div class="flex flex-col sm:flex-row gap-3 mt-5">
                    <a href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode($displayNumber) ?>" class="at-btn at-btn--primary at-focusable flex-1"><?= Security::escape($heroCtaLabel) ?></a>
                    <a href="<?= Security::escape(url_base()) ?>/index.php" class="at-btn at-btn--secondary at-focusable flex-1">Keep shopping</a>
                </div>
            </div>
        </div>
    </div>
<?php endif;
layout_public_end();
