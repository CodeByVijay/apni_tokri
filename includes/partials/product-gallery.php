<?php

declare(strict_types=1);

/** @var array<string, mixed> $product */
$images = $product['images'] ?? [];
if ($images === []) {
    $images = [asset_url('assets/images/logo-placeholder.svg')];
}
?>
<div class="at-gallery" data-at-gallery>
    <div class="at-gallery__stage" data-at-gallery-stage>
        <img id="at-gallery-main" class="at-img-fit" src="<?= Security::escape($images[0]) ?>" alt="<?= Security::escape((string) $product['name']) ?>" width="640" height="480" decoding="async" fetchpriority="high">
        <?php if (count($images) > 1): ?>
            <button type="button" class="at-gallery__nav at-gallery-nav at-gallery-prev at-focusable" style="left:.6rem" aria-label="Previous image">‹</button>
            <button type="button" class="at-gallery__nav at-gallery-nav at-gallery-next at-focusable" style="right:.6rem" aria-label="Next image">›</button>
            <div class="at-gallery__dots" aria-hidden="true">
                <?php foreach ($images as $i => $src): ?><i class="<?= $i === 0 ? 'is-on' : '' ?>"></i><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <button type="button" class="at-badge at-badge--dark at-focusable js-gallery-zoom absolute top-3 right-3" aria-label="View larger image">
            <?= at_icon('search', 'w-3.5 h-3.5') ?> Zoom
        </button>
    </div>
    <?php if (count($images) > 1): ?>
        <ul class="flex gap-2 mt-3 overflow-x-auto pb-1 at-no-scrollbar" role="list">
            <?php foreach ($images as $i => $src): ?>
                <li>
                    <button type="button"
                        class="at-gallery-thumb at-focusable <?= $i === 0 ? 'border-brand-green' : 'border-transparent' ?>"
                        data-index="<?= (int) $i ?>"
                        data-src="<?= Security::escape($src) ?>"
                        aria-label="Image <?= (int) ($i + 1) ?>">
                        <img class="at-img-fit" src="<?= Security::escape($src) ?>" alt="" loading="lazy" width="68" height="68">
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <script type="application/json" id="at-gallery-data"><?= json_encode(array_values($images), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?></script>
</div>
