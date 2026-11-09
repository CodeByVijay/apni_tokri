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
<div class="at-seo-saas__stack">
    <div class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title">XML sitemap</h3>
            <p class="text-sm at-muted"><?= $simple ? 'A sitemap is a list of pages you want Google to find.' : 'Public sitemap endpoint for crawlers.' ?></p>
            <dl class="text-sm grid sm:grid-cols-2 gap-2">
                <div><dt class="at-muted">Sitemap URL</dt><dd class="font-mono text-xs break-all"><a class="underline at-focusable" href="<?= Security::escape($sitemapUrl) ?>" target="_blank" rel="noopener"><?= Security::escape($sitemapUrl) ?></a></dd></div>
                <div><dt class="at-muted">Indexable URLs</dt><dd><?= (int) $urlCount ?></dd></div>
                <div><dt class="at-muted">Status</dt><dd><?= $urlCount > 0 ? 'Ready' : 'Not configured yet — publish products or pages first.' ?></dd></div>
            </dl>
            <p class="text-xs at-muted">Submit the sitemap URL in Google Search Console after verification. Generating the sitemap does not mean Google has indexed your site.</p>
            <?php
            $staticPath = SeoSitemap::staticRootPath();
            $staticMtime = is_file($staticPath) ? filemtime($staticPath) : false;
            ?>
            <dl class="text-sm grid sm:grid-cols-2 gap-2 mt-3">
                <div><dt class="at-muted">Static file</dt><dd class="font-mono text-xs break-all"><?= Security::escape($staticPath) ?></dd></div>
                <div><dt class="at-muted">Last regenerated</dt><dd><?= $staticMtime !== false ? Security::escape(date('Y-m-d H:i:s', $staticMtime)) : '—' ?></dd></div>
            </dl>
            <form method="post" class="mt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="technical">
                <input type="hidden" name="seo_action" value="regenerate_static_sitemap">
                <button type="submit" class="at-btn at-btn--secondary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Regenerate sitemap.xml file</button>
            </form>
            <p class="text-xs at-muted mt-1">CLI: <code class="font-mono">php scripts/regenerate-sitemap-xml.php</code> — also updates automatically after catalogue and CMS changes.</p>
    </div>

    <div class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title">robots.txt</h3>
            <p class="text-sm at-muted">Controls what crawlers may request. Your live file is generated safely; add extra rules only if you know what they do.</p>
            <p class="text-xs"><a href="<?= Security::escape($robotsPublic) ?>" class="underline at-focusable" target="_blank" rel="noopener">View robots.txt</a></p>
            <pre class="text-xs bg-neutral-100 p-3 rounded overflow-auto max-h-48"><?= Security::escape($robotsBody) ?></pre>
            <?php if (!SeoAdminCenter::isSimple()): ?>
            <form method="post" class="space-y-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="technical">
                <input type="hidden" name="seo_action" value="save_robots_extra">
                <label class="at-label">Additional robots.txt lines</label>
                <textarea class="at-input at-focusable font-mono text-xs" name="robots_extra" rows="6" placeholder="# User-agent: *&#10;# Disallow: /private/"><?= Security::escape((string) $robotsExtra) ?></textarea>
                <p class="text-xs text-amber-800">Never use Disallow: / — it blocks your entire website.</p>
                <button type="submit" class="at-btn at-btn--secondary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save extra rules</button>
            </form>
            <?php endif; ?>
    </div>

    <div class="at-seo-saas__panel text-sm">
            <h3 class="at-seo-saas__panel-title">Canonical URLs & indexing</h3>
            <p class="at-muted"><?= $simple ? 'Each product and page editor can set whether Google should index it and which URL is canonical.' : 'Overrides live on product, category, and CMS page records.' ?></p>
            <ul class="list-disc pl-5 at-muted">
                <li>Products & categories → edit item → SEO tab</li>
                <li>CMS pages → Content → edit page</li>
                <li>Homepage robots → Pages SEO</li>
            </ul>
    </div>
</div>
