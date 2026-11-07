<?php

declare(strict_types=1);

/**
 * @var list<array{status?: string, note?: string, created_at?: string, admin_name?: string|null}> $history
 */
$history = $history ?? [];
if ($history === []) {
    return;
}
$events = AdminOrderStatusTimeline::present($history);
?>
<ol class="at-admin-order-timeline" role="list">
    <?php foreach ($events as $event): ?>
        <?php
        $tone = (string) ($event['tone'] ?? AdminOrderStatusTimeline::TONE_COMPLETED);
        if (!in_array($tone, [
            AdminOrderStatusTimeline::TONE_COMPLETED,
            AdminOrderStatusTimeline::TONE_PENDING,
            AdminOrderStatusTimeline::TONE_FAILURE,
            AdminOrderStatusTimeline::TONE_RESOLVED,
        ], true)) {
            $tone = AdminOrderStatusTimeline::TONE_COMPLETED;
        }
        $itemClass = 'at-admin-order-timeline__item at-admin-order-timeline__item--' . $tone;
        if (!empty($event['is_latest'])) {
            $itemClass .= ' is-latest';
        }
        ?>
        <li class="<?= Security::escape($itemClass) ?>">
            <div class="at-admin-order-timeline__marker" aria-hidden="true">
                <?= at_icon((string) $event['icon'], 'at-admin-order-timeline__icon') ?>
            </div>
            <div class="at-admin-order-timeline__content">
                <div class="at-admin-order-timeline__head">
                    <p class="at-admin-order-timeline__title"><?= Security::escape((string) $event['title']) ?></p>
                    <?php if ((string) $event['datetime_attr'] !== ''): ?>
                        <time class="at-admin-order-timeline__time" datetime="<?= Security::escape((string) $event['datetime_attr']) ?>">
                            <?= Security::escape((string) $event['datetime']) ?>
                        </time>
                    <?php else: ?>
                        <span class="at-admin-order-timeline__time"><?= Security::escape((string) $event['datetime']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (trim((string) $event['description']) !== ''): ?>
                    <p class="at-admin-order-timeline__desc"><?= nl2br(Security::escape((string) $event['description']), false) ?></p>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ol>
