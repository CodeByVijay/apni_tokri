<?php

declare(strict_types=1);

/** @var array<string, mixed> $product */
/** @var array{url: string, title: string, text: string, whatsapp: string, telegram: string} $productSharePayload */
?>
<div class="at-pdp-share" data-at-product-share>
    <script type="application/json" id="at-product-share-data"><?= json_encode(
        $productSharePayload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
    <button type="button"
        class="at-btn at-btn--ghost at-btn--sm at-focusable at-pdp-share__trigger"
        data-at-product-share-trigger
        aria-label="Share product"
        aria-haspopup="true"
        aria-expanded="false"
        aria-controls="at-product-share-menu">
        <?= at_icon('share', 'w-4 h-4') ?> <span class="at-pdp-share__label">Share</span>
    </button>
    <div id="at-product-share-menu"
        class="at-share-menu hidden"
        data-at-product-share-menu
        role="menu"
        aria-label="Share product"
        hidden>
        <a class="at-share-menu__item at-focusable" role="menuitem" href="<?= Security::escape($productSharePayload['whatsapp']) ?>" target="_blank" rel="noopener noreferrer" data-at-share-whatsapp>
            <?= at_social_icon('whatsapp', 'w-4 h-4') ?> WhatsApp
        </a>
        <a class="at-share-menu__item at-focusable" role="menuitem" href="<?= Security::escape($productSharePayload['telegram']) ?>" target="_blank" rel="noopener noreferrer" data-at-share-telegram>
            <span class="at-share-menu__tg" aria-hidden="true">TG</span> Telegram
        </a>
        <button type="button" class="at-share-menu__item at-focusable" role="menuitem" data-at-share-copy>
            <?= at_icon('link', 'w-4 h-4') ?> Copy link
        </button>
    </div>
</div>
