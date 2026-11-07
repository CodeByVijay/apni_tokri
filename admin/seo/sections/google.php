<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$verify = AppSettings::get(SeoAdminCenter::KEY_GSC_VERIFY, '') ?? '';
$property = AppSettings::get(SeoAdminCenter::KEY_GSC_PROPERTY, '') ?? '';
$ga4 = AppSettings::get(SeoAdminCenter::KEY_GA4_ID, '') ?? '';
$gtm = AppSettings::get(SeoAdminCenter::KEY_GTM_ID, '') ?? '';
$gtmOn = AppSettings::get(SeoAdminCenter::KEY_GTM_ENABLED, '') === '1';
$gscOk = SeoAdminCenter::gscConfigured();
$ga4Ok = SeoAdminCenter::ga4Configured();
?>
<div class="at-seo-saas__stack">
    <div class="at-seo-saas__panel max-w-3xl">
            <h3 class="at-seo-saas__panel-title">Google Search Console</h3>
            <p class="text-sm at-muted">Verify site ownership with the HTML meta tag method. Paste only the <code>content</code> value from Google — not your Google password.</p>
            <p class="text-sm">Status: <strong><?= $gscOk ? 'Configured' : 'Not configured yet' ?></strong></p>
            <form method="post" class="space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="google">
                <input type="hidden" name="seo_action" value="save_google">
                <div>
                    <label class="at-label">Verification meta content</label>
                    <input class="at-input at-focusable font-mono text-sm" name="gsc_verify_meta" value="<?= Security::escape((string) $verify) ?>" placeholder="long alphanumeric code from Google">
                </div>
                <div>
                    <label class="at-label">Search Console property URL (for your reference)</label>
                    <input class="at-input at-focusable" name="gsc_property_url" value="<?= Security::escape((string) $property) ?>" placeholder="https://search.google.com/search-console/...">
                </div>
                <details class="text-sm">
                    <summary class="cursor-pointer at-focusable">How to verify</summary>
                    <ol class="list-decimal pl-5 mt-2 space-y-1 at-muted">
                        <li>Open Google Search Console and add your property.</li>
                        <li>Choose HTML tag verification.</li>
                        <li>Copy the content value and paste it above, then save.</li>
                        <li>Click Verify in Search Console.</li>
                        <li>Submit sitemap: <?= Security::escape(SeoRobots::sitemapAbsoluteUrl()) ?></li>
                    </ol>
                </details>
                <div>
                    <label class="at-label">GA4 Measurement ID</label>
                    <input class="at-input at-focusable font-mono" name="ga4_measurement_id" value="<?= Security::escape((string) $ga4) ?>" placeholder="G-XXXXXXXXXX">
                    <p class="text-xs at-muted mt-1">Status: <?= $ga4Ok ? 'Will load on storefront (unless GTM is enabled)' : 'Not configured yet' ?></p>
                </div>
                <?php if (!SeoAdminCenter::isSimple()): ?>
                <div>
                    <label class="at-label">Google Tag Manager container</label>
                    <input class="at-input at-focusable font-mono" name="gtm_container_id" value="<?= Security::escape((string) $gtm) ?>" placeholder="GTM-XXXXXXX">
                    <label class="flex items-center gap-2 text-sm mt-2"><input type="checkbox" name="gtm_enabled" value="1"<?= $gtmOn ? ' checked' : '' ?>> Enable GTM (disables direct GA4 script to avoid duplicates)</label>
                </div>
                <?php else: ?>
                <input type="hidden" name="gtm_container_id" value="<?= Security::escape((string) $gtm) ?>">
                <input type="hidden" name="gtm_enabled" value="<?= $gtmOn ? '1' : '0' ?>">
                <?php endif; ?>
                <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save Google settings</button>
            </form>
    </div>
</div>
