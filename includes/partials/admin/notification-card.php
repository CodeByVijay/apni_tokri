<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
$item = $item ?? [];
$id = (int) ($item['id'] ?? 0);
$isRead = !empty($item['is_read']);
$kind = (string) ($item['event_kind'] ?? 'generic');
$icon = $kind === 'customer_reply' ? 'chat' : ($kind === 'ticket_created' ? 'bell' : 'grid');
?>
<a href="<?= Security::escape((string) ($item['href'] ?? '#')) ?>"
   class="at-admin-notif-card at-focusable<?= $isRead ? '' : ' is-unread' ?>"
   data-notification-id="<?= $id ?>">
    <span class="at-admin-notif-card__icon" aria-hidden="true"><?= at_icon($icon) ?></span>
    <span class="at-admin-notif-card__body">
        <span class="at-admin-notif-card__title-row">
            <span class="at-admin-notif-card__title"><?= Security::escape((string) ($item['title'] ?? '')) ?></span>
            <?php if (!$isRead): ?>
                <span class="at-admin-notif-card__dot" aria-label="Unread"></span>
            <?php endif; ?>
        </span>
        <?php if ((string) ($item['customer_name'] ?? '') !== ''): ?>
            <span class="at-admin-notif-card__meta"><?= Security::escape((string) $item['customer_name']) ?></span>
        <?php endif; ?>
        <span class="at-admin-notif-card__desc"><?= Security::escape((string) ($item['body'] ?? '')) ?></span>
        <span class="at-admin-notif-card__foot">
            <?php if ((string) ($item['ticket_number'] ?? '') !== ''): ?>
                <span class="font-mono"><?= Security::escape((string) $item['ticket_number']) ?></span>
                <span class="mx-1">·</span>
            <?php endif; ?>
            <time datetime="<?= Security::escape((string) ($item['created_at'] ?? '')) ?>">
                <?= Security::escape((string) ($item['relative_time'] ?? ($item['created_at'] ?? ''))) ?>
            </time>
        </span>
    </span>
</a>
