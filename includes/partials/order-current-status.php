<?php

declare(strict_types=1);

/** @var array<string, mixed> $currentStatus */
/** @var string $orderStatus */
if ($currentStatus === []) {
    $currentStatus = [
        'status' => $orderStatus,
        'title' => ucwords(str_replace('_', ' ', $orderStatus)),
        'explanation' => 'Here is the latest update on your order.',
        'icon' => 'receipt',
        'when_label' => null,
        'when_value' => null,
        'refund_note' => null,
    ];
}
$csTitle = (string) ($currentStatus['title'] ?? $orderStatus);
$csExplanation = (string) ($currentStatus['explanation'] ?? '');
$csIcon = (string) ($currentStatus['icon'] ?? 'receipt');
$csStatus = (string) ($currentStatus['status'] ?? $orderStatus);
?>
<section class="at-order-status-card at-card" aria-labelledby="at-order-current-status-title">
    <div class="at-card__body">
        <p class="at-order-status-card__eyebrow">Current order status</p>
        <div class="at-order-status-card__main">
            <span class="at-order-status-card__icon" aria-hidden="true"><?= at_icon($csIcon, 'w-6 h-6') ?></span>
            <div class="at-order-status-card__text min-w-0">
                <h2 id="at-order-current-status-title" class="at-order-status-card__title">
                    <?= customer_status_badge($csStatus, $csTitle, 'order') ?>
                </h2>
                <?php if ($csExplanation !== ''): ?>
                    <p class="at-order-status-card__explain"><?= Security::escape($csExplanation) ?></p>
                <?php endif; ?>
                <?php if (!empty($currentStatus['when_label']) && !empty($currentStatus['when_value'])): ?>
                    <p class="at-order-status-card__when text-sm mt-2">
                        <strong><?= Security::escape((string) $currentStatus['when_label']) ?>:</strong>
                        <?= Security::escape((string) $currentStatus['when_value']) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($currentStatus['refund_note'])): ?>
                    <p class="at-order-status-card__refund text-sm mt-2 at-muted"><?= Security::escape((string) $currentStatus['refund_note']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
