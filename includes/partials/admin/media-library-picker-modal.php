<?php

declare(strict_types=1);

/** @var int $productId */
$mediaAjax = url_base() . '/admin/ajax/media-library.php';
$pickerCsrf = Csrf::token();
if (!MediaLibrary::isEnabled()) {
    return;
}
?>
<div
    class="at-media-picker hidden"
    data-at-media-picker
    data-media-ajax="<?= Security::escape($mediaAjax) ?>"
    data-csrf="<?= Security::escape($pickerCsrf) ?>"
    data-product-id="<?= (int) $productId ?>"
    role="dialog"
    aria-modal="true"
    aria-labelledby="at-media-picker-title"
>
    <div class="at-media-picker__backdrop" data-at-media-picker-close></div>
    <div class="at-media-picker__panel at-admin-card">
        <div class="at-admin-card__body space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="at-media-picker-title" class="font-bold">Choose from media library</h3>
                <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-media-picker-close aria-label="Close">×</button>
            </div>
            <div class="flex flex-wrap gap-2 items-end">
                <div class="flex-1 min-w-[10rem]">
                    <label class="at-label" for="at-media-picker-q">Search</label>
                    <input id="at-media-picker-q" type="search" class="at-input at-focusable" data-at-media-picker-q>
                </div>
                <div>
                    <label class="at-label" for="at-media-picker-usage">Filter</label>
                    <select id="at-media-picker-usage" class="at-input at-focusable" data-at-media-picker-usage>
                        <option value="">All</option>
                        <option value="unused">Unused</option>
                        <option value="used">Used</option>
                    </select>
                </div>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-media-picker-search>Search</button>
            </div>
            <ul class="at-media-library__grid at-media-picker__grid" data-at-media-picker-grid role="list"></ul>
            <div class="flex flex-wrap justify-between items-center gap-2">
                <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable hidden" data-at-media-picker-more>Load more</button>
                <span class="text-sm at-muted" data-at-media-picker-status></span>
                <div class="flex gap-2">
                    <button type="button" class="at-btn at-btn--ghost at-focusable" data-at-media-picker-close>Cancel</button>
                    <button type="button" class="at-btn at-btn--primary at-focusable" data-at-media-picker-confirm disabled>Attach selected</button>
                </div>
            </div>
        </div>
    </div>
</div>
