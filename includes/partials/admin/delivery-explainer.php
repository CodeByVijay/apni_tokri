<?php

declare(strict_types=1);

/**
 * "How delivery charges are worked out" panel, built from saved settings.
 *
 * @var string $explainMode
 * @var list<array<string, mixed>> $explainZones
 * @var array<string, mixed> $explainStore
 */
$explainLines = DeliveryAdminUi::explanationLines($explainMode, $explainZones, $explainStore);
?>
<div class="at-alert at-alert--info mb-4" role="note">
    <h3 class="font-bold mb-1">How delivery charges are worked out</h3>
    <p class="text-sm mb-2">Currently using: <strong><?= Security::escape(DeliveryAdminUi::modeLabel($explainMode)) ?></strong>. This summary updates automatically from your saved settings.</p>
    <ul class="list-disc pl-5 text-sm space-y-1">
        <?php foreach ($explainLines as $line): ?>
            <li><?= Security::escape($line) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
