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
    ['CAPI implementation', MetaAdsSettings::capiReady()
        ? 'Active — server-side Purchase outbox enabled'
        : 'Inactive — enable CAPI and configure a dataset ID + access token'],
];

$capiStats = MetaCapi::stats();
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
                <li>Purchase events carry a deterministic, non-PII event ID — browser Pixel and CAPI share it for deduplication.</li>
                <li>Conversions API calls <code>graph.facebook.com</code> server-side only; never from the browser.</li>
                <li>Server Purchase events require checkout-time marketing consent and never block payment completion.</li>
            </ul>
            <div class="mt-3 flex gap-2">
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('pixel')) ?>">Pixel settings</a>
                <a class="at-btn at-btn--secondary" href="<?= Security::escape(MetaAdsSettings::sectionUrl('capi')) ?>">Conversions API</a>
            </div>
        </div>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-2 max-w-5xl mt-4">
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">CAPI event outbox</h3>
            <?php if (empty($capiStats['table'])): ?>
                <div class="at-alert at-alert--warning" role="status">
                    The <code>meta_capi_events</code> table is not installed. Run the 1.45.0 migration.
                </div>
            <?php else: ?>
                <div class="at-table-wrap">
                    <table class="at-table">
                        <tbody>
                            <tr><th class="text-left w-1/2">Pending</th><td><?= (int) $capiStats['pending'] ?></td></tr>
                            <tr><th class="text-left">Processing</th><td><?= (int) $capiStats['processing'] ?></td></tr>
                            <tr><th class="text-left">Sent</th><td><?= (int) $capiStats['sent'] ?></td></tr>
                            <tr><th class="text-left">Failed</th><td><?= (int) $capiStats['failed'] ?></td></tr>
                            <tr><th class="text-left">Suppressed</th><td><?= (int) $capiStats['suppressed'] ?></td></tr>
                            <tr><th class="text-left">Last successful send</th>
                                <td><?= Security::escape((string) ($capiStats['last_sent_at'] ?? '—')) ?></td></tr>
                            <tr><th class="text-left">Last error</th>
                                <td><?= Security::escape((string) ($capiStats['last_error'] ?? '—')) ?></td></tr>
                            <tr><th class="text-left">Enqueue failures</th>
                                <td><?= (int) ($capiStats['enqueue_failures'] ?? 0) ?><?php if (!empty($capiStats['last_enqueue_failure'])): ?>
                                    — last: <?= Security::escape((string) $capiStats['last_enqueue_failure']) ?><?php endif; ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="<?= Security::escape(MetaAdsSettings::sectionUrl('status')) ?>" class="mt-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="section" value="status">
                    <input type="hidden" name="meta_action" value="retry_capi">
                    <button type="submit" class="at-btn at-btn--secondary">Retry pending/failed events</button>
                </form>
                <div class="at-alert at-alert--info mt-3" role="status">
                    Events send only when CAPI is enabled with a valid dataset ID and access token.
                    Consent-withdrawn events are suppressed and never retried.
                    Payloads are scrubbed on send/suppress and after bounded retention —
                    events older than Meta's 7-day window are terminalized, and failed
                    payloads are dropped after 30 days.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
