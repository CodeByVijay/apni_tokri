<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$del = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
$rescheduleNotes = $order['reschedule_notes'] ?? [];
?>
<section class="at-card" aria-labelledby="at-admin-od-fulfil-title">
    <div class="at-card__body text-sm">
        <h2 id="at-admin-od-fulfil-title" class="at-admin-od-section-title">Delivery &amp; fulfilment</h2>
        <dl class="at-admin-od-kv-grid">
            <div><dt>Delivery date</dt><dd><?= !empty($del['delivery_date']) ? Security::escape((string) $del['delivery_date']) : '—' ?></dd></div>
            <div><dt>Slot</dt><dd>
                <?php
                $slotLine = $del['slot_display'] ?? $order['delivery_slot_label'] ?? null;
                echo $slotLine !== null && $slotLine !== '' ? Security::escape((string) $slotLine) : '—';
                ?>
            </dd></div>
            <div><dt>Fulfilment status</dt><dd><?= admin_status_badge((string) ($del['fulfilment_status'] ?? $order['status'] ?? ''), (string) ($del['fulfilment_status'] ?? '')) ?></dd></div>
            <div><dt>Assigned driver</dt><dd><?php if (!empty($del['driver_name'])) {
                echo Security::escape((string) $del['driver_name']);
            } else { ?><span class="at-muted">—</span><?php } ?></dd></div>
            <div><dt>Dispatched</dt><dd><?= !empty($del['dispatched_at']) ? Security::escape(customer_format_datetime((string) $del['dispatched_at'])) : '—' ?></dd></div>
            <div><dt>Delivered</dt><dd><?= !empty($del['delivered_at']) ? Security::escape(customer_format_datetime((string) $del['delivered_at'])) : '—' ?></dd></div>
        </dl>
        <?php if (!empty($order['delivery_id'])): ?>
            <p class="mt-3"><a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/deliveries/view.php?id=<?= (int) $order['delivery_id'] ?>">Open delivery fulfilment record</a></p>
        <?php endif; ?>
        <?php if ($rescheduleNotes !== []): ?>
            <details class="at-admin-od-collapsible mt-4" open>
                <summary>Reschedule history (from status notes)</summary>
                <ul class="mt-2 space-y-2 list-disc pl-5">
                    <?php foreach ($rescheduleNotes as $note): ?>
                        <li>
                            <time datetime="<?= Security::escape((string) ($note['at'] ?? '')) ?>"><?= Security::escape(customer_format_datetime((string) ($note['at'] ?? ''))) ?></time>
                            — <?= Security::escape((string) ($note['note'] ?? '')) ?>
                            <?php if (!empty($note['admin_name'])): ?>
                                <span class="at-muted">(<?= Security::escape((string) $note['admin_name']) ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
</section>
