<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$order = null;
if ($id !== '' && CustomerService::isLoggedIn()) {
    $order = CustomerService::order($id);
}
if ($order === null && $id !== '' && AppEnvironment::allowsSessionDemoCommerce()) {
    $order = DemoCustomer::order($id);
}

layout_public_start([
    'pageTitle' => 'Order confirmed — Apni Tokri',
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
    ?>
    <div class="at-container py-10 md:py-16 max-w-2xl">
        <div class="text-center">
            <span class="inline-flex w-16 h-16 rounded-full bg-[var(--at-success-bg)] text-[var(--at-success)] items-center justify-center"><?= at_icon('check', 'w-8 h-8') ?></span>
            <h1 class="at-h1 mt-4">Thank you — order confirmed</h1>
            <p class="mt-2 at-muted">Order <span class="font-mono font-bold text-[var(--at-ink)]"><?= Security::escape($id) ?></span> (demo)</p>
        </div>
        <div class="at-card mt-8">
            <div class="at-card__body">
                <dl class="at-summary-list">
                    <div><dt>Total paid</dt><dd class="font-extrabold"><?= Security::escape(catalogue_format_price((float) ($totals['total'] ?? 0))) ?></dd></div>
                    <div><dt>Delivery slot</dt><dd><?= Security::escape($slotLabels[(string) ($order['slot'] ?? '')] ?? (string) ($order['slot'] ?? '')) ?></dd></div>
                </dl>
                <div class="flex flex-col sm:flex-row gap-3 mt-5">
                    <a href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode($id) ?>" class="at-btn at-btn--primary at-focusable flex-1">Track this order</a>
                    <a href="<?= Security::escape(url_base()) ?>/index.php" class="at-btn at-btn--secondary at-focusable flex-1">Keep shopping</a>
                </div>
            </div>
        </div>
    </div>
<?php endif;
layout_public_end();
