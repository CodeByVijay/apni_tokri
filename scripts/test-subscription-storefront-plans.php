<?php

declare(strict_types=1);

/**
 * Storefront vs admin subscription plan consistency (no demo fallback on homepage).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
t(strpos($indexSrc, "'Starter'") === false, 'Homepage source has no hardcoded Starter plan');
t(strpos($indexSrc, "'Family'") === false, 'Homepage source has no hardcoded Family plan');
t(strpos($indexSrc, 'Connect the database to show live plans') === false, 'Homepage removed misleading DB connect copy');

$activeDbCount = (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1')->fetchColumn();
$storefront = SubscriptionCheckoutService::activeStorefrontPlanCards();
$activeRows = SubscriptionPlanRepository::activeForStorefront();
t(count($storefront) === count($activeRows), 'Storefront card count matches activeForStorefront row count');
if ($activeDbCount === 0) {
    t($storefront === [], 'Zero active plans in DB yields zero storefront cards');
} else {
    t($storefront !== [], 'Active plans in DB appear on storefront');
}

$adminList = SubscriptionPlanRepository::listForAdminPaginated(1, 500, '');
$adminActiveIds = [];
foreach ($adminList['rows'] as $p) {
    if (!empty($p['is_active'])) {
        $adminActiveIds[(int) $p['id']] = (float) $p['price'];
    }
}
$storefrontIds = [];
foreach ($storefront as $card) {
    $id = (int) $card['id'];
    $storefrontIds[$id] = (float) $card['price'];
}
t($storefrontIds === $adminActiveIds, 'Storefront active plan IDs and prices match admin active plans');

foreach ($storefront as $card) {
    t($card['name'] !== '' && $card['id'] > 0, 'Each storefront card has id and name');
    $row = SubscriptionPlanRepository::find((int) $card['id']);
    if ($row !== null) {
        t((float) $row['price'] === (float) $card['price'], 'Storefront price matches DB for plan id ' . $card['id']);
    }
}

$sample = SubscriptionPlanRepository::findByTemplateKey('veg_7');
if ($sample !== null) {
    $savedActive = (int) $sample['is_active'];
    $pdo->prepare('UPDATE subscription_plans SET is_active = 0 WHERE id = ?')->execute([(int) $sample['id']]);
    try {
        $cardsAfterOff = SubscriptionCheckoutService::activeStorefrontPlanCards();
        $stillListed = false;
        foreach ($cardsAfterOff as $c) {
            if ((int) $c['id'] === (int) $sample['id']) {
                $stillListed = true;
                break;
            }
        }
        t(!$stillListed, 'Inactive plan omitted from storefront listing');
        t(SubscriptionCheckoutService::publicPlanCard(array_merge($sample, ['is_active' => 0])) === null, 'publicPlanCard rejects inactive');
    } finally {
        $pdo->prepare('UPDATE subscription_plans SET is_active = ? WHERE id = ?')->execute([$savedActive, (int) $sample['id']]);
    }
} else {
    t(true, 'Inactive plan test skipped (no veg_7 template row)');
}

$tempName = 'ZT Storefront Empty ' . bin2hex(random_bytes(3));
$pdo->prepare(
    'INSERT INTO subscription_plans (name, calendar_duration_days, delivery_count, duration_model, frequency_type,
     frequency_config, basket_rules_json, price, payment_rules_json, skip_pause_policy_json, slot_policy_json,
     selection_cutoff_hours, is_active, sort_order)
     VALUES (?, 7, 7, \'hybrid\', \'daily\', \'{}\', \'{}\', 123.45, \'{}\', \'{}\', \'{}\', 18, 0, 9999)'
)->execute([$tempName]);
$inactiveId = (int) $pdo->lastInsertId();
try {
    $cards = SubscriptionCheckoutService::activeStorefrontPlanCards();
    $leaked = false;
    foreach ($cards as $c) {
        if ((int) $c['id'] === $inactiveId) {
            $leaked = true;
        }
    }
    t(!$leaked, 'New inactive plan (is_active=0) never appears on storefront');
} finally {
    $pdo->prepare('DELETE FROM subscription_plans WHERE id = ?')->execute([$inactiveId]);
}

t(
    count($storefront) === 0 || ProductRepository::productCount() === 0 || CommerceGateway::useDatabase(),
    'Storefront listing does not depend on CommerceGateway product gate'
);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
