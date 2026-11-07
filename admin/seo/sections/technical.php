<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$sitemapUrl = SeoRobots::sitemapAbsoluteUrl();
$urlCount = 0;
try {
    $urlCount = count(SeoSitemap::collectIndexableUrls());
} catch (Throwable $e) {
    $urlCount = 0;
}
$robotsBody = SeoRobots::render();
$robotsExtra = AppSettings::get(SeoAdminCenter::KEY_ROBOTS_EXTRA, '') ?? '';
$robotsPublic = url_base() . '/robots.php';
$sitemapPublic = url_base() . '/sitemap.php';
?>
<div class="grid gap-4">
    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">XML sitemap</h3>
            <p class="text-sm at-muted"><?= $simple ? 'A sitemap is a list of pages you want Google to find.' : 'Public sitemap endpoint for crawlers.' ?></p>
            <dl class="text-sm grid sm:grid-cols-2 gap-2">
                <div><dt class="at-muted">Sitemap URL</dt><dd class="font-mono text-xs break-all"><a class="underline at-focusable" href="<?= Security::escape($sitemapUrl) ?>" target="_blank" rel="noopener"><?= Security::escape($sitemapUrl) ?></a></dd></div>
                <div><dt class="at-muted">Indexable URLs</dt><dd><?= (int) $urlCount ?></dd></div>
                <div><dt class="at-muted">Status</dt><dd><?= $urlCount > 0 ? 'Ready' : 'Not configured yet — publish products or pages first.' ?></dd></div>
            </dl>
            <p class="text-xs at-muted">Submit the sitemap URL in Google Search Console after verification. Generating the sitemap does not mean Google has indexed your site.</p>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold">robots.txt</h3>
            <p class="text-sm at-muted">Controls what crawlers may request. Your live file is generated safely; add extra rules only if you know what they do.</p>
            <p class="text-xs"><a href="<?= Security::escape($robotsPublic) ?>" class="underline at-focusable" target="_blank" rel="noopener">View robots.txt</a></p>
            <pre class="text-xs bg-neutral-100 p-3 rounded overflow-auto max-h-48"><?= Security::escape($robotsBody) ?></pre>
            <?php if (!SeoAdminCenter::isSimple()): ?>
            <form method="post" class="space-y-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="seo_action" value="save_robots_extra">
                <label class="at-label">Additional robots.txt lines</label>
                <textarea class="at-input at-focusable font-mono text-xs" name="robots_extra" rows="6" placeholder="# User-agent: *&#10;# Disallow: /private/"><?= Security::escape((string) $robotsExtra) ?></textarea>
                <p class="text-xs text-amber-800">Never use Disallow: / — it blocks your entire website.</p>
                <button type="submit" class="at-btn at-btn--secondary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save extra rules</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="at-card">
        <div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Canonical URLs & indexing</h3>
            <p class="at-muted"><?= $simple ? 'Each product and page editor can set whether Google should index it and which URL is canonical.' : 'Overrides live on product, category, and CMS page records.' ?></p>
            <ul class="list-disc pl-5 at-muted">
                <li>Products & categories → edit item → SEO tab</li>
                <li>CMS pages → Content → edit page</li>
                <li>Homepage robots → Pages SEO</li>
            </ul>
        </div>
    </div>
</div>
