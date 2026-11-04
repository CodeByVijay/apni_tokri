<?php

declare(strict_types=1);

/**
 * Integration: migrations 1.25.0 + 1.26.0 + 1.27.0 on disposable DB (production SQL import).
 * Does not use the configured application database.
 *
 * CLI: php7.3 scripts/test-subscription-migrations-integration.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dbCfg = App::config()['database'];
$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$migrations = [
    '1.25.0' => $root . '/database/migrations/1.25.0_subscription_menu_plans.sql',
    '1.26.0' => $root . '/database/migrations/1.26.0_product_types.sql',
    '1.27.0' => $root . '/database/migrations/1.27.0_subscription_basket_generation_notes.sql',
];
$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function mysqlRun(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    exec($cmd . ' 2>/dev/null ' . $extra, $out, $code);

    return (int) $code;
}

function schemaVersion(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute(['schema_version']);

    return (string) ($stmt->fetchColumn() ?: '');
}

/** @return array{count: int, fp: string} */
function legacyMenuSnapshot(PDO $pdo): array
{
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM subscription_menu_templates WHERE menu_plan_id IS NULL'
    )->fetchColumn();
    $fp = (string) $pdo->query(
        'SELECT MD5(GROUP_CONCAT(CONCAT(id, "|", name, "|", planner_type, "|", COALESCE(weekday, ""), "|", is_active) ORDER BY id SEPARATOR ";;"))
         FROM subscription_menu_templates WHERE menu_plan_id IS NULL'
    )->fetchColumn();

    return ['count' => $count, 'fp' => $fp ?: ''];
}

/** @return array{sub_fp: string, day_fp: string, basket_fp: string} */
function subscriptionBasketSnapshot(PDO $pdo, int $subId): array
{
    $subFp = (string) $pdo->query(
        'SELECT MD5(CONCAT(subscription_number, "|", status, "|", price_paid, "|", deliveries_remaining)) FROM customer_subscriptions WHERE id = ' . (int) $subId
    )->fetchColumn();
    $dayFp = (string) $pdo->query(
        'SELECT MD5(GROUP_CONCAT(CONCAT(id, "|", delivery_date, "|", basket_status, "|", COALESCE(generated_order_id, 0)) ORDER BY id SEPARATOR ";;"))
         FROM subscription_delivery_days WHERE customer_subscription_id = ' . (int) $subId
    )->fetchColumn();
    $basketFp = (string) $pdo->query(
        'SELECT MD5(GROUP_CONCAT(CONCAT(b.id, "|", b.status, "|", b.total_inr, "|", COALESCE(b.generation_skipped_json, ""), "|",
         (SELECT GROUP_CONCAT(CONCAT(i.variant_id, "x", i.qty) ORDER BY i.id) FROM subscription_delivery_basket_items i WHERE i.basket_id = b.id))
         ORDER BY b.id SEPARATOR ";;"))
         FROM subscription_delivery_baskets b
         INNER JOIN subscription_delivery_days d ON d.id = b.subscription_delivery_day_id
         WHERE d.customer_subscription_id = ' . (int) $subId
    )->fetchColumn();

    return ['sub_fp' => $subFp ?: '', 'day_fp' => $dayFp ?: '', 'basket_fp' => $basketFp ?: ''];
}

$freshDb = 'apni_tokri_migint_' . date('YmdHis') . '_' . bin2hex(random_bytes(2));
ok(mysqlRun($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable database');
ok(is_readable($sqlFile), 'Production SQL file readable');
ok(mysqlRun($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import production SQL');

$orig = App::config();
App::setConfig(array_replace_recursive($orig, ['database' => array_merge($dbCfg, ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $pdo = Database::pdo();

    // Production SQL has schema/seed but no catalogue rows — seed minimal published stock for subscription tests.
    $pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('Integration Veg', 'int-veg', 'published')");
    $catId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (category_id, name, slug, sku, status, product_type) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$catId, 'Integration Tomato', 'int-tomato', 'INT-TOM', 'published', 'fresh']);
    $prodId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_variants (product_id, name, sku, price, stock_qty, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$prodId, '500 g', 'INT-TOM-500', 40, 100]);
    $variantId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_variants (product_id, name, sku, price, stock_qty, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$prodId, '1 kg', 'INT-TOM-1K', 70, 50]);
    $oosId = (int) $pdo->lastInsertId();

    ok(
        schemaVersion($pdo) === '1.27.0-subscription-menu-readiness',
        'Fresh import schema_version is 1.27.0-subscription-menu-readiness'
    );
    ok(
        (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'subscription_menu_plans\'')->fetchColumn() === 1,
        'subscription_menu_plans table exists after import'
    );
    ok(
        (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'product_types\'')->fetchColumn() === 1,
        'product_types table exists after import'
    );
    ok(
        SubscriptionBasketService::generationSkippedColumnReady(),
        'generation_skipped_json column present after import'
    );

    ok($variantId > 0, 'Catalogue variant for fixtures');

    $legacySave = SubscriptionMenuRepository::save(null, [
        'name' => 'Integration legacy weekly',
        'planner_type' => 'weekly',
        'weekday' => '3',
        'is_active' => '1',
    ], [[
        'variant_id' => $variantId,
        'default_qty' => 1,
        'unit_label' => 'kg',
        'unit_price_inr' => 45,
        'include_in_rotation' => 1,
        'max_qty' => 2,
    ]]);
    ok($legacySave['ok'], 'Seed legacy menu template (menu_plan_id NULL)');

    // Customer subscription + generated basket fixture
    $plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
    ok($plan !== null, 'Subscription plan veg_7 seeded');
    $customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn());
    if ($customerId <= 0) {
        $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['9799999999', 'Integration Customer']);
        $customerId = (int) $pdo->lastInsertId();
    }
    $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
    $subNum = 'SUB-INT-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id,
         plan_snapshot_json, price_paid, status, deliveries_remaining, start_date, calendar_end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY)'
    )->execute([
        $subNum,
        $customerId,
        (int) $plan['id'],
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        (float) $plan['price'],
        'active',
        (int) $plan['delivery_count'],
    ]);
    $subId = (int) $pdo->lastInsertId();
    $deliveryDate = (new DateTimeImmutable('+4 days', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
    $weekday = (new DateTimeImmutable($deliveryDate, new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('N');
    $menuWeekly = SubscriptionMenuRepository::save(null, [
        'name' => 'Basket weekly ' . $subNum,
        'planner_type' => 'weekly',
        'weekday' => $weekday,
        'is_active' => '1',
    ], [[
        'variant_id' => $variantId,
        'default_qty' => 1,
        'unit_label' => 'kg',
        'unit_price_inr' => 50,
        'include_in_rotation' => 1,
        'max_qty' => 2,
    ]]);
    ok($menuWeekly['ok'], 'Weekly menu aligned to delivery weekday');

    $cutoff = SubscriptionCutoff::cutoffDateTime($deliveryDate, SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot))->format('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
         VALUES (?, ?, 1, ?, ?, ?)'
    )->execute([$subId, $deliveryDate, 'scheduled', $cutoff, 'none']);
    $dayId = (int) $pdo->lastInsertId();
    $subRow = CustomerSubscriptionRepository::findByNumber($subNum);
    $dayRow = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
    $gen = SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);
    ok(!empty($gen['ok']), 'Generate suggested basket before migration re-run');

    // OOS line for skipped JSON (second variant zero stock)
    $pdo->exec('UPDATE product_variants SET stock_qty = 0 WHERE id = ' . (int) $oosId);
    SubscriptionMenuRepository::save((int) $menuWeekly['id'], [
        'name' => 'Basket weekly ' . $subNum,
        'planner_type' => 'weekly',
        'weekday' => $weekday,
        'is_active' => '1',
    ], [
        ['variant_id' => $variantId, 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => 50, 'include_in_rotation' => 1, 'max_qty' => 2],
        ['variant_id' => $oosId, 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => 30, 'include_in_rotation' => 1, 'max_qty' => 2],
    ]);
    $pdo->exec('DELETE FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ' . (int) $dayId);
    $pdo->prepare('UPDATE subscription_delivery_days SET basket_status = ? WHERE id = ?')->execute(['none', $dayId]);
    $gen2 = SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);
    ok(!empty($gen2['skipped']), 'Skipped menu lines recorded on basket generation');
    $basketRow = SubscriptionBasketRepository::basketByDayId($dayId);
    ok(
        is_string($basketRow['generation_skipped_json'] ?? null) && ($basketRow['generation_skipped_json'] ?? '') !== '',
        'Skipped JSON persisted on subscription_delivery_baskets row'
    );
    $decoded = json_decode((string) $basketRow['generation_skipped_json'], true);
    ok(is_array($decoded) && $decoded !== [], 'Skipped JSON decodes to non-empty array');
    ok(
        is_array($gen2['skipped'] ?? null) && ($gen2['skipped'] ?? []) !== [],
        'Skipped lines returned to caller for customer display'
    );

    $legacyBefore = legacyMenuSnapshot($pdo);
    $snapBefore = subscriptionBasketSnapshot($pdo, $subId);

    $productTypeCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM product_types')->fetchColumn();

    // Simulate upgrade path: label pre-1.25 then apply 1.25 → 1.26 → 1.27 sequentially
    $pdo->exec("UPDATE settings SET setting_value = '1.24.0-cms-footer-order' WHERE setting_key = 'schema_version'");
    ok(schemaVersion($pdo) === '1.24.0-cms-footer-order', 'Schema label reset to 1.24 for sequential migration test');

    foreach ($migrations as $label => $path) {
        ok(is_readable($path), "Migration file readable {$label}");
        $code = mysqlRun($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($path));
        ok($code === 0, "Migration {$label} applies successfully");
    }
    ok(
        schemaVersion($pdo) === '1.27.0-subscription-menu-readiness',
        'After 1.25→1.26→1.27 schema_version is exactly 1.27.0-subscription-menu-readiness'
    );

    // Idempotent second pass
    foreach ($migrations as $label => $path) {
        $code = mysqlRun($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($path));
        ok($code === 0, "Migration {$label} re-apply (idempotent) succeeds");
    }
    ok(
        schemaVersion($pdo) === '1.27.0-subscription-menu-readiness',
        'Schema version unchanged after idempotent re-apply'
    );

    $legacyAfter = legacyMenuSnapshot($pdo);
    ok($legacyBefore['count'] === $legacyAfter['count'], 'Legacy menu template count unchanged after migrations');
    ok($legacyBefore['fp'] === $legacyAfter['fp'], 'Legacy menu template rows unchanged after migrations');

    $snapAfter = subscriptionBasketSnapshot($pdo, $subId);
    ok($snapBefore['sub_fp'] === $snapAfter['sub_fp'], 'Customer subscription row unchanged after migrations');
    ok($snapBefore['day_fp'] === $snapAfter['day_fp'], 'Delivery days unchanged after migrations');
    ok($snapBefore['basket_fp'] === $snapAfter['basket_fp'], 'Generated baskets unchanged after migrations');

    $productTypeCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM product_types')->fetchColumn();
    ok($productTypeCountAfter === $productTypeCountBefore, 'product_types row count stable on idempotent migration (no duplicates)');

    // Duplicate POST guard (menu plan save)
    $_SESSION = [];
    require_once APNI_TOKRI_ROOT . '/includes/admin/menu_plan_submit_guard.php';
    $token = MenuPlanSubmitGuard::issueSaveToken();
    ok(MenuPlanSubmitGuard::beginSave($token)['ok'], 'Menu plan save token accepted');
    $plan7 = SubscriptionPlanRepository::findByTemplateKey('veg_7');
    $savePayload = [
        'name' => 'Integration menu plan ' . bin2hex(random_bytes(2)),
        'duration_days' => 7,
        'subscription_plan_id' => (int) $plan7['id'],
        'repeat_mode' => 'none',
        'is_active' => false,
        'allow_incomplete' => true,
        'days' => [
            1 => ['customized' => false, 'items' => [['variant_id' => $variantId, 'qty' => 1, 'max_qty' => null]]],
        ],
    ];
    $save1 = SubscriptionMenuPlanService::save(null, $savePayload);
    ok(!empty($save1['ok']), 'Menu plan created via service');
    $planId = (int) $save1['id'];
    $dupPlanName = (string) $savePayload['name'];
    MenuPlanSubmitGuard::completeSave($token, $planId);
    $dup = MenuPlanSubmitGuard::beginSave($token);
    ok(!empty($dup['ok']) && !empty($dup['idempotent']) && (int) ($dup['plan_id'] ?? 0) === $planId, 'Duplicate save token is idempotent (no second plan)');

    $planCount = (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_plans WHERE name = ' . $pdo->quote($dupPlanName))->fetchColumn();
    ok($planCount === 1, 'Exactly one menu plan row for duplicate POST simulation');

    // Live plan activation swap
    $saveA = SubscriptionMenuPlanService::save(null, array_merge($savePayload, [
        'name' => 'Live A ' . bin2hex(random_bytes(2)),
        'is_active' => true,
        'allow_incomplete' => true,
    ]));
    $saveB = SubscriptionMenuPlanService::save(null, array_merge($savePayload, [
        'name' => 'Live B ' . bin2hex(random_bytes(2)),
        'is_active' => true,
        'allow_incomplete' => true,
    ]));
    ok(!empty($saveA['ok']) && !empty($saveB['ok']), 'Two menu plans saved as live sequentially');
    $active = (int) $pdo->query(
        'SELECT COUNT(*) FROM subscription_menu_plans WHERE subscription_plan_id = ' . (int) $plan7['id'] . ' AND is_active = 1'
    )->fetchColumn();
    ok($active === 1, 'Only one live menu plan per subscription plan after activation');
    $liveId = (int) $pdo->query(
        'SELECT id FROM subscription_menu_plans WHERE subscription_plan_id = ' . (int) $plan7['id'] . ' AND is_active = 1 LIMIT 1'
    )->fetchColumn();
    ok($liveId === (int) $saveB['id'], 'Newest live plan is active; previous plan deactivated');

    ok(Install::SCHEMA_VERSION === '1.27.0-subscription-menu-readiness', 'Install::SCHEMA_VERSION matches expected target');
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlRun($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

echo "\nIntegration: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}

echo "\n--- Downstream suites (disposable / isolated DB only) ---\n";
$runner = PHP_BINARY ?: 'php';
$downstream = [
    'Stage 11 Phase C' => $root . '/scripts/run-in-disposable-db.php',
    'Menu readiness' => $root . '/scripts/run-in-disposable-db.php',
    'Menu planner' => $root . '/scripts/test-subscription-menu-planner.php',
];
$downstreamArgs = [
    'Stage 11 Phase C' => [$root . '/scripts/test-subscription-stage11-phase-c.php'],
    'Menu readiness' => [$root . '/scripts/test-subscription-menu-readiness.php'],
    'Menu planner' => [],
];

$downFail = 0;
foreach ($downstream as $label => $script) {
    echo "\n=== {$label} ===\n";
    $args = array_merge([$script], $downstreamArgs[$label]);
    $cmd = escapeshellarg($runner) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($cmd, $code);
    if ($code !== 0) {
        $downFail++;
        echo "FAIL: {$label} exited {$code}\n";
    } else {
        echo "PASS: {$label}\n";
    }
}

echo "\n--- PHP 7.3 syntax (integration-related files) ---\n";
$syntaxFiles = [
    $root . '/scripts/test-subscription-migrations-integration.php',
    $root . '/includes/subscription/basket.php',
    $root . '/includes/subscription/menu_plan.php',
    $root . '/includes/admin/menu_plan_submit_guard.php',
    $root . '/scripts/build-apni-tokri-production-sql.php',
    $root . '/database/migrations/1.25.0_subscription_menu_plans.sql',
    $root . '/database/migrations/1.26.0_product_types.sql',
    $root . '/database/migrations/1.27.0_subscription_basket_generation_notes.sql',
];
$syntaxFail = 0;
foreach ($syntaxFiles as $f) {
    if (!is_file($f)) {
        echo "SKIP: {$f}\n";
        continue;
    }
    passthru('php7.3 -l ' . escapeshellarg($f) . ' 2>&1', $code);
    if ($code !== 0) {
        $syntaxFail++;
    }
}

echo "\nSummary: integration {$pass}/" . ($pass + $fail) . ", downstream failures {$downFail}, syntax failures {$syntaxFail}\n";
exit($fail > 0 || $downFail > 0 || $syntaxFail > 0 ? 1 : 0);
