<?php

declare(strict_types=1);

/** @var array<string, mixed> $img */
/** @var int $productId */
$productId = (int) ($productId ?? 0);
$src = asset_url((string) $img['file_path']);
$id = (int) $img['id'];
?>
<li class="at-product-img-card" data-image-id="<?= $id ?>" draggable="true">
    <img src="<?= Security::escape($src) ?>" alt="" class="at-product-img-card__thumb" width="112" height="112" loading="lazy">
    <?php if (!empty($img['is_primary'])): ?>
        <span class="at-product-img-card__badge">Primary</span>
    <?php endif; ?>
    <div class="at-product-img-card__actions">
        <?php if (empty($img['is_primary'])): ?>
            <button type="button" class="at-btn at-btn--ghost at-btn--xs at-focusable" data-at-set-primary="<?= $id ?>">Set primary</button>
        <?php endif; ?>
        <form method="post" class="inline" data-at-delete-image-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete_image">
            <input type="hidden" name="id" value="<?= $productId ?>">
            <input type="hidden" name="image_id" value="<?= $id ?>">
            <button type="submit" class="at-btn at-btn--ghost at-btn--xs text-red-700 at-focusable">Remove</button>
        </form>
    </div>
</li>
