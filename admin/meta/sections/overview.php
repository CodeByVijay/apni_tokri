<?php

declare(strict_types=1);

$tokenState = (string) ($metaStatus['token_state'] ?? MetaAdsSettings::TOKEN_STATE_NOT_SET);
$tokenBadge = match ($tokenState) {
    MetaAdsSettings::TOKEN_STATE_CONFIGURED => admin_status_badge('active', 'Saved'),
    MetaAdsSettings::TOKEN_STATE_UNREADABLE => admin_status_badge('error', 'Unreadable'),
    default => admin_status_badge('inactive', 'Not configured'),
};
$pixelBadge = !empty($metaStatus['pixel_enabled'])
    ? (!empty($metaStatus['pixel_configured'])
        ? admin_status_badge('active', 'Enabled')
        : admin_status_badge('error', 'Missing Pixel ID'))
    : (!empty($metaStatus['pixel_configured'])
        ? admin_status_badge('processing', 'Configured — disabled')
        : admin_status_badge('inactive', 'Not configured'));
$capiBadge = !empty($metaStatus['capi_enabled'])
    ? (!empty($metaStatus['capi_configured'])
        ? admin_status_badge('active', 'Enabled')
        : admin_status_badge('error', 'Incomplete configuration'))
    : (!empty($metaStatus['capi_configured'])
        ? admin_status_badge('processing', 'Configured — disabled')
        : admin_status_badge('inactive', 'Not configured'));
$envLabel = ucfirst((string) ($metaStatus['environment'] ?? 'unknown'));
$envBadge = AppEnvironment::isProduction()
    ? admin_status_badge('active', $envLabel)
    : admin_status_badge('processing', $envLabel);
?>
<?php
$browserTrackingBadge = MetaPixel::configured()
    ? admin_status_badge('active', 'Active (consent-gated)')
    : admin_status_badge('inactive', 'Not active');
$consentBadge = MetaConsent::marketingAllowed()
    ? admin_status_badge('active', 'Granted (this browser)')
    : admin_status_badge('processing', 'Awaiting visitor consent');
?>
<div class="at-alert at-alert--info mb-4" role="status">
    Phase 2 is live: the Meta Pixel loads on the storefront only for visitors who grant
    marketing consent. The Conversions API (Phase 3) still sends no events.
</div>
<div class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Integration status</h3>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span>Environment</span><?= $envBadge ?>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span>Meta Pixel</span><?= $pixelBadge ?>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span>Browser tracking</span><?= $browserTrackingBadge ?>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span>Marketing consent</span><?= $consentBadge ?>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span>Conversions API</span><?= $capiBadge ?>
            </div>
            <div class="flex justify-between items-center py-2">
                <span>Access token</span><?= $tokenBadge ?>
            </div>
            <div class="mt-3 flex gap-2">
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('pixel')) ?>">Pixel settings</a>
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('capi')) ?>">Conversions API</a>
            </div>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">How tracking works</h3>
            <p class="text-sm text-slate-600 mb-3">
                Meta Ads connects Veggie Tokri to Meta Business so ad campaigns on Facebook and
                Instagram can be measured: product views, add-to-cart, checkout and purchases.
            </p>
            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                <li><strong>Phase 1:</strong> Pixel ID, dataset and credentials stored safely.</li>
                <li><strong>Phase 2 (live):</strong> consent banner + Meta Pixel with standard events — PageView, ViewContent, Search, AddToCart, InitiateCheckout, Purchase.</li>
                <li><strong>Phase 3 (pending):</strong> server-side Conversions API events deduplicated against the pixel.</li>
            </ul>
            <p class="text-sm text-slate-500 mt-3">
                Visitors control marketing cookies via the storefront consent banner and
                the footer “Cookie Preferences” link. The access token is stored encrypted
                and is never shown on this screen.
            </p>
        </div>
    </div>
</div>
