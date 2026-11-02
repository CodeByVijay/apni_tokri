<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.0.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

Install::ensureConnection();

foreach (['veg_7', 'veg_15', 'veg_30'] as $key) {
    $p = SubscriptionPlanRepository::findByTemplateKey($key);
    ok($p !== null, 'Default template ' . $key);
}

$badPlan = [
    'calendar_duration_days' => 5,
    'delivery_count' => 10,
    'frequency_type' => 'alternate',
    'frequency_config' => ['interval_days' => 2],
];
$bad = SubscriptionScheduleGenerator::validatePlanSchedule($badPlan);
ok(!$bad['ok'], 'Invalid schedule rejected (5-day window, 10 alternate deliveries)');

$goodPlan = [
    'calendar_duration_days' => 14,
    'delivery_count' => 7,
    'frequency_type' => 'daily',
    'frequency_config' => ['interval_days' => 1],
];
$gen = SubscriptionScheduleGenerator::generateDeliveryDates($goodPlan, '2026-06-01');
ok($gen['ok'] && count($gen['dates'] ?? []) === 7, '7 deliveries in 14-day daily window');

$active = SubscriptionPlanRepository::activeForStorefront();
ok(count($active) >= 3, 'Active seeded plans available for storefront');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 11 Phase A.\n";
exit(0);
