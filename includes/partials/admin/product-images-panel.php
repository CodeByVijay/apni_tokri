<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $images */
/** @var array<string, mixed> $product */
$productId = (int) $product['id'];
$imageCount = count($images);
$maxImages = ProductRepository::MAX_IMAGES_PER_PRODUCT;
$uploadUrl = url_base() . '/admin/ajax/product-images.php';
$csrf = Csrf::token();
$mediaLibrary = MediaLibrary::isEnabled();
?>
<div
    class="at-product-images"
    data-at-product-images
    data-product-id="<?= $productId ?>"
    data-upload-url="<?= Security::escape($uploadUrl) ?>"
    data-csrf="<?= Security::escape($csrf) ?>"
    data-max-images="<?= $maxImages ?>"
    data-image-count="<?= $imageCount ?>"
    data-media-library="<?= $mediaLibrary ? '1' : '0' ?>"
>
    <p class="text-sm at-muted mb-3">
        <?= (int) $imageCount ?> / <?= $maxImages ?> images · JPEG, PNG or WebP · max 5 MB each
    </p>
    <div class="flex flex-wrap gap-2 mb-3">
        <?php if ($mediaLibrary): ?>
            <a href="<?= Security::escape(url_base()) ?>/admin/media/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Media library</a>
            <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-open-media-picker>Choose from library</button>
        <?php endif; ?>
    </div>
    <ul class="at-product-images__gallery js-image-sortable" data-at-product-images-gallery role="list">
        <?php foreach ($images as $img): ?>
            <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/product-images-gallery-item.php'; ?>
        <?php endforeach; ?>
    </ul>
    <?php if ($imageCount < $maxImages): ?>
        <div class="at-product-images__dropzone mt-4" data-at-image-dropzone tabindex="0" role="button" aria-label="Add product images">
            <p class="font-semibold">Drag images here or click to browse</p>
            <p class="text-sm at-muted mt-1">Select multiple files at once</p>
            <input type="file" class="sr-only" data-at-image-file-input multiple accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
        </div>
        <ul class="at-product-images__queue hidden mt-3 space-y-2" data-at-image-queue role="list"></ul>
        <div class="flex flex-wrap items-center gap-3 mt-3">
            <button type="button" class="at-btn at-btn--primary at-focusable" data-at-image-upload disabled>Upload images</button>
            <button type="button" class="at-btn at-btn--ghost at-focusable hidden" data-at-image-clear-queue>Clear selection</button>
        </div>
        <div class="mt-3 space-y-1 text-sm" data-at-image-upload-status role="status" aria-live="polite"></div>
    <?php else: ?>
        <p class="at-alert at-alert--info mt-4">Maximum image count reached. Remove an image to upload more.</p>
    <?php endif; ?>
    <noscript>
        <form method="post" enctype="multipart/form-data" class="at-admin-card max-w-md mt-4">
            <div class="at-admin-card__body space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="upload_image">
                <input type="hidden" name="id" value="<?= $productId ?>">
                <label class="at-label" for="image-noscript">Upload image (JPEG, PNG, WebP — max 5 MB)</label>
                <input id="image-noscript" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="at-input" required>
                <button type="submit" class="at-btn at-btn--secondary at-focusable">Upload</button>
            </div>
        </form>
    </noscript>
    <?php if ($mediaLibrary): ?>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/media-library-picker-modal.php'; ?>
    <?php endif; ?>
</div>
