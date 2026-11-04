<?php

declare(strict_types=1);

/**
 * Subscription Menu Planner tests. Disposable database only: no real payments, refunds or SMS.
 * CLI: php7.3 scripts/test-subscription-menu-planner.php
 * Pure draft operations (copy/duplicate/repeat/...) are covered by: node scripts/test-menu-planner-ops.js
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$dbCfg = App::config()['database'];
$pass = 0;
$fail = 0;
function okM(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function mysqlM(array $db, string $extra): int
{
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    passthru($cmd . ' 2>/dev/null ' . $extra, $code);

    return (int) $code;
}

/** @param array<int, list<array<string, mixed>>> $dayItems day => items */
function payloadM(string $name, int $duration, array $dayItems = [], array $over = []): array
{
    $days = [];
    foreach ($dayItems as $d => $items) {
        $days[$d] = ['customized' => true, 'items' => $items];
    }

    return array_merge([
        'name' => $name, 'duration_days' => $duration, 'subscription_plan_id' => '', 'repeat_mode' => 'none',
        'repeat_every' => '', 'is_active' => false, 'allow_incomplete' => false, 'days' => $days,
    ], $over);
}

function lineM(int $vid, float $qty = 1, ?float $max = null): array
{
    return ['variant_id' => $vid, 'qty' => $qty, 'max_qty' => $max];
}

$root = dirname(__DIR__);
$sqlFile = $root . '/database/production/apni_tokri_production.sql';
$freshDb = 'apni_tokri_menuplan_' . date('YmdHis');

okM(mysqlM($dbCfg, '-e ' . escapeshellarg("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) === 0, 'Create disposable DB');
okM(mysqlM($dbCfg, escapeshellarg($freshDb) . ' < ' . escapeshellarg($sqlFile)) === 0, 'Import production SQL into the EMPTY disposable DB');

$orig = App::config();
App::setConfig(array_replace_recursive($orig, ['database' => array_merge($dbCfg, ['name' => $freshDb])]));
Database::disconnect();

try {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $pdo->exec('SET time_zone = \'+00:00\'');
    okM(Install::isSchemaReady(), 'Schema version matches ' . Install::SCHEMA_VERSION);

    // ---- Fixtures ----
    $pdo->exec("INSERT INTO categories (name, slug) VALUES ('Vegetables','mp-veg')");
    $cat = (int) $pdo->lastInsertId();
    $mkVar = static function (string $name, string $sku, float $price, ?float $sale, int $stock, int $active, string $status) use ($pdo, $cat): int {
        $pdo->prepare('INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$cat, $name, strtolower($sku), $sku, $status]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$pid, '500 g', $sku . '-500', $price, $sale, $stock, $active]);

        return (int) $pdo->lastInsertId();
    };
    $vTomato = $mkVar('Tomato', 'MP-TOM', 20, null, 200, 1, 'published');
    $vPotato = $mkVar('Potato', 'MP-POT', 18, null, 200, 1, 'published');
    $vOnion = $mkVar('Onion', 'MP-ONI', 25, 22, 200, 1, 'published');
    $vSpinach = $mkVar('Spinach', 'MP-SPI', 15, null, 200, 1, 'published');
    $vCarrot = $mkVar('Carrot', 'MP-CAR', 30, null, 200, 1, 'published');
    $vOos = $mkVar('Brinjal', 'MP-BRI', 28, null, 0, 1, 'published');
    $vInactive = $mkVar('Okra', 'MP-OKR', 40, null, 50, 0, 'published');
    $vDraftProd = $mkVar('Pumpkin', 'MP-PUM', 35, null, 50, 1, 'draft');
    $all = [$vTomato, $vPotato, $vOnion, $vSpinach, $vCarrot];

    $plan7 = SubscriptionPlanRepository::findByTemplateKey('veg_7');
    okM($plan7 !== null, 'Standard plan veg_7 present in a fresh install');
    $plan15 = SubscriptionPlanRepository::findByTemplateKey('veg_15');
    $plan30 = SubscriptionPlanRepository::findByTemplateKey('veg_30');
    okM($plan15 !== null && $plan30 !== null, 'Standard plans veg_15 and veg_30 present');
    $planCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn();

    // Pre-existing legacy global templates (must keep working, must never be touched)
    $legacy = SubscriptionMenuRepository::save(null, ['name' => 'Legacy day 1', 'planner_type' => 'day_index', 'day_index' => '1', 'is_active' => '1'], [[
        'variant_id' => $vCarrot, 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => 30, 'include_in_rotation' => 1, 'max_qty' => 2,
    ]]);
    okM($legacy['ok'], 'Legacy global day template created (veg_7-style, menu_plan_id NULL)');
    $legacyId = (int) $legacy['id'];
    $legacyBefore = json_encode([
        $pdo->query('SELECT * FROM subscription_menu_templates WHERE id = ' . $legacyId)->fetch(PDO::FETCH_ASSOC),
        $pdo->query('SELECT variant_id, default_qty, unit_price_inr FROM subscription_menu_template_items WHERE menu_template_id = ' . $legacyId)->fetchAll(PDO::FETCH_ASSOC),
    ]);

    // ================= Plan creation =================
    $mk = static function (string $name, int $days) use ($all): array {
        $items = [];
        for ($d = 1; $d <= $days; $d++) {
            $items[$d] = [lineM($all[$d % 5], 1, 2), lineM($all[($d + 1) % 5], 2)];
        }

        return SubscriptionMenuPlanService::save(null, payloadM($name, $days, $items));
    };
    $cnt = static function (int $id) use ($pdo): array {
        $a = (int) $pdo->query("SELECT COUNT(*) FROM subscription_menu_templates WHERE menu_plan_id = {$id} AND is_active = 1")->fetchColumn();
        $b = (int) $pdo->query("SELECT COUNT(*) FROM subscription_menu_template_items i INNER JOIN subscription_menu_templates t ON t.id = i.menu_template_id WHERE t.menu_plan_id = {$id}")->fetchColumn();

        return [$a, $b];
    };
    $r7 = $mk('Seven day menu', 7);
    okM($r7['ok'] && $cnt((int) $r7['id']) === [7, 14] && $r7['summary']['days_saved'] === 7, 'Create 7-day plan (7 day rows, 14 entries)');
    $r15 = $mk('Fifteen day menu', 15);
    okM($r15['ok'] && $cnt((int) $r15['id']) === [15, 30], 'Create 15-day plan');
    $r30 = $mk('Thirty day menu', 30);
    okM($r30['ok'] && $cnt((int) $r30['id']) === [30, 60], 'Create 30-day plan');
    $r5 = $mk('Custom five', 5);
    okM($r5['ok'] && $cnt((int) $r5['id']) === [5, 10], 'Create custom 5-day plan');
    $r45 = $mk('Custom forty-five', 45);
    okM($r45['ok'] && $cnt((int) $r45['id']) === [45, 90], 'Create custom 45-day plan');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_plans')->fetchColumn();
    $bad = true;
    foreach (['0', 'abc', '-3', '7.5', '', '1e2'] as $dv) {
        $res = SubscriptionMenuPlanService::save(null, payloadM('Bad ' . $dv, 1, [], ['duration_days' => $dv]));
        if ($res['ok']) {
            $bad = false;
        }
    }
    okM($bad, 'Reject duration 0 / non-numeric / negative / fractional / empty');
    $over = SubscriptionMenuPlanService::save(null, payloadM('Too long', 366));
    $max = SubscriptionMenuPlanService::save(null, payloadM('Max length', 365));
    okM(!$over['ok'] && $max['ok'] && $cnt((int) $max['id']) === [365, 0], 'Reject 366 days; accept the 365-day maximum');
    $dupName = SubscriptionMenuPlanService::save(null, payloadM('seven DAY menu', 7));
    $noName = SubscriptionMenuPlanService::save(null, payloadM('  ', 7));
    okM(!$dupName['ok'] && !$noName['ok'], 'Reject duplicate and empty plan names');
    okM((int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_plans')->fetchColumn() === $before + 1, 'Rejected saves write nothing (only the valid 365-day plan was added)');

    // ================= Daily menu operations (server side) =================
    $p7 = (int) $r7['id'];
    $loaded = SubscriptionMenuPlanService::load($p7);
    okM($loaded !== null && $loaded['configured_days'] === 7 && count($loaded['days'][1]['items']) === 2, 'Load plan back: 7 of 7 days configured');
    $days = [];
    foreach ($loaded['days'] as $d => $dd) {
        $days[$d] = ['customized' => true, 'items' => array_map(static function ($i) {
            return ['variant_id' => $i['variant_id'], 'qty' => $i['qty'], 'max_qty' => $i['max_qty']];
        }, $dd['items'])];
    }
    $edit = $days;
    $edit[2]['items'][0]['qty'] = 5;
    $edit[2]['items'][0]['max_qty'] = 6;
    $edit[2]['items'][] = lineM($vCarrot, 3);
    $res = SubscriptionMenuPlanService::save($p7, payloadM('Seven day menu', 7, [], ['days' => $edit]));
    $after = SubscriptionMenuPlanService::load($p7);
    okM($res['ok'] && count($after['days'][2]['items']) === 3 && (float) $after['days'][2]['items'][0]['qty'] === 5.0
        && count($after['days'][1]['items']) === 2 && count($after['days'][3]['items']) === 2, 'Edit one day (qty change + added vegetable); other days unchanged');
    $rem = $edit;
    $rem[2]['items'] = [$edit[2]['items'][0]];
    SubscriptionMenuPlanService::save($p7, payloadM('Seven day menu', 7, [], ['days' => $rem]));
    $after = SubscriptionMenuPlanService::load($p7);
    okM(count($after['days'][2]['items']) === 1 && $after['configured_days'] === 7, 'Remove vegetables from a day');
    $clr = $rem;
    $clr[4]['items'] = [];
    $clr[5]['items'] = [];
    $res = SubscriptionMenuPlanService::save($p7, payloadM('Seven day menu', 7, [], ['days' => $clr]));
    $after = SubscriptionMenuPlanService::load($p7);
    okM($res['ok'] && $after['configured_days'] === 5 && $res['summary']['configured_days'] === 5, 'Cleared days save as empty (5 of 7 configured)');
    $liveIncomplete = SubscriptionMenuPlanService::save($p7, payloadM('Seven day menu', 7, [], [
        'days' => $clr, 'subscription_plan_id' => (int) $plan7['id'], 'is_active' => true,
    ]));
    okM(!$liveIncomplete['ok'] && stripos(implode(' ', $liveIncomplete['errors']), 'Day 4, 5') !== false, 'Incomplete plan cannot go live without explicit confirmation (names the empty days)');
    $liveOk = SubscriptionMenuPlanService::save($p7, payloadM('Seven day menu', 7, [], [
        'days' => $clr, 'subscription_plan_id' => (int) $plan7['id'], 'is_active' => true, 'allow_incomplete' => true,
    ]));
    okM($liveOk['ok'], 'Incomplete plan can go live after explicit confirmation');
    SubscriptionMenuPlanService::setActive($p7, false);

    // ================= Validation =================
    $v = static function (array $items, array $over = []) use ($pdo): array {
        $r = SubscriptionMenuPlanService::save(null, payloadM('V ' . bin2hex(random_bytes(4)), 2, [1 => $items], $over));

        return $r;
    };
    $nTplBefore = (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_templates')->fetchColumn();
    $cases = [
        'missing variant' => [lineM(999999, 1)],
        'zero quantity' => [lineM($vTomato, 0)],
        'negative quantity' => [lineM($vTomato, -1)],
        'max below suggested' => [lineM($vTomato, 3, 2)],
        'duplicate variant' => [lineM($vTomato, 1), lineM($vTomato, 2)],
        'inactive variant (new)' => [lineM($vInactive, 1)],
        'unpublished product (new)' => [lineM($vDraftProd, 1)],
    ];
    $allRejected = true;
    $msgs = [];
    foreach ($cases as $label => $items) {
        $r = $v($items);
        if ($r['ok']) {
            $allRejected = false;
            $msgs[] = $label;
        }
    }
    okM($allRejected, 'Server rejects missing / zero / negative / max<qty / duplicate / inactive / unpublished lines' . ($msgs ? ' [accepted: ' . implode(',', $msgs) . ']' : ''));
    okM((int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_templates')->fetchColumn() === $nTplBefore, 'Invalid saves leave no partial data (transaction/validation)');
    $maxOk = $v([lineM($vTomato, 2, 2)]);
    okM($maxOk['ok'], 'Max quantity equal to suggested quantity is accepted');
    $outside = SubscriptionMenuPlanService::save(null, payloadM('Outside day', 3, [9 => [lineM($vTomato)]]));
    okM(!$outside['ok'], 'Day number outside the plan duration is rejected');
    $linkBad = SubscriptionMenuPlanService::save(null, payloadM('Bad link', 3, [], ['subscription_plan_id' => 987654]));
    $liveNoPlan = SubscriptionMenuPlanService::save(null, payloadM('Live without plan', 1, [1 => [lineM($vTomato)]], ['is_active' => true]));
    okM(!$linkBad['ok'] && !$liveNoPlan['ok'], 'Unknown subscription plan / live without a plan rejected');

    // ================= Price snapshots =================
    $rp = SubscriptionMenuPlanService::save(null, payloadM('Price snapshot plan', 1, [1 => [lineM($vOnion, 1), lineM($vTomato, 1)]]));
    $pid = (int) $rp['id'];
    $priceOf = static function (int $planId, int $vid) use ($pdo): float {
        $s = $pdo->prepare('SELECT i.unit_price_inr FROM subscription_menu_template_items i INNER JOIN subscription_menu_templates t ON t.id = i.menu_template_id
                            WHERE t.menu_plan_id = ? AND t.day_index = 1 AND i.variant_id = ?');
        $s->execute([$planId, $vid]);

        return (float) $s->fetchColumn();
    };
    okM($priceOf($pid, $vOnion) === 22.0 && $priceOf($pid, $vTomato) === 20.0, 'Menu prices come from the catalogue (onion uses its sale price 22, not regular 25 or any client value)');
    $spoof = SubscriptionMenuPlanService::save(null, payloadM('Spoof price', 1, [1 => [['variant_id' => $vTomato, 'qty' => 1, 'max_qty' => null, 'price' => 1, 'unit_price_inr' => 1]]]));
    okM($spoof['ok'] && $priceOf((int) $spoof['id'], $vTomato) === 20.0, 'Client-supplied prices are ignored');
    $pdo->exec("UPDATE product_variants SET price = 99, sale_price = NULL WHERE id = {$vTomato}");
    $rd = SubscriptionMenuPlanService::load($pid);
    $reSave = static function (array $over = []) use ($pid, $rd, $vCarrot): array {
        $days = [];
        foreach ($rd['days'] as $d => $dd) {
            $days[$d] = ['customized' => true, 'items' => array_map(static function ($i) {
                return ['variant_id' => $i['variant_id'], 'qty' => $i['qty'], 'max_qty' => $i['max_qty']];
            }, $dd['items'])];
        }
        $days[1]['items'][] = lineM($vCarrot, 1);

        return SubscriptionMenuPlanService::save($pid, payloadM('Price snapshot plan', 1, [], array_merge(['days' => $days], $over)));
    };
    $reSave();
    okM($priceOf($pid, $vTomato) === 20.0 && $priceOf($pid, $vCarrot) === 30.0, 'Re-saving preserves the saved price snapshot of existing lines (catalogue now 99); new lines use today\'s price');
    $rd = SubscriptionMenuPlanService::load($pid);
    okM($rd['days'][1]['items'][0]['current_price'] !== null && in_array(99.0, array_column($rd['days'][1]['items'], 'current_price'), true), 'Editor exposes the changed catalogue price next to the saved one');
    $reSave(['refresh_prices' => true]);
    okM($priceOf($pid, $vTomato) === 99.0, 'Refresh-prices option updates to the current catalogue price');
    $pdo->exec("UPDATE product_variants SET price = 20 WHERE id = {$vTomato}");

    // ================= Search =================
    $s1 = SubscriptionMenuPlanService::searchVariants('toma');
    $s2 = SubscriptionMenuPlanService::searchVariants('MP-POT');
    $s3 = SubscriptionMenuPlanService::searchVariants('500 g');
    $onionRow = null;
    foreach (SubscriptionMenuPlanService::searchVariants('onion') as $r) {
        $onionRow = $r;
    }
    $inactiveRow = SubscriptionMenuPlanService::searchVariants('okra')[0] ?? null;
    okM(count($s1) === 1 && $s1[0]['variant_id'] === $vTomato && count($s2) === 1 && $s2[0]['variant_id'] === $vPotato && count($s3) >= 8, 'Search by product name, SKU and variant name');
    okM($onionRow !== null && $onionRow['price'] === 22.0 && $onionRow['stock_qty'] === 200 && $onionRow['sku'] === 'MP-ONI-500' && array_key_exists('image_url', $onionRow)
        && $inactiveRow !== null && $inactiveRow['available'] === false, 'Search results show price, stock, SKU, image field, and flag unavailable variants');
    okM(count(SubscriptionMenuPlanService::searchVariants("x' OR '1'='1")) === 0 && count(SubscriptionMenuPlanService::searchVariants('%')) === 0, 'Search is injection/wildcard safe');

    // ================= Compatibility: legacy templates untouched =================
    $legacyAfter = json_encode([
        $pdo->query('SELECT * FROM subscription_menu_templates WHERE id = ' . $legacyId)->fetch(PDO::FETCH_ASSOC),
        $pdo->query('SELECT variant_id, default_qty, unit_price_inr FROM subscription_menu_template_items WHERE menu_template_id = ' . $legacyId)->fetchAll(PDO::FETCH_ASSOC),
    ]);
    okM($legacyBefore === $legacyAfter, 'Existing global templates are byte-for-byte unchanged by planner activity');
    okM((int) $pdo->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn() === $planCountBefore
        && SubscriptionPlanRepository::findByTemplateKey('veg_7') !== null, 'Standard plans veg_7/15/30 untouched');
    $listed = SubscriptionMenuRepository::listForAdminPaginated(1, 100, '');
    $listedIds = array_map('intval', array_column($listed['rows'], 'id'));
    okM(in_array($legacyId, $listedIds, true) && $listed['total'] === SubscriptionMenuRepository::countForAdmin('')
        && (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_templates WHERE menu_plan_id IS NULL')->fetchColumn() === $listed['total'], 'Advanced template list shows only legacy templates');
    $planTpl = (int) $pdo->query("SELECT id FROM subscription_menu_templates WHERE menu_plan_id = {$p7} LIMIT 1")->fetchColumn();
    okM(!SubscriptionMenuRepository::deleteForAdmin($planTpl)['ok'], 'Planner-owned day templates cannot be deleted from the legacy list');

    // ================= Subscriptions: resolution, snapshots, reservations =================
    $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
    $mobile = static function (): string {
        return '97' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    };
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile(), 'Planner Customer A']);
    $custA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile(), 'Planner Customer B']);
    $custB = (int) $pdo->lastInsertId();
    foreach ([$custA, $custB] as $cid) {
        $pdo->prepare('INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$cid, 'Home', 'Test line', 'Indore', 'MP', '452001']);
    }
    $snapshot = SubscriptionPlanRepository::toSnapshotArray($plan7, (float) $plan7['price']);
    $mkSub = static function (int $cust, string $createdAt, string $tag, array $dayIdx) use ($pdo, $plan7, $snapshot, $tz): array {
        $num = 'SUB-MP-' . $tag . '-' . strtoupper(substr(Security::randomToken(3), 0, 5));
        $pdo->prepare(
            'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json, price_paid, status,
             deliveries_remaining, start_date, calendar_end_date, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE() + INTERVAL 1 DAY, CURDATE() + INTERVAL 14 DAY, ?)'
        )->execute([$num, $cust, (int) $plan7['id'], json_encode($snapshot, JSON_UNESCAPED_UNICODE), (float) $plan7['price'], 'active', (int) $plan7['delivery_count'], $createdAt]);
        $subId = (int) $pdo->lastInsertId();
        $days = [];
        foreach ($dayIdx as $n => $di) {
            $date = (new DateTimeImmutable('+' . (3 + $n) . ' days', $tz))->format('Y-m-d');
            $cut = SubscriptionCutoff::cutoffDateTime($date, SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot))->format('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
                           VALUES (?, ?, ?, ?, ?, ?)')->execute([$subId, $date, $di, 'scheduled', $cut, 'none']);
            $days[$di] = (int) $pdo->lastInsertId();
        }

        return ['num' => $num, 'id' => $subId, 'days' => $days];
    };
    $basketFor = static function (array $sub, int $dayIndex) use ($snapshot): array {
        $subRow = CustomerSubscriptionRepository::findByNumber($sub['num']);
        $dayRow = SubscriptionBasketRepository::dayByIdForSubscription($sub['days'][$dayIndex], $sub['id']);

        return SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);
    };
    $variantsOf = static function (array $res): array {
        $ids = array_map(static function ($i) {
            return (int) $i['variant_id'];
        }, $res['items'] ?? []);
        sort($ids);

        return $ids;
    };

    // Subscriber A started BEFORE the planner menu goes live.
    $subA = $mkSub($custA, '2000-01-01 00:00:00', 'A', [1 => 1, 2 => 2]);
    $menuLive = SubscriptionMenuPlanService::save(null, payloadM('Live veg_7 menu', 7, [
        1 => [lineM($vTomato, 1, 2), lineM($vPotato, 1), lineM($vOnion, 1)],
        2 => [lineM($vSpinach, 1), lineM($vOos, 1), lineM($vCarrot, 1)],
        3 => [lineM($vTomato, 1)], 4 => [lineM($vTomato, 1)], 5 => [lineM($vTomato, 1)], 6 => [lineM($vTomato, 1)], 7 => [lineM($vTomato, 1)],
    ], ['subscription_plan_id' => (int) $plan7['id'], 'is_active' => true]));
    okM($menuLive['ok'], 'Menu plan made live for veg_7');
    $livePlanId = (int) $menuLive['id'];
    $act = $pdo->query("SELECT activated_at FROM subscription_menu_plans WHERE id = {$livePlanId}")->fetchColumn();
    // Subscriber B started AFTER activation.
    $subB = $mkSub($custB, (new DateTimeImmutable((string) $act))->modify('+1 minute')->format('Y-m-d H:i:s'), 'B', [1 => 1, 2 => 2, 3 => 3]);

    $genA = $basketFor($subA, 1);
    okM($genA['ok'] && $variantsOf($genA) === [$vCarrot], 'Existing subscription keeps resolving the legacy menu (unchanged by the new live plan)');
    $genB = $basketFor($subB, 1);
    $expectB = [$vTomato, $vPotato, $vOnion];
    sort($expectB);
    okM($genB['ok'] && $variantsOf($genB) === $expectB, 'New subscription gets the live Menu Planner day-1 vegetables');

    $mapLine = static function (array $res): array {
        $o = [];
        foreach ($res['items'] as $i) {
            $o[(int) $i['variant_id']] = [(float) $i['qty'], (float) $i['unit_price_inr'], (string) $i['product_name']];
        }
        ksort($o);

        return $o;
    };
    $snapB = $mapLine($genB);
    // Edit the live menu AFTER the basket exists: change qty, price in catalogue, replace vegetables on day 1.
    $pdo->exec("UPDATE product_variants SET price = 77 WHERE id = {$vPotato}");
    $liveLoaded = SubscriptionMenuPlanService::load($livePlanId);
    $ld = [];
    foreach ($liveLoaded['days'] as $d => $dd) {
        $ld[$d] = ['customized' => true, 'items' => array_map(static function ($i) {
            return ['variant_id' => $i['variant_id'], 'qty' => $i['qty'], 'max_qty' => $i['max_qty']];
        }, $dd['items'])];
    }
    $ld[1]['items'] = [lineM($vSpinach, 4)];
    $editLive = SubscriptionMenuPlanService::save($livePlanId, payloadM('Live veg_7 menu', 7, [], [
        'days' => $ld, 'subscription_plan_id' => (int) $plan7['id'], 'is_active' => true, 'refresh_prices' => true,
    ]));
    okM($editLive['ok'], 'Edit the live menu after baskets were generated');
    $genB2 = $basketFor($subB, 1);
    okM(!empty($genB2['ok']) && $mapLine($genB2) === $snapB, 'Already-generated customer basket is a snapshot: unchanged lines, quantities and prices after menu + catalogue edits');
    $subB2 = $mkSub($custB, (new DateTimeImmutable((string) $act))->modify('+2 minutes')->format('Y-m-d H:i:s'), 'B2', [1 => 1]);
    $genNew = $basketFor($subB2, 1);
    okM($variantsOf($genNew) === [$vSpinach], 'A later subscription sees the edited menu (edits only affect future baskets)');
    $genA2 = $basketFor($subA, 1);
    okM($variantsOf($genA2) === [$vCarrot], 'Edits to the live menu still do not reach the earlier subscription');

    // Stock + no silent substitution: day 2 contains an out-of-stock variant.
    $genDay2 = $basketFor($subB, 2);
    $d2 = $variantsOf($genDay2);
    okM($genDay2['ok'] && !in_array($vOos, $d2, true) && count(array_diff($d2, [$vSpinach, $vCarrot, $vOos])) === 0 && count($d2) === 2,
        'Out-of-stock vegetable is skipped at basket generation and never replaced by another product');
    $genDay3 = $basketFor($subB, 3);
    okM($variantsOf($genDay3) === [$vTomato], 'Each day resolves its own menu (day 3 differs from day 1/2)');
    $pdo->exec("UPDATE product_variants SET is_active = 0 WHERE id = {$vTomato}");
    $subB3 = $mkSub($custB, (new DateTimeImmutable((string) $act))->modify('+3 minutes')->format('Y-m-d H:i:s'), 'B3', [3 => 3]);
    $genInactive = $basketFor($subB3, 3);
    okM(empty($genInactive['ok']) && ($genInactive['items'] ?? []) === [], 'Variant deactivated after planning is not served and not substituted (no basket generated)');
    $pdo->exec("UPDATE product_variants SET is_active = 1 WHERE id = {$vTomato}");

    // Customer customisation within limits, confirm, reservation + order idempotency
    $dayB1 = $subB['days'][1];
    $over = SubscriptionBasketService::saveCustomerDays($custB, $subB['num'], [$dayB1 => ['lines' => [['variant_id' => $vTomato, 'qty' => 500]]]], true);
    okM(!$over['ok'], 'Customer cannot exceed allowance/limits through basket customisation');
    $custSave = SubscriptionBasketService::saveCustomerDays($custB, $subB['num'], [$dayB1 => ['lines' => [['variant_id' => $vPotato, 'qty' => 1], ['variant_id' => $vOnion, 'qty' => 1]]]], true);
    okM(!empty($custSave['ok']), 'Customer can customise and confirm a planner-generated basket within limits');

    $pdo->prepare('UPDATE subscription_delivery_days SET selection_cutoff_at = DATE_SUB(NOW(), INTERVAL 1 DAY), delivery_date = CURDATE() WHERE id = ?')->execute([$dayB1]);
    require_once __DIR__ . '/includes/subscription_test_fulfilment_fixture.php';
    SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $dayB1);
    $stockBefore = (int) $pdo->query("SELECT stock_qty FROM product_variants WHERE id = {$vOnion}")->fetchColumn();
    $run = 'mp_' . time();
    $g1 = SubscriptionFulfilmentService::generateForDay($dayB1, $run);
    $g2 = SubscriptionFulfilmentService::generateForDay($dayB1, $run);
    $stockAfter = (int) $pdo->query("SELECT stock_qty FROM product_variants WHERE id = {$vOnion}")->fetchColumn();
    $orders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE subscription_delivery_day_id = {$dayB1}")->fetchColumn();
    okM(!empty($g1['ok']) && !empty($g2['idempotent']) && $orders === 1, 'Fulfilment from a planner-based basket is idempotent (one order for two runs)');
    okM($stockBefore - $stockAfter <= 1 && $stockBefore - $stockAfter >= 0, 'Inventory is reserved once, not twice (stock moved by ' . ($stockBefore - $stockAfter) . ' for qty 1)');
    $oiv = $pdo->query("SELECT oi.variant_id FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.subscription_delivery_day_id = {$dayB1} ORDER BY oi.variant_id")->fetchAll(PDO::FETCH_COLUMN);
    $expectOi = [$vPotato, $vOnion];
    sort($expectOi);
    okM(array_map('intval', $oiv) === $expectOi, 'Generated subscription order contains exactly the customer-confirmed basket lines (not the planner menu)');

    // Plan / activation rules
    $second = SubscriptionMenuPlanService::save(null, payloadM('Replacement menu', 7, [1 => [lineM($vTomato)]], [
        'subscription_plan_id' => (int) $plan7['id'], 'is_active' => true, 'allow_incomplete' => true,
    ]));
    $active = (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_plans WHERE subscription_plan_id = ' . (int) $plan7['id'] . ' AND is_active = 1')->fetchColumn();
    okM($second['ok'] && $active === 1, 'Only one live menu plan per subscription plan');
    $delLive = SubscriptionMenuPlanService::delete($livePlanId);
    $delDraft = SubscriptionMenuPlanService::delete((int) $r5['id']);
    okM(!$delLive['ok'] && $delDraft['ok'], 'A menu plan that has been live cannot be deleted; a never-live draft can');
    $grow = SubscriptionMenuPlanService::load((int) $second['id']);
    $shrinkRes = SubscriptionMenuPlanService::save((int) $second['id'], payloadM('Replacement menu', 3, [1 => [lineM($vTomato)]], [
        'subscription_plan_id' => (int) $plan7['id'], 'is_active' => true, 'allow_incomplete' => true,
    ]));
    $hidden = (int) $pdo->query('SELECT COUNT(*) FROM subscription_menu_templates WHERE menu_plan_id = ' . (int) $second['id'] . ' AND is_active = 0')->fetchColumn();
    okM($shrinkRes['ok'] && $hidden === 4, 'Shrinking a plan deactivates (does not delete) days beyond the new duration');

    // Migration is idempotent
    $migration = $root . '/database/migrations/1.25.0_subscription_menu_plans.sql';
    okM(mysqlM(array_merge($dbCfg, []), escapeshellarg($freshDb) . ' < ' . escapeshellarg($migration)) === 0, 'Migration 1.25.0 can be re-applied safely (idempotent)');
    okM(Install::isSchemaReady(), 'Schema version still ready after re-applying the migration');
} finally {
    App::setConfig($orig);
    Database::disconnect();
    mysqlM($dbCfg, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$freshDb}`"));
}

// Pure draft operations
$node = trim((string) shell_exec('command -v node'));
if ($node !== '') {
    $out = [];
    exec(escapeshellarg($node) . ' ' . escapeshellarg($root . '/scripts/test-menu-planner-ops.js') . ' 2>&1', $out, $code);
    $nodeFails = count(array_filter($out, static function ($l) {
        return strpos($l, 'FAIL:') === 0;
    }));
    $nodePass = count(array_filter($out, static function ($l) {
        return strpos($l, 'OK:') === 0;
    }));
    okM($code === 0 && $nodeFails === 0, "Draft operations (node): {$nodePass} checks passed");
} else {
    echo "SKIP: node not available; draft operations not executed\n";
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
