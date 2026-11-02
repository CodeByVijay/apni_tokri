<?php

declare(strict_types=1);

/** @var string $emptyTitle */
/** @var string $emptyMessage */
/** @var string|null $emptyActionHref */
/** @var string|null $emptyActionLabel */
/** @var string|null $emptyIcon */
$emptyIcon = $emptyIcon ?? 'basket';
?>
<div class="at-empty">
    <div class="at-empty__icon"><?= at_icon($emptyIcon) ?></div>
    <h2 class="at-h2 !text-xl"><?= Security::escape($emptyTitle) ?></h2>
    <p class="mt-2 text-sm at-muted"><?= Security::escape($emptyMessage) ?></p>
    <?php if (!empty($emptyActionHref) && !empty($emptyActionLabel)): ?>
        <a href="<?= Security::escape($emptyActionHref) ?>" class="at-btn at-btn--primary at-focusable mt-6">
            <?= Security::escape($emptyActionLabel) ?>
        </a>
    <?php endif; ?>
</div>
