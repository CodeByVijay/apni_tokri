<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
/** @var array<string, mixed>|null $adminInv */
$overview = $order['overview'] ?? [];
$payMethod = $order['payment_method_primary'] ?? null;
?>
<section class="at-card at-admin-od-overview" aria-labelledby="at-admin-od-overview-title">
    <div class="at-card__body">
        <h2 id="at-admin-od-overview-title" class="at-admin-od-section-title">Order overview</h2>
        <dl class="at-admin-od-kv-grid">
            <div><dt>Order number</dt><dd class="font-mono font-bold"><?= Security::escape((string) ($overview['order_number'] ?? '')) ?></dd></div>
            <div><dt>Placed</dt><dd><?= Security::escape(customer_format_datetime((string) ($overview['placed_at'] ?? ''))) ?></dd></div>
            <div><dt>Order status</dt><dd><?= admin_status_badge((string) ($order['status'] ?? '')) ?></dd></div>
            <div><dt>Payment status</dt><dd><?= admin_status_badge((string) ($order['payment_status'] ?? '')) ?></dd></div>
            <div><dt>Source</dt><dd><?= Security::escape((string) ($order['order_source_label'] ?? $order['order_source'] ?? '—')) ?></dd></div>
            <div><dt>Grand total</dt><dd class="font-bold"><?= Security::escape(catalogue_format_price((float) ($order['grand_total'] ?? 0))) ?></dd></div>
            <div><dt>Delivery</dt><dd>
                <?php if ((string) ($overview['delivery_date'] ?? '') !== ''): ?>
                    <?= Security::escape((string) $overview['delivery_date']) ?> ·
                <?php endif; ?>
                <?= Security::escape((string) ($overview['delivery_slot_label'] ?? '—')) ?>
            </dd></div>
            <div><dt>Payment method</dt><dd><?php if ($payMethod !== null && $payMethod !== '') {
                echo Security::escape((string) $payMethod);
            } else { ?><span class="at-muted">—</span><?php } ?></dd></div>
            <div class="at-admin-od-kv-grid--wide"><dt>Invoice</dt><dd>
                <?php if ($adminInv !== null): ?>
                    <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/invoices/view.php?id=<?= (int) $adminInv['id'] ?>"><?= Security::escape((string) $adminInv['invoice_number']) ?></a>
                <?php else: ?>
                    <span class="at-muted">Not generated yet</span>
                <?php endif; ?>
            </dd></div>
        </dl>
        <?php
        $cancelEvt = OrderCancellationService::cancellationEventForOrder((int) ($order['id'] ?? 0));
        if ($cancelEvt !== null):
            $reasonCode = (string) ($cancelEvt['reason_code'] ?? '');
            $reasonLabel = OrderCancellationService::CUSTOMER_REASONS[$reasonCode] ?? $reasonCode;
            ?>
            <div class="at-admin-od-callout mt-4">
                <p class="font-bold">Cancellation</p>
                <p class="text-sm"><?= Security::escape(ucfirst((string) ($cancelEvt['requested_by'] ?? ''))) ?> · <?= Security::escape(customer_format_datetime((string) ($cancelEvt['created_at'] ?? ''))) ?></p>
                <p class="text-sm"><strong>Reason:</strong> <?= Security::escape($reasonLabel) ?>
                    <?php if (trim((string) ($cancelEvt['reason_detail'] ?? '')) !== ''): ?>
                        — <?= Security::escape(trim((string) $cancelEvt['reason_detail'])) ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>
