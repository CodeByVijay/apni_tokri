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
                Enabling these settings does not activate tracking. No Meta scripts load on the
                storefront and no server requests are sent in Phase 1.
            </div>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Phase 2/3 readiness</h3>
            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                <li>Pixel activation (Phase 2) will need CSP additions for
                    <code>connect.facebook.net</code> (script/img) and
                    <code>www.facebook.com</code> (img/connect) — not added yet.</li>
                <li>Conversions API (Phase 3) calls <code>graph.facebook.com</code> server-side; no browser CSP change needed for it.</li>
                <li>Purchase events will hook into the existing payment completion flow in Phase 3.</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('pixel')) ?>">Pixel settings</a>
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('capi')) ?>">Conversions API</a>
            </div>
        </div>
    </div>
</div>
