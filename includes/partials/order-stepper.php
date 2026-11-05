<?php

declare(strict_types=1);

/**
 * Responsive horizontal order tracker (data comes from CustomerOrderTimeline; no progress is hardcoded).
 *
 * @var list<array{status?: string, at?: string, done?: bool}> $timeline
 * @var string $orderStatus Public/raw order status (used only to colour terminal failure states)
 */
$steps = [];
foreach ($timeline as $step) {
    $steps[] = [
        'label' => trim((string) ($step['status'] ?? '')),
        'at' => trim((string) ($step['at'] ?? '')),
        'done' => !empty($step['done']),
    ];
}
$total = count($steps);
if ($total === 0) {
    return;
}

$statusKey = strtolower(str_replace(' ', '_', trim((string) ($orderStatus ?? ''))));
$terminalBad = in_array($statusKey, ['cancelled', 'payment_failed', 'refunded', 'partially_refunded', 'delivery_exception'], true);
$badLabel = static function (string $label): bool {
    return (bool) preg_match('/cancel|fail|could not|refund|exception|declin/i', $label);
};

$doneCount = 0;
$currentIdx = null;
foreach ($steps as $i => $s) {
    if ($s['done']) {
        $doneCount++;
    } elseif ($currentIdx === null) {
        $currentIdx = $i;
    }
}
$allDone = $currentIdx === null;
if ($allDone) {
    $currentIdx = $total - 1;
}

foreach ($steps as $i => &$s) {
    $isBad = $s['done'] && $badLabel($s['label']);
    if ($terminalBad && $allDone && $i === $total - 1 && $s['done']) {
        $isBad = true;
    }
    if ($isBad) {
        $s['state'] = 'is-bad';
    } elseif ($i === $currentIdx && !$allDone) {
        $s['state'] = 'is-current';
    } elseif ($s['done']) {
        $s['state'] = 'is-done';
    } else {
        $s['state'] = 'is-upcoming';
    }
    if ($i === $currentIdx && $allDone && $s['state'] === 'is-done') {
        $s['state'] .= ' is-final';
    }
}
unset($s);

$currentStep = $steps[$currentIdx];
$progressPct = $total > 1 ? (int) round(($doneCount / $total) * 100) : ($doneCount > 0 ? 100 : 0);
$countLabel = $allDone ? ($total . ' of ' . $total) : (min($doneCount + 1, $total) . ' of ' . $total);
$summaryBad = $currentStep['state'] === 'is-bad';
?>
<div class="at-stepper" data-at-stepper>
    <div class="at-stepper__summary" role="status">
        <div class="at-stepper__summary-top">
            <span class="at-stepper__summary-label<?= $summaryBad ? ' is-bad' : '' ?>"><?= Security::escape($currentStep['label']) ?></span>
            <span class="at-stepper__summary-count">Step <?= Security::escape($countLabel) ?></span>
        </div>
        <div class="at-stepper__bar" aria-hidden="true">
            <span class="at-stepper__bar-fill<?= $summaryBad ? ' is-bad' : '' ?>" style="width: <?= (int) $progressPct ?>%"></span>
        </div>
        <?php if ($currentStep['at'] !== ''): ?>
            <p class="at-stepper__summary-time"><?= Security::escape(customer_format_datetime($currentStep['at'])) ?></p>
        <?php endif; ?>
    </div>

    <div class="at-stepper__scroll" tabindex="0" aria-label="Order tracking steps">
        <ol class="at-stepper__list">
            <?php foreach ($steps as $i => $s): ?>
                <li class="at-stepper__step <?= Security::escape($s['state']) ?>"<?= $i === $currentIdx ? ' aria-current="step"' : '' ?>>
                    <span class="at-stepper__dot" aria-hidden="true">
                        <?php if (strpos($s['state'], 'is-bad') !== false): ?>
                            <?= at_icon('info', 'w-3.5 h-3.5') ?>
                        <?php elseif (strpos($s['state'], 'is-done') !== false): ?>
                            <?= at_icon('check', 'w-3.5 h-3.5') ?>
                        <?php endif; ?>
                    </span>
                    <span class="at-stepper__label"><?= Security::escape($s['label']) ?></span>
                    <?php if ($s['at'] !== ''): ?>
                        <time class="at-stepper__time"><?= Security::escape(customer_format_datetime($s['at'])) ?></time>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
