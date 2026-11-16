<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$report = $useDb ? DeliveryZoneReadiness::report() : null;
$preview = $useDb ? DeliveryZoneReadiness::previewComparison(200) : null;

admin_layout_start(['pageTitle' => 'Delivery zone readiness', 'activeAdminNav' => 'settings']);
$pageHeading = 'Delivery Zone Readiness';
$pageDescription = 'Read-only migration check before switching from pincode-based to distance-based delivery. Nothing on this page changes settings, zones, addresses or orders.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'readiness';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';

if (!$useDb || $report === null):
?>
<div class="at-card max-w-3xl"><div class="at-card__body text-sm at-muted">Readiness report requires the database and an admin session.</div></div>
<?php
else:
    $mode = (string) $report['mode'];
    $store = $report['store'];
    $integrity = $report['integrity'];
    $coverage = $report['coverage'];
    $pending = $report['pending'];
    $hasCoords = (is_numeric($store['latitude'] ?? null) && is_numeric($store['longitude'] ?? null)
        && !((float) $store['latitude'] === 0.0 && (float) $store['longitude'] === 0.0));
    $blocking = $integrity['errors'] !== [] || !$hasCoords;
    $sum = $preview !== null ? $preview['summary'] : [];
?>
<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Current configuration</h3>
        <p>Resolution mode: <strong><?= Security::escape(DeliveryAdminUi::modeLabel($mode)) ?></strong> <code class="text-xs">(<?= Security::escape($mode) ?>)</code></p>
        <p>Store / dispatch centre:
            <?php if ($hasCoords): ?>
                <strong><?= Security::escape((string) $store['latitude']) ?>, <?= Security::escape((string) $store['longitude']) ?></strong>
            <?php else: ?>
                <span class="at-cred-badge at-cred-badge--empty">Not configured</span>
            <?php endif; ?>
            &middot; Max radius: <?= is_numeric($store['max_radius_km'] ?? null) ? Security::escape(DeliveryAdminUi::km($store['max_radius_km'])) : '—' ?>
        </p>
        <p class="at-muted text-xs">Mode changes are made on the <a href="<?= Security::escape(url_base() . '/admin/settings/delivery/policies.php') ?>">Delivery rules</a> page. This page is read-only.</p>
    </div>
</div>

<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Distance-band integrity</h3>
        <?php if ($integrity['errors'] === [] && $integrity['gaps'] === [] && $integrity['warnings'] === []): ?>
            <p><span class="at-cred-badge at-cred-badge--saved">No issues found</span></p>
        <?php else: ?>
            <?php foreach ($integrity['errors'] as $e): ?><p class="text-red-700 text-xs">✕ <?= Security::escape($e) ?></p><?php endforeach; ?>
            <?php foreach ($integrity['gaps'] as $g): ?><p class="text-amber-700 text-xs">⚠ Gap: <?= Security::escape($g) ?></p><?php endforeach; ?>
            <?php foreach ($integrity['warnings'] as $w): ?><p class="at-muted text-xs">• <?= Security::escape($w) ?></p><?php endforeach; ?>
        <?php endif; ?>
        <div class="overflow-x-auto">
            <table class="at-table text-xs w-full">
                <thead><tr><th class="text-left">Zone</th><th class="text-left">Kind</th><th>Band (km)</th><th>Fee</th><th>Free above</th><th>Sort</th><th>Active</th></tr></thead>
                <tbody>
                <?php foreach ($report['zones'] as $z): ?>
                    <tr>
                        <td><?= Security::escape((string) ($z['name'] ?? '')) ?></td>
                        <td><?= Security::escape(DeliveryAdminUi::kindLabel((string) ($z['zone_kind'] ?? 'pincode'))) ?></td>
                        <td class="text-center"><?php
                            $min = $z['min_distance_km'] ?? null;
                            $max = $z['max_distance_km'] ?? null;
                            echo ($min !== null && $max !== null) ? Security::escape('[' . $min . ', ' . $max . ')') : '—';
                        ?></td>
                        <td class="text-center"><?= Security::escape(DeliveryAdminUi::inr($z['flat_fee'] ?? 0)) ?></td>
                        <td class="text-center"><?= Security::escape(DeliveryAdminUi::inr($z['free_above'] ?? 0)) ?></td>
                        <td class="text-center"><?= (int) ($z['sort_order'] ?? 0) ?></td>
                        <td class="text-center"><?= !empty($z['is_active']) ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Address coordinate coverage</h3>
        <div class="grid sm:grid-cols-3 gap-3 text-center">
            <div><div class="text-lg font-bold"><?= (int) $coverage['total_active'] ?></div><div class="at-muted text-xs">Active addresses</div></div>
            <div><div class="text-lg font-bold"><?= (int) $coverage['with_valid_coords'] ?></div><div class="at-muted text-xs">With valid coordinates</div></div>
            <div><div class="text-lg font-bold"><?= (int) $coverage['confirmed_coords'] ?></div><div class="at-muted text-xs">Customer-confirmed</div></div>
            <div><div class="text-lg font-bold"><?= (int) $coverage['missing_coords'] ?></div><div class="at-muted text-xs">Missing coordinates</div></div>
            <div><div class="text-lg font-bold"><?= (int) $coverage['partial_coords'] + (int) $coverage['invalid_coords'] ?></div><div class="at-muted text-xs">Partial / invalid</div></div>
            <div><div class="text-lg font-bold"><?= max(0, (int) $coverage['with_valid_coords'] - (int) $coverage['confirmed_coords']) ?></div><div class="at-muted text-xs">Coords, unconfirmed</div></div>
        </div>
        <p class="at-muted text-xs">Distance-based delivery needs confirmed coordinates per address. Addresses without them will be asked to confirm their map pin at checkout — nothing is auto-filled or guessed from pincodes.</p>
    </div>
</div>

<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Pending records warning</h3>
        <p>Unpaid orders: <strong><?= (int) $pending['orders_unpaid'] ?></strong> &middot; In-fulfilment orders: <strong><?= (int) $pending['orders_in_fulfilment'] ?></strong> &middot; Active/paused subscriptions: <strong><?= (int) $pending['subscriptions_active'] ?></strong></p>
        <?php if ((string) $pending['note'] !== ''): ?><p class="at-muted text-xs"><?= Security::escape((string) $pending['note']) ?></p><?php endif; ?>
        <p class="at-muted text-xs">Existing order snapshots are never rewritten by a mode change. Pending payments will re-validate delivery eligibility under whichever mode is active at payment time.</p>
    </div>
</div>

<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Preview — legacy vs distance-based (saved addresses)</h3>
        <?php if ($preview === null || ($preview['summary']['compared'] ?? 0) < 0): ?>
            <p class="at-muted text-xs">Preview unavailable.</p>
        <?php else: ?>
            <div class="flex flex-wrap gap-3 text-xs">
                <span class="at-cred-badge at-cred-badge--saved"><?= (int) ($sum['same'] ?? 0) ?> unchanged</span>
                <span class="at-cred-badge at-cred-badge--empty"><?= (int) ($sum['still_serviceable_different_zone'] ?? 0) ?> different zone</span>
                <span class="at-cred-badge at-cred-badge--empty"><?= (int) ($sum['becomes_unserviceable'] ?? 0) ?> would lose service</span>
                <span class="at-cred-badge at-cred-badge--empty"><?= (int) ($sum['newly_serviceable'] ?? 0) ?> newly serviceable</span>
                <span class="at-cred-badge at-cred-badge--empty"><?= (int) ($sum['needs_location_confirm'] ?? 0) ?> need location confirm</span>
            </div>
            <p class="at-muted text-xs">Compared <?= (int) ($sum['compared'] ?? 0) ?> most recent active addresses. Boundary rule: distance is band-min inclusive, band-max exclusive.</p>
            <div class="overflow-x-auto">
                <table class="at-table text-xs w-full">
                    <thead><tr><th class="text-left">Address</th><th>Coords</th><th class="text-left">Today</th><th class="text-left">Distance mode</th><th>Km</th><th class="text-left">Impact</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['rows'] as $r): ?>
                        <tr>
                            <td>#<?= (int) $r['address_id'] ?> <?= Security::escape((string) $r['city']) ?> <?= Security::escape((string) $r['pincode']) ?><?= $r['confirmed'] ? '' : ' <span class="at-muted">(unconfirmed)</span>' ?></td>
                            <td class="text-center"><?= $r['has_coords'] ? 'Yes' : 'No' ?></td>
                            <td><?= $r['legacy_serviceable'] ? '✓ ' . Security::escape((string) ($r['legacy_zone'] ?? '')) : '✗' ?></td>
                            <td><?= $r['hybrid_serviceable'] ? '✓ ' . Security::escape((string) ($r['hybrid_zone'] ?? '')) : ($r['requires_map_pin'] ? 'needs pin' : '✗') ?></td>
                            <td class="text-center"><?= $r['distance_km'] !== null ? Security::escape((string) $r['distance_km']) : '—' ?></td>
                            <td><span class="at-cred-badge <?= $r['class'] === 'same' ? 'at-cred-badge--saved' : 'at-cred-badge--empty' ?>"><?= Security::escape(str_replace('_', ' ', (string) $r['class'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($preview['rows'] ?? []) === []): ?><tr><td colspan="6" class="at-muted">No saved addresses to compare.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="at-card max-w-3xl mb-4">
    <div class="at-card__body text-sm space-y-1">
        <h3 class="font-bold">Activation checklist</h3>
        <ol class="list-decimal pl-5 text-xs space-y-1">
            <li><?= $hasCoords ? '✓' : '✕' ?> Store coordinates configured (Delivery → Dispatch &amp; fees).</li>
            <li><?= $integrity['errors'] === [] ? '✓' : '✕' ?> No band overlaps or malformed bands.</li>
            <li><?= $integrity['gaps'] === [] ? '✓' : '⚠' ?> No coverage gaps inside the store radius.</li>
            <li><?= (int) $coverage['missing_coords'] === 0 ? '✓' : '⚠' ?> <?= (int) $coverage['missing_coords'] ?> address(es) still need a confirmed location.</li>
            <li><?= (int) ($sum['becomes_unserviceable'] ?? 0) === 0 ? '✓' : '⚠' ?> <?= (int) ($sum['becomes_unserviceable'] ?? 0) ?> address(es) would lose service under distance mode.</li>
            <li>Review unpaid orders and subscriptions above.</li>
            <li>When ready, switch the mode on <a href="<?= Security::escape(url_base() . '/admin/settings/delivery/policies.php') ?>">Delivery rules</a>. Activation stays a manual owner decision.</li>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php admin_layout_end(); ?>
