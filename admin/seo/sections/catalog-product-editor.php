<?php

declare(strict_types=1);

/** @var array<string, mixed> $editProduct */
/** @var array<string, scalar|null> $catalogParams */
/** @var bool $useDb */
/** @var bool $simple */

$editId = (int) ($editProduct['id'] ?? 0);
$metaTitle = trim((string) ($editProduct['meta_title'] ?? ''));
$metaDesc = trim((string) ($editProduct['meta_description'] ?? ''));
$slug = (string) ($editProduct['slug'] ?? '');
$robotsOn = !empty($editProduct['robots_index']);
$suggest = SeoAdminCenter::templateSuggestionsForProduct($editId);
$suggestTitle = (string) ($suggest['title'] ?? '');
$suggestDesc = (string) ($suggest['description'] ?? '');
$previewUrl = SeoAdminCenter::productPublicSeoUrl($slug);
$closeUrl = SeoAdminCenter::catalogSectionUrl(array_diff_key($catalogParams, ['product_id' => true]));
$hasExisting = $metaTitle !== '' || $metaDesc !== '';
?>
<div class="at-seo-editor-backdrop" data-at-seo-editor-backdrop>
    <div class="at-seo-editor at-seo-saas__panel" role="dialog" aria-labelledby="at-seo-editor-title" aria-modal="true">
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 id="at-seo-editor-title" class="font-bold text-lg">Manage SEO</h3>
                    <p class="text-sm at-muted"><?= Security::escape((string) ($editProduct['name'] ?? '')) ?>
                        <span class="font-mono text-xs"> · <?= Security::escape((string) ($editProduct['sku'] ?? '')) ?></span></p>
                </div>
                <a href="<?= Security::escape($closeUrl) ?>" class="at-btn at-btn--ghost at-btn--sm at-focusable">Cancel</a>
            </div>

            <form method="post" class="space-y-4" id="at-seo-product-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="catalog">
                <input type="hidden" name="seo_action" value="save_product_seo">
                <input type="hidden" name="product_id" value="<?= $editId ?>">
                <?php foreach ($catalogParams as $hk => $hv): ?>
                    <?php if ($hk === 'product_id') {
                        continue;
                    } ?>
                    <input type="hidden" name="<?= Security::escape((string) $hk) ?>" value="<?= Security::escape((string) $hv) ?>">
                <?php endforeach; ?>

                <div>
                    <label class="at-label" for="seo_meta_title"><?= $simple ? 'Title for Google search' : 'SEO title' ?></label>
                    <input id="seo_meta_title" name="meta_title" class="at-input at-focusable" maxlength="70"
                           value="<?= Security::escape($metaTitle) ?>" data-at-seo-title>
                    <p class="text-xs at-muted mt-1"><span data-at-seo-title-count><?= mb_strlen($metaTitle) ?></span> / 70 — aim for a clear title with your product name and city.</p>
                </div>
                <div>
                    <label class="at-label" for="seo_meta_desc"><?= $simple ? 'Short summary for search results' : 'Meta description' ?></label>
                    <textarea id="seo_meta_desc" name="meta_description" class="at-textarea at-focusable" rows="4" maxlength="320"
                              data-at-seo-desc><?= Security::escape($metaDesc) ?></textarea>
                    <p class="text-xs at-muted mt-1"><span data-at-seo-desc-count><?= mb_strlen($metaDesc) ?></span> / 320 — at least 50 characters helps Google show a useful snippet.</p>
                </div>

                <div class="text-sm">
                    <span class="at-muted">Page address (slug):</span>
                    <span class="font-mono break-all"><?= Security::escape($slug) ?></span>
                    <span class="text-xs at-muted block mt-1">To change the slug, use Admin → Products → edit product.</span>
                </div>

                <?php if (!SeoAdminCenter::isSimple()): ?>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="robots_index" value="1"<?= $robotsOn ? ' checked' : '' ?>>
                        Allow search engines to index this product
                    </label>
                <?php else: ?>
                    <input type="hidden" name="robots_index" value="<?= $robotsOn ? '1' : '0' ?>">
                <?php endif; ?>

                <div>
                    <p class="text-sm font-medium mb-1">Search preview (approximate)</p>
                    <div data-at-seo-live-preview><?= SeoAdminCenter::searchPreview($metaTitle, $metaDesc, $previewUrl) ?></div>
                </div>

                <div class="at-admin-seo-panel space-y-2">
                    <p class="text-sm font-bold">Template suggestions</p>
                    <p class="text-xs at-muted">Generated from your saved product template — review before using.</p>
                    <p class="text-sm"><span class="at-muted">Title:</span> <?= Security::escape($suggestTitle) ?></p>
                    <p class="text-sm"><span class="at-muted">Description:</span> <?= Security::escape($suggestDesc) ?></p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save SEO</button>
                    <a href="<?= Security::escape($closeUrl) ?>" class="at-btn at-btn--secondary at-focusable">Cancel</a>
                </div>
            </form>

            <form method="post" class="border-t border-[var(--at-line)] pt-3 space-y-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="catalog">
                <input type="hidden" name="seo_action" value="apply_product_template">
                <input type="hidden" name="product_id" value="<?= $editId ?>">
                <?php foreach ($catalogParams as $hk => $hv): ?>
                    <?php if ($hk === 'product_id') {
                        continue;
                    } ?>
                    <input type="hidden" name="<?= Security::escape((string) $hk) ?>" value="<?= Security::escape((string) $hv) ?>">
                <?php endforeach; ?>
                <p class="text-sm font-medium">Apply template to this product</p>
                <label class="flex gap-2 text-sm">
                    <input type="checkbox" name="only_fill_blanks" value="1" checked>
                    Only fill empty title or description
                </label>
                <?php if ($hasExisting): ?>
                    <label class="flex gap-2 text-sm text-amber-900">
                        <input type="checkbox" name="confirm_overwrite" value="1">
                        Replace my existing SEO text with the template above
                    </label>
                <?php endif; ?>
                <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable"<?= $useDb ? '' : ' disabled' ?>>
                    Apply template
                </button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var title = document.querySelector('[data-at-seo-title]');
    var desc = document.querySelector('[data-at-seo-desc]');
    var preview = document.querySelector('[data-at-seo-live-preview]');
    var siteUrl = <?= json_encode($previewUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function render() {
        var t = title ? title.value : '';
        var d = desc ? desc.value : '';
        var tc = document.querySelector('[data-at-seo-title-count]');
        var dc = document.querySelector('[data-at-seo-desc-count]');
        if (tc) tc.textContent = String(t.length);
        if (dc) dc.textContent = String(d.length);
        if (!preview) return;
        var showT = t || 'Your product title';
        var showD = d || 'Add a short summary shoppers may see below your title in Google.';
        var snippet = showD.length > 160 ? showD.slice(0, 160) + '…' : showD;
        preview.innerHTML = '<div class="at-seo-preview"><p class="at-seo-preview__title">' + esc(showT) +
            '</p><p class="at-seo-preview__url">' + esc(siteUrl) +
            '</p><p class="at-seo-preview__desc">' + esc(snippet) + '</p></div>';
    }
    if (title) title.addEventListener('input', render);
    if (desc) desc.addEventListener('input', render);
    document.querySelector('[data-at-seo-editor-backdrop]')?.addEventListener('click', function (e) {
        if (e.target === this) {
            window.location.href = <?= json_encode($closeUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
    });
})();
</script>
