<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$customerId = CustomerAuth::id();
$base = url_base();
$subList = AccountListPagination::emptyResult(AccountListPagination::DEFAULT_PER_PAGE);
if ($customerId !== null && CommerceGateway::useDatabase() && Install::isSchemaReady()) {
    $pager = AccountListPagination::fromRequest();
    $subList = CustomerSubscriptionRepository::listForCustomer($customerId, $pager['page'], $pager['per_page']);
}
$subs = $subList['rows'];

layout_public_start(['pageTitle' => 'My subscriptions — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'subscriptions';
$accountTitle = 'My subscriptions';
$accountSub = 'Prepaid plans, with every skip and remaining delivery visible.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<div class="at-alert at-alert--info text-sm">
    <?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?>
    <span><strong>Before you buy:</strong> prepaid plans need 100% advance payment. A valid skip carries forward as an extra delivery day, not a cash refund (unless policy allows). Pausing extends your schedule when enabled.</span>
</div>

<?php if ($subList['total'] === 0): ?>
    <div class="at-card">
        <div class="at-card__body">
            <p class="at-muted">You do not have any subscriptions yet.</p>
            <a href="<?= Security::escape($base) ?>/subscriptions.php" class="at-btn at-btn--primary at-focusable mt-4">Browse plans</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($subs as $sub):
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $name = (string) ($snapshot['name'] ?? 'Subscription');
        $status = (string) $sub['status'];
        $remaining = (int) ($sub['deliveries_remaining'] ?? 0);
        $total = (int) ($snapshot['delivery_count'] ?? $remaining);
        $completed = max(0, $total - $remaining);
        $pct = $total > 0 ? min(100, (int) round(($completed / $total) * 100)) : 0;
        $statusClass = $status === 'active' ? 'ok' : ($status === 'pending_payment' ? 'warn' : 'neutral');
        $subNum = (string) $sub['subscription_number'];
        ?>
        <article class="at-card mb-4">
            <div class="at-card__body">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="at-h3"><?= Security::escape($name) ?></h2>
                        <p class="text-sm at-muted mt-0.5 font-mono"><?= Security::escape($subNum) ?></p>
                    </div>
                    <?= customer_status_badge($status, ucwords(str_replace('_', ' ', $status)), 'generic') ?>
                </div>

                <?php if ($status === 'active'): ?>
                    <div class="mt-5">
                        <div class="flex justify-between text-sm font-semibold"><span><?= $completed ?> of <?= $total ?> deliveries completed</span><span class="at-muted"><?= $remaining ?> remaining</span></div>
                        <div class="at-progress mt-2" role="progressbar" aria-valuenow="<?= $completed ?>" aria-valuemin="0" aria-valuemax="<?= $total ?>"><div class="at-progress__bar" style="width:<?= $pct ?>%"></div></div>
                    </div>
                <?php elseif ($status === 'pending_payment'): ?>
                    <p class="mt-4 text-sm">Complete payment to activate your delivery calendar.</p>
                    <a href="<?= Security::escape($base) ?>/subscription-payment.php?sub=<?= rawurlencode($subNum) ?>" class="at-btn at-btn--primary at-focusable mt-3">Pay now</a>
                <?php endif; ?>

                <?php if ($status === 'active'): ?>
                    <a href="<?= Security::escape($base) ?>/subscription-details.php?sub=<?= rawurlencode($subNum) ?>" class="at-btn at-btn--primary at-focusable mt-5">View calendar</a>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?php require APNI_TOKRI_ROOT . '/includes/partials/account-pagination.php';
    customer_account_pagination($subList['page'], $subList['total'], $subList['per_page']); ?>
<?php endif; ?>

<section class="mt-8">
    <h2 class="at-h3 mb-3">Add another plan</h2>
    <a href="<?= Security::escape($base) ?>/subscriptions.php" class="at-btn at-btn--secondary">View all active plans</a>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
