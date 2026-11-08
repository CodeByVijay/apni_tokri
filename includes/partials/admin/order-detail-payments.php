<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
/** @var bool $canRefreshCashfree */
$payments = $order['payments'] ?? [];
if ($payments === []) {
    return;
}
?>
<details class="at-card at-admin-od-collapsible" open>
    <summary class="at-admin-od-collapsible__summary at-card__body at-admin-order-detail__section-head">
        <h2 class="at-admin-od-section-title">Payment details &amp; attempts</h2>
        <?php if (!empty($canRefreshCashfree)): ?>
            <button type="submit" form="admin-order-refresh-cashfree" name="action" value="refresh_cashfree_payment" class="at-btn at-btn--secondary at-btn--sm at-focusable" onclick="event.stopPropagation();">Refresh Cashfree</button>
        <?php endif; ?>
    </summary>
    <div class="at-card__body pt-0">
        <p class="text-xs at-muted mb-3">Stored payment rows only (no live gateway calls on this page). Admin refresh re-queries Cashfree server-side.</p>
        <div class="at-admin-table-wrap">
            <table class="at-admin-table at-admin-table--dense">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Gateway</th>
                        <th>Status</th>
                        <th class="is-num">Amount</th>
                        <th class="is-num">Refunded</th>
                        <th>Method</th>
                        <th>Gateway order</th>
                        <th>Gateway payment</th>
                        <th>UPI / ref</th>
                        <th>Paid at</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $i => $pay): ?>
                    <tr>
                        <td><?= (int) $i + 1 ?></td>
                        <td><?= Security::escape((string) ($pay['gateway_label'] ?? $pay['gateway'] ?? '')) ?></td>
                        <td><?= admin_status_badge((string) ($pay['status'] ?? ''), (string) ($pay['admin_status_label'] ?? '')) ?></td>
                        <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($pay['amount'] ?? 0))) ?></td>
                        <td class="is-num"><?= (float) ($pay['amount_refunded'] ?? 0) > 0 ? Security::escape(catalogue_format_price((float) $pay['amount_refunded'])) : '—' ?></td>
                        <td><?= !empty($pay['payment_method']) ? Security::escape((string) $pay['payment_method']) : '—' ?></td>
                        <td class="is-id text-xs"><?= ($pay['gateway_order_id'] ?? '') !== '' ? Security::escape((string) $pay['gateway_order_id']) : '—' ?></td>
                        <td class="is-id text-xs"><?= ($pay['gateway_payment_id'] ?? '') !== '' ? Security::escape((string) $pay['gateway_payment_id']) : '—' ?></td>
                        <td class="text-xs">
                            <?php
                            $hints = array_filter([$pay['upi_hint'] ?? null, $pay['bank_reference'] ?? null]);
                            echo $hints !== [] ? Security::escape(implode(' · ', $hints)) : '—';
                            ?>
                        </td>
                        <td class="is-nowrap"><?= ($pay['paid_at'] ?? '') !== '' ? Security::escape(customer_format_datetime((string) $pay['paid_at'])) : '—' ?></td>
                        <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($pay['updated_at'] ?? $pay['created_at'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>
