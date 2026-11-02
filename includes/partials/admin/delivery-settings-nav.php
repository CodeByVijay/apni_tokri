<?php

declare(strict_types=1);

/** @var string $deliverySettingsBase */
$deliverySettingsBase = url_base() . '/admin/settings/delivery';
$deliveryNavActive = $deliveryNavActive ?? 'dispatch';
?>
<nav class="flex flex-wrap gap-2 mb-4 text-sm" aria-label="Delivery settings sections">
    <a href="<?= Security::escape($deliverySettingsBase) ?>.php" class="at-focusable px-3 py-1 rounded-full border<?= $deliveryNavActive === 'dispatch' ? ' bg-emerald-50 border-emerald-600 font-semibold' : '' ?>">Dispatch & fees</a>
    <a href="<?= Security::escape($deliverySettingsBase) ?>/zones.php" class="at-focusable px-3 py-1 rounded-full border<?= $deliveryNavActive === 'zones' ? ' bg-emerald-50 border-emerald-600 font-semibold' : '' ?>">Zones</a>
    <a href="<?= Security::escape($deliverySettingsBase) ?>/slots.php" class="at-focusable px-3 py-1 rounded-full border<?= $deliveryNavActive === 'slots' ? ' bg-emerald-50 border-emerald-600 font-semibold' : '' ?>">Slots</a>
    <a href="<?= Security::escape($deliverySettingsBase) ?>/policies.php" class="at-focusable px-3 py-1 rounded-full border<?= $deliveryNavActive === 'policies' ? ' bg-emerald-50 border-emerald-600 font-semibold' : '' ?>">Policies</a>
    <span class="px-3 py-1 rounded-full border at-muted">Partners</span>
</nav>
