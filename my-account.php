<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!DemoCustomer::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$p = DemoCustomer::profile();
$orders = DemoCustomer::orders();
$addresses = DemoCustomer::addresses();
$latest = $orders[0] ?? null;
$base = url_base();

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
    <div class="at-stat"><small>Active subscriptions</small><strong>1</strong><span class="at-badge at-badge--neutral mt-1">Demo</span></div>
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
                    <p class="font-mono font-bold"><?= Security::escape((string) $latest['id']) ?></p>
                    <p class="text-sm mt-1"><?php $st = (string) ($latest['status'] ?? ''); ?><?= customer_status_badge($st, ucwords(str_replace('_', ' ', $st)), 'order') ?></p>
                </div>
                <div class="text-right">
                    <p class="font-extrabold text-lg"><?= Security::escape(catalogue_format_price((float) ($latest['totals']['total'] ?? 0))) ?></p>
                    <a href="<?= Security::escape($base) ?>/order-details.php?id=<?= rawurlencode((string) $latest['id']) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-1">Track order</a>
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
        <p class="mt-2 text-sm"><strong>Family vegetable plan</strong> <span class="at-badge at-badge--neutral ml-1">Demo</span></p>
        <div class="mt-3 at-progress" role="progressbar" aria-valuenow="5" aria-valuemin="0" aria-valuemax="7"><div class="at-progress__bar" style="width:71%"></div></div>
        <p class="text-xs at-muted mt-2">5 of 7 deliveries completed · 2 remaining</p>
    </div>
</section>

<section class="at-card">
    <div class="at-card__body">
        <div class="flex items-center justify-between gap-3">
            <h2 class="at-h3">Saved addresses</h2>
            <a href="<?= Security::escape($base) ?>/my-addresses.php" class="at-link-arrow at-focusable">Manage addresses</a>
        </div>
        <ul class="mt-3 grid sm:grid-cols-2 gap-3">
            <?php foreach ($addresses as $a): ?>
                <li class="at-option !cursor-default">
                    <span class="text-[var(--at-green)] mt-0.5"><?= at_icon('pin', 'w-5 h-5') ?></span>
                    <span class="text-sm"><strong><?= Security::escape($a['label']) ?></strong><br><?= Security::escape($a['line1']) ?>, <?= Security::escape($a['city']) ?> — <?= Security::escape($a['pincode']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="text-xs at-muted mt-3">Add, edit or remove addresses from <a href="<?= Security::escape($base) ?>/my-addresses.php" class="underline">My addresses</a>, or add one at checkout.</p>
    </div>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
