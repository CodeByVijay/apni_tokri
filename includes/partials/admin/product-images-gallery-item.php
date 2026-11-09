<?php

declare(strict_types=1);

/** @var array<string, mixed> $img */
/** @var int $productId */
$productId = (int) ($productId ?? 0);
$src = product_image_asset_url($img);
$id = (int) $img['id'];
$alt = (string) ($img['alt_text'] ?? '');
$isPrimary = !empty($img['is_primary']);
?>
<li class="at-product-img-card" data-image-id="<?= $id ?>" draggable="true">
    <div class="at-product-img-card__media">
        <img
            src="<?= Security::escape($src) ?>"
            alt="<?= Security::escape($alt) ?>"
            class="at-product-img-card__thumb"
            width="112"
            height="112"
            loading="lazy"
        >
        <?php if ($isPrimary): ?>
            <span class="at-product-img-card__badge">Primary</span>
        <?php endif; ?>
    </div>
    <div class="at-product-img-card__body">
        <?php if (MediaLibrary::isEnabled()): ?>
            <label class="sr-only" for="alt-<?= $id ?>">Alt text</label>
            <input
                id="alt-<?= $id ?>"
                type="text"
                class="at-input at-product-img-card__alt"
                maxlength="255"
                placeholder="Alt text (optional)"
                value="<?= Security::escape($alt) ?>"
                data-at-image-alt="<?= $id ?>"
            >
        <?php endif; ?>
        <div class="at-product-img-card__actions">
            <?php if ($isPrimary): ?>
                <span class="at-product-img-card__primary-pill" aria-hidden="true">Primary</span>
            <?php else: ?>
                <button type="button" class="at-btn at-btn--ghost at-focusable at-product-img-card__btn" data-at-set-primary="<?= $id ?>">Set primary</button>
            <?php endif; ?>
            <form method="post" data-at-delete-image-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="id" value="<?= $productId ?>">
                <input type="hidden" name="image_id" value="<?= $id ?>">
                <button type="submit" class="at-btn at-btn--danger at-focusable at-product-img-card__btn">Remove</button>
            </form>
        </div>
    </div>
</li>
