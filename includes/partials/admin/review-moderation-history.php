<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $moderationHistoryRows */
/** @var bool|null $compact */
$compact = !empty($compact);
?>
<div class="at-card<?= $compact ? '' : ' mt-4' ?>">
    <div class="at-card__body">
        <h3 class="at-h3">Moderation history</h3>
        <?php if (($moderationHistoryRows ?? []) === []): ?>
            <p class="text-sm at-muted mt-2">No moderation actions recorded yet.</p>
        <?php else: ?>
            <ul class="mt-3 space-y-3 text-sm">
                <?php foreach ($moderationHistoryRows as $h): ?>
                    <li class="border-b border-neutral-200 pb-3 last:border-0">
                        <p class="font-semibold">
                            <?= Security::escape((string) ($h['action_label'] ?? '')) ?>
                            <span class="font-normal at-muted">· <?= Security::escape((string) ($h['created_at'] ?? '')) ?></span>
                        </p>
                        <p class="at-muted">Review #<?= (int) ($h['review_id'] ?? 0) ?>
                            <?php if (!empty($h['product_name'])): ?>
                                — <?= Security::escape((string) $h['product_name']) ?>
                            <?php endif; ?>
                        </p>
                        <p>Actor: <?= Security::escape((string) ($h['actor_display'] ?? '—')) ?></p>
                        <?php
                        $prev = (string) ($h['previous_status'] ?? '');
                        $new = (string) ($h['new_status'] ?? '');
                        if ($prev !== '' || $new !== ''):
                            ?>
                            <p>Status: <?= Security::escape($prev !== '' ? $prev : '—') ?>
                                → <?= Security::escape($new !== '' ? $new : '—') ?></p>
                        <?php endif; ?>
                        <?php if (!empty($h['reason_display'])): ?>
                            <p class="text-red-700">Reason: <?= Security::escape((string) $h['reason_display']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($h['admin_reply_display'])): ?>
                            <p><strong>Reply:</strong> <?= Security::escape((string) $h['admin_reply_display']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
