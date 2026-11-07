<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$homeTitle = AppSettings::get('seo.home.meta_title', '') ?? '';
$homeDesc = AppSettings::get('seo.home.meta_description', '') ?? '';
$homeIndex = AppSettings::get('seo.home.robots_index', '1') !== '0';
$previewUrl = SeoUrl::absolute('/');
$cmsRows = [];
if ($useDb && CmsPageService::schemaReady()) {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT id, slug, title, meta_title, meta_description, robots_index, visibility, status
         FROM cms_pages WHERE status = ? ORDER BY title ASC LIMIT 200'
    );
    $stmt->execute([PublishStatus::PUBLISHED]);
    $cmsRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<div class="at-seo-saas__stack">
    <div class="at-seo-saas__panel max-w-2xl">
            <h3 class="at-seo-saas__panel-title">Homepage</h3>
            <p class="at-seo-saas__panel-sub">The main listing shoppers see when they search for your store.</p>
            <form method="post" class="space-y-3 mt-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="seo_action" value="save_homepage">
                <div>
                    <label class="at-label"><?= $simple ? 'Title in Google results' : 'SEO title' ?></label>
                    <input class="at-input at-focusable" name="home_meta_title" maxlength="70" value="<?= Security::escape((string) $homeTitle) ?>">
                </div>
                <div>
                    <label class="at-label"><?= $simple ? 'Short summary' : 'Meta description' ?></label>
                    <textarea class="at-input at-focusable" name="home_meta_description" rows="3" maxlength="320"><?= Security::escape((string) $homeDesc) ?></textarea>
                </div>
                <?php if (!SeoAdminCenter::isSimple()): ?>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="home_robots_index" value="1"<?= $homeIndex ? ' checked' : '' ?>> index, follow</label>
                <?php else: ?>
                    <input type="hidden" name="home_robots_index" value="<?= $homeIndex ? '1' : '0' ?>">
                <?php endif; ?>
                <?= SeoAdminCenter::searchPreview((string) $homeTitle, (string) $homeDesc, $previewUrl) ?>
                <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save</button>
            </form>
    </div>

    <div class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title">Content pages</h3>
            <p class="text-sm at-muted">Edit full SEO fields (canonical, Open Graph, robots) in each page editor. Login, checkout, cart and account routes stay non-indexable by default.</p>
            <ul class="divide-y divide-[var(--at-line)] text-sm">
                <?php foreach ($cmsRows as $page): ?>
                    <?php
                    $vis = (string) ($page['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC);
                    $mt = trim((string) ($page['meta_title'] ?? ''));
                    $md = trim((string) ($page['meta_description'] ?? ''));
                    $warn = $vis === CmsPageService::VISIBILITY_PUBLIC && ($mt === '' || $md === '');
                    ?>
                    <li class="py-2 flex flex-wrap justify-between gap-2">
                        <span>
                            <?= Security::escape((string) ($page['title'] ?? $page['slug'] ?? '')) ?>
                            <?php if ($warn): ?><span class="text-amber-700 text-xs"> · needs SEO text</span><?php endif; ?>
                            <?php if ($vis !== CmsPageService::VISIBILITY_PUBLIC): ?><span class="at-muted text-xs"> · <?= Security::escape($vis) ?></span><?php endif; ?>
                        </span>
                        <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="<?= Security::escape(url_base() . '/admin/cms/edit.php?id=' . (int) $page['id']) ?>">Edit page SEO</a>
                    </li>
                <?php endforeach; ?>
                <?php if ($cmsRows === []): ?>
                    <li class="at-muted">No published CMS pages yet.</li>
                <?php endif; ?>
            </ul>
    </div>
</div>
