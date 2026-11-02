<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$customerId = CustomerAuth::id();
if ($customerId === null || !CommerceGateway::useDatabase()) {
    layout_public_start(['pageTitle' => 'My refunds — Apni Tokri', 'activeNav' => 'account']);
    echo '<div class="at-container py-10"><p>Refund status requires a signed-in account with live orders.</p></div>';
    layout_public_end();
    exit;
}

$refunds = RefundRequestService::listForCustomer($customerId);

layout_public_start(['pageTitle' => 'My refunds — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'refunds';
$accountTitle = 'My refunds';
$accountSub = 'Track refund requests and payment refund progress for your orders.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<div class="at-card">
    <div class="at-card__body">
        <?php if ($refunds === []): ?>
            <?php
            $emptyTitle = 'No refund requests yet';
            $emptyMessage = 'When you submit a refund request from an order, it will appear here with status updates.';
            $emptyIcon = 'receipt';
            $emptyActionHref = url_base() . '/my-orders.php';
            $emptyActionLabel = 'View my orders';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
            <ul class="at-ticket-list">
                <?php foreach ($refunds as $r): ?>
                    <li class="at-refund-row">
                        <div class="min-w-0">
                            <a class="font-bold hover:underline at-focusable" href="<?= Security::escape(url_base()) ?>/refund-details.php?refund=<?= rawurlencode((string) $r['request_number']) ?>"><?= Security::escape((string) $r['request_number']) ?></a>
                            <p class="text-sm mt-1">
                                Order <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode((string) $r['order_number']) ?>"><?= Security::escape((string) $r['order_number']) ?></a>
                                <?php if (!empty($r['subscription_number'])): ?>
                                    · Subscription <?= Security::escape((string) $r['subscription_number']) ?>
                                <?php endif; ?>
                            </p>
                            <p class="text-xs at-muted mt-1">
                                Requested <?= Security::escape(customer_format_datetime((string) $r['created_at'])) ?>
                                · Updated <?= Security::escape(customer_format_datetime((string) ($r['updated_at'] ?? $r['created_at']))) ?>
                            </p>
                        </div>
                        <div class="text-sm space-y-1 sm:text-right">
                            <p class="font-extrabold"><?= Security::escape(catalogue_format_price((float) ($r['display_amount_inr'] ?? 0))) ?></p>
                            <p><?= customer_status_badge((string) $r['status'], RefundRequestService::publicStatusLabel((string) $r['status']), 'refund') ?></p>
                            <p class="text-xs at-muted">Payment: <?= Security::escape(RefundRequestService::publicGatewayStatusLabel($r['gateway_status'] ?? null)) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
