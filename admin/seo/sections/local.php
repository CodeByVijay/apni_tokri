<?php

declare(strict_types=1);

$simple = SeoAdminCenter::isSimple();
$serviceArea = AppSettings::get(SeoAdminCenter::KEY_LOCAL_SERVICE_AREA, '') ?? '';
$hours = AppSettings::get(SeoAdminCenter::KEY_LOCAL_HOURS, '') ?? '';
$settingsBusiness = url_base() . '/admin/settings/business.php';
$preview = [
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => BusinessSettings::legalName(),
    'description' => trim(BrandingSettings::tagline() ?? '') !== '' ? BrandingSettings::tagline() : null,
    'url' => BusinessSettings::websiteUrl(),
    'telephone' => BusinessSettings::supportPhoneDisplay(),
    'email' => BusinessSettings::supportEmail(),
    'address' => array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => BusinessSettings::addressLine(),
        'addressLocality' => BusinessSettings::city(),
        'addressRegion' => BusinessSettings::state(),
        'postalCode' => BusinessSettings::pincode(),
        'addressCountry' => 'IN',
    ]),
];
$preview = array_filter($preview, static fn ($v) => $v !== null && $v !== '' && $v !== []);
if ($hours !== '') {
    $preview['openingHours'] = $hours;
}
if ($serviceArea !== '') {
    $preview['areaServed'] = $serviceArea;
}
$jsonPreview = json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="at-seo-saas__stack lg:grid lg:grid-cols-2 lg:gap-4">
    <div class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title"><?= $simple ? 'Your business on Google Maps & local search' : 'Local business data' ?></h3>
            <p class="text-sm at-muted">Core address and contact come from <a href="<?= Security::escape($settingsBusiness) ?>" class="underline at-focusable">Business profile</a>. We only output structured data you have entered — no invented reviews or hours.</p>
            <dl class="text-sm grid gap-2">
                <div><dt class="at-muted">Business</dt><dd><?= Security::escape(BusinessSettings::legalName()) ?></dd></div>
                <div><dt class="at-muted">Address</dt><dd><?= Security::escape(trim((BusinessSettings::addressLine() ?? '') . ', ' . (BusinessSettings::city() ?? '') . ', ' . (BusinessSettings::state() ?? ''))) ?></dd></div>
                <div><dt class="at-muted">Phone</dt><dd><?= Security::escape(BusinessSettings::supportPhoneDisplay() ?? '—') ?></dd></div>
                <div><dt class="at-muted">Website</dt><dd><?= Security::escape(BusinessSettings::websiteUrl()) ?></dd></div>
            </dl>
            <form method="post" class="space-y-3 mt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="section" value="local">
                <input type="hidden" name="seo_action" value="save_local">
                <div>
                    <label class="at-label">Service area (optional)</label>
                    <input class="at-input at-focusable" name="local_service_area" value="<?= Security::escape((string) $serviceArea) ?>" placeholder="e.g. Indore and nearby areas">
                </div>
                <div>
                    <label class="at-label">Opening hours (optional, plain text)</label>
                    <textarea class="at-input at-focusable" name="local_business_hours" rows="3" placeholder="e.g. Mon–Sat 8:00–20:00"><?= Security::escape((string) $hours) ?></textarea>
                </div>
                <button type="submit" class="at-btn at-btn--primary at-focusable"<?= $useDb ? '' : ' disabled' ?>>Save local SEO notes</button>
            </form>
            <p class="text-xs at-muted">To manage your Google Business Profile, use <a href="https://business.google.com/" class="underline" rel="noopener noreferrer">business.google.com</a> — this admin panel does not connect to GBP unless a future integration is added.</p>
    </div>
    <div class="at-seo-saas__panel">
            <h3 class="at-seo-saas__panel-title">Structured data preview (JSON-LD)</h3>
            <p class="text-xs at-muted">Rendered on the site when address data exists. Validate in Google’s Rich Results Test.</p>
            <pre class="text-xs bg-neutral-100 p-3 rounded overflow-auto max-h-96"><?= Security::escape(is_string($jsonPreview) ? $jsonPreview : '{}') ?></pre>
    </div>
</div>
