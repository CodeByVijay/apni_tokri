<?php

declare(strict_types=1);

$tokenState = (string) ($metaStatus['token_state'] ?? MetaAdsSettings::TOKEN_STATE_NOT_SET);
$rows = [
    ['Environment', ucfirst((string) ($metaStatus['environment'] ?? 'unknown'))],
    ['Meta Pixel — flag', !empty($metaStatus['pixel_enabled']) ? 'Enabled' : 'Disabled'],
    ['Meta Pixel ID', !empty($metaStatus['pixel_configured'])
        ? (string) ($metaForm['pixel_id'] ?? '') : 'Not configured'],
    ['Conversions API — flag', !empty($metaStatus['capi_enabled']) ? 'Enabled' : 'Disabled'],
    ['Effective dataset ID', $metaStatus['effective_dataset_id'] !== null
        ? (string) $metaStatus['effective_dataset_id'] : 'Not configured'],
    ['Access token', match ($tokenState) {
        MetaAdsSettings::TOKEN_STATE_CONFIGURED => 'Saved (encrypted)',
        MetaAdsSettings::TOKEN_STATE_UNREADABLE => 'Stored but unreadable — check encryption key',
        default => 'Not configured',
    }],
    ['Test event code', $metaStatus['test_event_code'] !== null
        ? (string) $metaStatus['test_event_code'] : 'Not set'],
    ['Consent manager', 'Implemented — marketing denied by default'],
    ['Browser integration', MetaPixel::configured()
        ? (MetaPixel::browserAllowedNow()
            ? 'Active — pixel + consent granted in this browser'
            : 'Configured — waiting for visitor marketing consent')
        : 'Not active — enable the Pixel and set a valid Pixel ID'],
    ['CSP readiness', 'connect.facebook.net + www.facebook.com added (script/connect/img)'],
    ['CAPI implementation', 'Pending — Phase 3 (no server events sent)'],
];
?>
<div class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Connection &amp; configuration status</h3>
            <div class="at-table-wrap">
                <table class="at-table">
                    <tbody>
                        <?php foreach ($rows as [$label, $value]): ?>
                            <tr>
                                <th class="text-left w-1/2"><?= Security::escape($label) ?></th>
                                <td><?= Security::escape((string) $value) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="at-alert at-alert--info mt-3" role="status">
                Phase 2: the pixel loads only when enabled, a valid ID is set, and the visitor
                has granted marketing consent. No Conversions API requests are sent yet.
            </div>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Phase 2/3 readiness</h3>
            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                <li>Phase 2 CSP is in place: <code>connect.facebook.net</code> (script) and
                    <code>www.facebook.com</code> (connect/img beacons).</li>
                <li>Purchase events carry a deterministic, non-PII event ID — Phase 3 CAPI will reuse it for browser/server deduplication.</li>
                <li>Conversions API (Phase 3) calls <code>graph.facebook.com</code> server-side; no browser CSP change needed for it.</li>
                <li>Server-side Purchase delivery still hooks the payment completion flow in Phase 3.</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('pixel')) ?>">Pixel settings</a>
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('capi')) ?>">Conversions API</a>
            </div>
        </div>
    </div>
</div>
