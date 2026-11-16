<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$block = $order['cancellation_refund'] ?? null;
if (!is_array($block) || $block === []) {
    return;
}
$req = $block['refund_request'] ?? null;
$reqNum = is_array($req) ? (string) ($req['request_number'] ?? '') : '';
$reqStatus = is_array($req) ? (string) ($req['status'] ?? '') : '';
$reqAmount = is_array($req) ? RefundRequestService::displayAmountInr($req) : 0.0;
$needsApproval = !empty($block['needs_approval']);
$reviewUrl = (string) ($block['review_url'] ?? '');
?>
<section class="at-card at-admin-od-section mb-4">
    <div class="at-card__body">
        <h2 class="at-admin-od-section-title mb-3">Cancellation &amp; refund</h2>
        <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2">
            <?php if (!empty($block['cancellation_source'])): ?>
                <div>
                    <dt class="at-dl__dt">Cancelled by</dt>
                    <dd><?= Security::escape((string) $block['cancellation_source']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($block['cancelled_at'])): ?>
                <div>
                    <dt class="at-dl__dt">Cancelled at</dt>
                    <dd><?= Security::escape(customer_format_datetime((string) $block['cancelled_at'])) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($block['reason_code'])): ?>
                <div class="sm:col-span-2">
                    <dt class="at-dl__dt">Reason</dt>
                    <dd>
                        <?= Security::escape(ucwords(str_replace('_', ' ', (string) $block['reason_code']))) ?>
                        <?php if (!empty($block['reason_detail'])): ?>
                            <span class="at-muted"> — <?= Security::escape((string) $block['reason_detail']) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endif; ?>
            <div>
                <dt class="at-dl__dt">Payment status</dt>
                <dd><?= Security::escape(AdminOrderService::statusLabel((string) ($order['payment_status'] ?? ''))) ?></dd>
            </div>
            <div>
                <dt class="at-dl__dt">Captured amount</dt>
                <dd><?= Security::escape(catalogue_format_price((float) ($block['captured_amount_inr'] ?? 0))) ?></dd>
            </div>
            <div>
                <dt class="at-dl__dt">Refunded so far</dt>
                <dd><?= Security::escape(catalogue_format_price((float) ($block['refunded_amount_inr'] ?? 0))) ?></dd>
            </div>
            <div>
                <dt class="at-dl__dt">Remaining refundable</dt>
                <dd><?= Security::escape(catalogue_format_price((float) ($block['remaining_refundable_inr'] ?? 0))) ?></dd>
            </div>
            <?php if ($reqNum !== ''): ?>
                <div>
                    <dt class="at-dl__dt">Refund request</dt>
                    <dd class="font-mono text-sm"><?= Security::escape($reqNum) ?></dd>
                </div>
                <div>
                    <dt class="at-dl__dt">Refund status</dt>
                    <dd><?= admin_status_badge($reqStatus, RefundRequestService::publicStatusLabel($reqStatus)) ?></dd>
                </div>
                <div>
                    <dt class="at-dl__dt">Refund amount</dt>
                    <dd><?= Security::escape(catalogue_format_price($reqAmount)) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
        <?php if ($needsApproval && $reviewUrl !== ''): ?>
            <p class="mt-4">
                <a href="<?= Security::escape($reviewUrl) ?>" class="at-btn at-btn--primary at-focusable">Review &amp; Approve Refund</a>
            </p>
        <?php elseif ($reviewUrl !== ''): ?>
            <p class="mt-3">
                <a href="<?= Security::escape($reviewUrl) ?>" class="underline font-semibold at-focusable">Open refund request</a>
            </p>
        <?php endif; ?>
    </div>
</section>
