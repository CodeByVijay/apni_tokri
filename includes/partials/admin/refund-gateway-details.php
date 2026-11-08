<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $gatewayRefundDetails */
/** @var list<array<string, mixed>> $refundHistory */

$gatewayRefundDetails = $gatewayRefundDetails ?? null;
$refundHistory = $refundHistory ?? [];
if ($gatewayRefundDetails === null) {
    return;
}
?>
<section class="at-card mt-4">
    <div class="at-card__body text-sm space-y-3">
        <h3 class="font-bold">Gateway refund details</h3>
        <dl class="grid md:grid-cols-2 gap-x-6 gap-y-2">
            <div><dt class="at-muted">Payment gateway</dt><dd><?= Security::escape((string) $gatewayRefundDetails['payment_gateway']) ?></dd></div>
            <div><dt class="at-muted">Gateway status</dt><dd><?= admin_status_badge((string) ($gatewayRefundDetails['gateway_status_raw'] ?? '')) ?> <?= Security::escape((string) $gatewayRefundDetails['gateway_status']) ?></dd></div>
            <div><dt class="at-muted">Merchant refund ID</dt><dd class="font-mono text-xs"><?= Security::escape((string) $gatewayRefundDetails['merchant_refund_id']) ?></dd></div>
            <div><dt class="at-muted">Gateway refund ID</dt><dd class="font-mono text-xs"><?= Security::escape((string) ($gatewayRefundDetails['gateway_refund_id'] ?: '—')) ?></dd></div>
            <div><dt class="at-muted">Refund amount</dt><dd><?= Security::escape(catalogue_format_price((float) $gatewayRefundDetails['refund_amount_inr'])) ?> <?= Security::escape((string) $gatewayRefundDetails['currency']) ?></dd></div>
            <div><dt class="at-muted">Gateway order reference</dt><dd class="font-mono text-xs"><?= Security::escape((string) ($gatewayRefundDetails['gateway_order_reference'] ?: '—')) ?></dd></div>
            <div><dt class="at-muted">Initiated at</dt><dd><?= ($gatewayRefundDetails['initiated_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $gatewayRefundDetails['initiated_at'])) : '—' ?></dd></div>
            <div><dt class="at-muted">Last checked at</dt><dd>
                <?php if (($gatewayRefundDetails['last_checked_at'] ?? '') !== ''): ?>
                    <?= Security::escape(customer_format_datetime((string) $gatewayRefundDetails['last_checked_at'])) ?>
                    <?php if (($gatewayRefundDetails['last_check_source'] ?? '') !== ''): ?>
                        <span class="at-muted">(<?= Security::escape((string) $gatewayRefundDetails['last_check_source']) ?>)</span>
                    <?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd></div>
            <div><dt class="at-muted">Completed at</dt><dd><?= ($gatewayRefundDetails['completed_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $gatewayRefundDetails['completed_at'])) : '—' ?></dd></div>
        </dl>
        <?php if (!empty($gatewayRefundDetails['status_message'])): ?>
            <p class="text-xs at-muted"><?= Security::escape((string) $gatewayRefundDetails['status_message']) ?></p>
        <?php endif; ?>
        <?php if (!empty($gatewayRefundDetails['last_error'])): ?>
            <p class="at-alert at-alert--warning text-xs"><?= Security::escape((string) $gatewayRefundDetails['last_error']) ?></p>
        <?php endif; ?>
        <?php if ($refundHistory !== []): ?>
            <h4 class="font-semibold mt-2">Refund status history</h4>
            <ul class="space-y-1 text-xs">
                <?php foreach ($refundHistory as $h):
                    if (!in_array((string) ($h['action'] ?? ''), ['gateway_status_fetch', 'gateway_status_cron', 'refunded', 'gateway_failed', 'gateway_initiated'], true)) {
                        continue;
                    }
                    $payload = [];
                    if (!empty($h['payload_json'])) {
                        try {
                            $payload = json_decode((string) $h['payload_json'], true) ?: [];
                        } catch (Throwable $e) {
                            $payload = [];
                        }
                    }
                    $src = (string) ($payload['source'] ?? ($h['action'] === 'gateway_status_cron' ? 'cron' : ''));
                    ?>
                    <li><?= Security::escape(admin_format_audit_datetime((string) ($h['created_at'] ?? ''))) ?>
                        — <?= Security::escape((string) $h['action']) ?>
                        <?php if ($src !== ''): ?><span class="at-muted">[<?= Security::escape($src) ?>]</span><?php endif; ?>
                        <?php if (!empty($payload['gateway_status'])): ?> · <?= Security::escape((string) $payload['gateway_status']) ?><?php endif; ?>
                        <?php if (!empty($payload['error'])): ?> · <?= Security::escape((string) $payload['error']) ?><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
