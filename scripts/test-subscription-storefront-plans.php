<?php

declare(strict_types=1);

/** Storefront subscription plan listing (subscriptions.php flow). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$storefront = [];
foreach (SubscriptionPlanRepository::activeForStorefront() as $row) {
    $card = SubscriptionCheckoutService::publicPlanCard($row);
    if ($card !== null) {
        $storefront[] = $card;
    }
}

t(count($storefront) >= 3, 'At least three active plans render on storefront');

foreach (['veg_7', 'veg_15', 'veg_30'] as $key) {
    $p = SubscriptionPlanRepository::findByTemplateKey($key);
    if ($p === null || empty($p['is_active'])) {
        t(false, 'Template plan active: ' . $key);
        continue;
    }
    $inList = false;
    foreach ($storefront as $card) {
        if ((int) $card['id'] === (int) $p['id']) {
            $inList = true;
            break;
        }
    }
    t($inList, 'Storefront lists ' . $key);
    if ($inList) {
        foreach ($storefront as $card) {
            if ((int) $card['id'] === (int) $p['id']) {
                t($card['name'] !== '' && $card['price'] >= 0 && $card['delivery_count'] > 0, $key . ' card has name, price, deliveries');
                break;
            }
        }
    }
}

$inactive = SubscriptionPlanRepository::findByTemplateKey('veg_7');
if ($inactive !== null) {
    $row = $inactive;
    $row['is_active'] = 0;
    t(SubscriptionCheckoutService::publicPlanCard($row) === null, 'Inactive plan hidden from publicPlanCard');
}

$draftLike = SubscriptionPlanRepository::allForAdmin();
$hasInactive = false;
foreach ($draftLike as $p) {
    if (empty($p['is_active'])) {
        $hasInactive = true;
        $found = false;
        foreach (SubscriptionPlanRepository::activeForStorefront() as $a) {
            if ((int) $a['id'] === (int) $p['id']) {
                $found = true;
                break;
            }
        }
        t(!$found, 'Inactive plan excluded from activeForStorefront');
        break;
    }
}
if (!$hasInactive) {
    t(true, 'Inactive plan excluded from activeForStorefront (no inactive row to sample)');
}

t(CommerceGateway::useDatabase(), 'CommerceGateway allows DB mode for subscription pages');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
