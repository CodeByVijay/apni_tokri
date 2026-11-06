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
$requestNumber = isset($_GET['refund']) && is_string($_GET['refund']) ? trim($_GET['refund']) : '';

if ($customerId === null || !CommerceGateway::useDatabase() || $requestNumber === '') {
    header('Location: ' . url_base() . '/my-refunds.php', true, 302);
    exit;
}

$req = RefundRequestService::byNumberForCustomer($requestNumber, $customerId);
if ($req === null) {
    layout_public_start(['pageTitle' => 'Refund — Veggie Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Refund request not found';
    $emptyMessage = 'This refund does not belong to your account.';
    $emptyActionHref = url_base() . '/my-refunds.php';
    $emptyActionLabel = 'My refunds';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$requestId = (int) $req['id'];
$timeline = RefundRequestService::customerTimeline($requestId, $customerId);
$subCtx = RefundRequestService::subscriptionContextForRequest($requestId, $customerId);
$amount = RefundRequestService::displayAmountInr($req);
$gatewayStatus = RefundRequestService::latestGatewayStatusForRequest($requestId);
$reasonLabels = RefundRequestService::reasonLabels();

layout_public_start(['pageTitle' => 'Refund ' . $requestNumber . ' — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'refunds';
$accountTitle = (string) $req['request_number'];
$accountSub = 'Refund request for order ' . (string) $req['order_number'];
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<p class="text-sm"><a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/my-refunds.php">← All refunds</a></p>
<div class="at-card mt-4">
    <div class="at-card__body text-sm space-y-2">
        <p>Order: <a class="font-bold underline" href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode((string) $req['order_number']) ?>"><?= Security::escape((string) $req['order_number']) ?></a></p>
        <?php if ($subCtx !== null): ?>
            <p>Subscription: <a class="font-bold underline" href="<?= Security::escape(url_base()) ?>/subscription-details.php?sub=<?= rawurlencode($subCtx['subscription_number']) ?>"><?= Security::escape($subCtx['subscription_number']) ?></a></p>
        <?php endif; ?>
        <p>Reason: <?= Security::escape($reasonLabels[(string) $req['reason_code']] ?? (string) $req['reason_code']) ?></p>
        <?php if (!empty($req['description'])): ?>
            <p class="at-muted"><?= Security::escape((string) $req['description']) ?></p>
        <?php endif; ?>
        <p>Amount: <strong><?= Security::escape(catalogue_format_price($amount)) ?></strong></p>
        <p>Request status: <?= customer_status_badge((string) $req['status'], RefundRequestService::publicStatusLabel((string) $req['status']), 'refund') ?></p>
        <p>Payment refund status: <strong><?= Security::escape(RefundRequestService::publicGatewayStatusLabel($gatewayStatus)) ?></strong></p>
        <p class="text-xs at-muted">Submitted <?= Security::escape((string) $req['created_at']) ?> · Last updated <?= Security::escape((string) ($req['updated_at'] ?? $req['created_at'])) ?></p>
        <?php if ((string) $req['status'] === RefundStatus::REJECTED && !empty($req['rejection_reason'])): ?>
            <div class="at-alert at-alert--error mt-2"><span><?= Security::escape((string) $req['rejection_reason']) ?></span></div>
        <?php endif; ?>
    </div>
</div>
<div class="at-card mt-4">
    <div class="at-card__body">
        <h2 class="at-h3">Timeline</h2>
        <?php if ($timeline === []): ?>
            <p class="text-sm at-muted mt-2">No activity recorded yet.</p>
        <?php else: ?>
            <ol class="mt-3 space-y-3 border-l-2 border-[var(--at-line)] pl-4">
                <?php foreach ($timeline as $ev): ?>
                    <li class="text-sm">
                        <p class="font-semibold"><?= Security::escape((string) $ev['label']) ?></p>
                        <p class="text-xs at-muted"><?= Security::escape((string) $ev['at']) ?></p>
                        <?php if (!empty($ev['detail'])): ?>
                            <p class="mt-1"><?= Security::escape((string) $ev['detail']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
