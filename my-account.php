<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$p = CustomerService::profile();
$orders = CustomerService::orders();
$addresses = CustomerService::addresses();
$latest = $orders[0] ?? null;
$base = url_base();

$activeSubCount = 0;
$latestSub = null;
$customerId = CustomerAuth::id();
if ($customerId !== null && AppEnvironment::usePersistedStorefront()) {
    $activeSubCount = CustomerSubscriptionRepository::countActiveForCustomer($customerId);
    $latestSub = CustomerSubscriptionRepository::findLatestForCustomer($customerId);
}

layout_public_start([
    'pageTitle' => 'My account — Apni Tokri',
    'activeNav' => 'account',
]);
$accountActive = 'overview';
$accountTitle = 'Hello, ' . ($p['name'] ?? 'there');
$accountSub = (string) ($p['mobile'] ?? '');
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<div class="grid sm:grid-cols-3 gap-3">
    <div class="at-stat"><small>Orders</small><strong><?= count($orders) ?></strong></div>
    <div class="at-stat"><small>Active subscriptions</small><strong><?= (int) $activeSubCount ?></strong></div>
    <div class="at-stat"><small>Saved addresses</small><strong><?= count($addresses) ?></strong></div>
</div>

<section class="at-card">
    <div class="at-card__body">
        <div class="flex items-center justify-between gap-3">
            <h2 class="at-h3">Latest order</h2>
            <a href="<?= Security::escape($base) ?>/my-orders.php" class="at-link-arrow at-focusable">All orders</a>
        </div>
        <?php if ($latest): ?>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono font-bold"><?= Security::escape((string) ($latest['id'] ?? $latest['order_number'] ?? '')) ?></p>
                    <p class="text-sm mt-1"><?php $st = (string) ($latest['status'] ?? ''); ?><?= customer_status_badge($st, ucwords(str_replace('_', ' ', $st)), 'order') ?></p>
                </div>
                <div class="text-right">
                    <p class="font-extrabold text-lg"><?= Security::escape(catalogue_format_price((float) ($latest['totals']['total'] ?? $latest['grand_total'] ?? 0))) ?></p>
                    <a href="<?= Security::escape($base) ?>/order-details.php?id=<?= rawurlencode((string) ($latest['id'] ?? $latest['order_number'] ?? '')) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-1">Track order</a>
                </div>
            </div>
        <?php else: ?>
            <p class="mt-2 text-sm at-muted">You haven’t placed an order yet.</p>
            <a href="<?= Security::escape($base) ?>/index.php" class="at-btn at-btn--primary at-btn--sm at-focusable mt-3">Start shopping</a>
        <?php endif; ?>
    </div>
</section>

<section class="at-card">
    <div class="at-card__body">
        <div class="flex items-center justify-between gap-3">
            <h2 class="at-h3">Subscription</h2>
            <a href="<?= Security::escape($base) ?>/my-subscriptions.php" class="at-link-arrow at-focusable">Manage</a>
        </div>
        <?php if ($latestSub === null): ?>
            <p class="mt-2 text-sm at-muted">You do not have any subscriptions yet.</p>
            <a href="<?= Security::escape($base) ?>/subscriptions.php" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-3">Browse plans</a>
        <?php else:
            $snapshot = CustomerSubscriptionRepository::decodeSnapshot($latestSub);
            $subName = (string) ($snapshot['name'] ?? 'Subscription');
            $status = (string) ($latestSub['status'] ?? '');
            $remaining = (int) ($latestSub['deliveries_remaining'] ?? 0);
            $total = (int) ($snapshot['delivery_count'] ?? $remaining);
            $completed = max(0, $total - $remaining);
            $pct = $total > 0 ? min(100, (int) round(($completed / $total) * 100)) : 0;
            ?>
            <p class="mt-2 text-sm"><strong><?= Security::escape($subName) ?></strong>
                <?= customer_status_badge($status, ucwords(str_replace('_', ' ', $status)), 'generic') ?></p>
            <?php if ($status === 'active' && $total > 0): ?>
                <div class="mt-3 at-progress" role="progressbar" aria-valuenow="<?= $completed ?>" aria-valuemin="0" aria-valuemax="<?= $total ?>"><div class="at-progress__bar" style="width:<?= $pct ?>%"></div></div>
                <p class="text-xs at-muted mt-2"><?= $completed ?> of <?= $total ?> deliveries completed · <?= $remaining ?> remaining</p>
            <?php elseif ($status === 'pending_payment'): ?>
                <p class="text-sm at-muted mt-2">Complete payment to activate your plan.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="at-card">
    <div class="at-card__body">
        <div class="flex items-center justify-between gap-3">
            <h2 class="at-h3">Saved addresses</h2>
            <a href="<?= Security::escape($base) ?>/my-addresses.php" class="at-link-arrow at-focusable">Manage addresses</a>
        </div>
        <?php if ($addresses === []): ?>
            <p class="mt-2 text-sm at-muted">No saved addresses yet.</p>
        <?php else: ?>
            <ul class="mt-3 grid sm:grid-cols-2 gap-3">
                <?php foreach ($addresses as $a): ?>
                    <li class="at-option !cursor-default">
                        <span class="text-[var(--at-green)] mt-0.5"><?= at_icon('pin', 'w-5 h-5') ?></span>
                        <span class="text-sm"><strong><?= Security::escape($a['label']) ?></strong><br><?= Security::escape($a['line1']) ?>, <?= Security::escape($a['city']) ?> — <?= Security::escape($a['pincode']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="text-xs at-muted mt-3">Add, edit or remove addresses from <a href="<?= Security::escape($base) ?>/my-addresses.php" class="underline">My addresses</a>, or add one at checkout.</p>
    </div>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
