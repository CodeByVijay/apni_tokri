<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$refunds = $order['refunds'] ?? [];
if ($refunds === []) {
    return;
}
?>
<details class="at-card at-admin-od-collapsible" open>
    <summary class="at-admin-od-collapsible__summary at-card__body">
        <h2 class="at-admin-od-section-title">Refund &amp; cancellation</h2>
    </summary>
    <div class="at-card__body pt-0">
        <div class="at-admin-table-wrap">
            <table class="at-admin-table at-admin-table--dense">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Status</th>
                        <th class="is-num">Amount</th>
                        <th>Gateway refund ref</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($refunds as $ref): ?>
                    <tr>
                        <td class="font-mono text-sm"><?= Security::escape((string) ($ref['request_number'] ?? '')) ?></td>
                        <td><?= admin_status_badge((string) ($ref['status'] ?? ''), (string) ($ref['status_label'] ?? '')) ?></td>
                        <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($ref['amount_inr'] ?? 0))) ?></td>
                        <td class="is-id text-xs"><?= ($ref['gateway_refund_id'] ?? '') !== '' ? Security::escape((string) $ref['gateway_refund_id']) : '—' ?></td>
                        <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($ref['created_at'] ?? ''))) ?></td>
                        <td><a class="underline" href="<?= Security::escape((string) ($ref['admin_url'] ?? '')) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs at-muted mt-2">Process refunds from the Refunds module — not from this screen.</p>
    </div>
</details>
