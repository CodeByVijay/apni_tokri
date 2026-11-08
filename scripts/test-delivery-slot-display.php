<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

t(
    DeliverySlotDisplay::formatLabelWithWindow('Evening Slot 1', '18:30:00', '21:30:00') === 'Evening Slot 1 (6:30 PM – 9:30 PM)',
    'Evening Slot 1 window 6:30 PM – 9:30 PM'
);
t(
    DeliverySlotDisplay::formatLabelWithWindow('Evening Slot 2', '19:30:00', '21:30:00') === 'Evening Slot 2 (7:30 PM – 9:30 PM)',
    'Evening Slot 2 window'
);
t(
    DeliverySlotDisplay::formatLabelWithWindow('Evening Slot 3', '20:30:00', '22:30:00') === 'Evening Slot 3 (8:30 PM – 10:30 PM)',
    'Evening Slot 3 window'
);
t(
    DeliverySlotDisplay::formatWindowRange('06:30:00', '09:30:00') === '6:30 AM – 9:30 AM',
    'Morning window formatting'
);

$snapOrder = [
    'delivery_slot' => 'evening-slot-1',
    'delivery_slot_id' => 99,
    'delivery_snapshot_json' => json_encode([
        'slot_label' => 'Evening Slot 1',
        'slot_start_time' => '18:30:00',
        'slot_end_time' => '21:30:00',
    ], JSON_UNESCAPED_UNICODE),
];
$resolved = DeliverySlotDisplay::resolveForOrder($snapOrder);
t(
    ($resolved['display_line'] ?? '') === 'Evening Slot 1 (6:30 PM – 9:30 PM)',
    'Historical snapshot times used (not live slot row)'
);

$legacySnap = [
    'delivery_slot' => 'morning',
    'delivery_snapshot_json' => json_encode(['slot_label' => 'Daily Morning'], JSON_UNESCAPED_UNICODE),
];
$legacy = DeliverySlotDisplay::resolveForOrder($legacySnap);
t(($legacy['display_line'] ?? '') === 'Daily Morning', 'Legacy snapshot without times shows label only');

$card = DeliverySlotDisplay::checkoutCard([
    'id' => 1,
    'slug' => 'eve',
    'label' => 'Evening Slot 1',
    'start_time' => '18:30:00',
    'end_time' => '21:30:00',
], '2026-11-09', 3);
t(($card['time_range'] ?? '') === '6:30 PM – 9:30 PM', 'Checkout card time_range');
t(($card['window'] ?? '') === '6:30 PM – 9:30 PM', 'Checkout card window is delivery window not window_label');

$slotsPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/delivery/slot_display.php');
t(strpos($slotsPhp, 'DeliverySlotDisplay') !== false, 'Shared display helper exists');
$bootstrap = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/bootstrap.php');
t(strpos($bootstrap, 'slot_display.php') !== false, 'Bootstrap loads slot_display');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
