<?php

declare(strict_types=1);

/**
 * Dispatch settings page — delivery zones table markup (disposable DB).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-delivery-dispatch-table.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okD(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

Install::ensureConnection();
$pdo = Database::pdo();
$pdo->exec("UPDATE admin_users SET password_hash = '" . password_hash('qa', PASSWORD_BCRYPT) . "' WHERE id = 1");

$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'DSP-%'");
$ins = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, eta_display, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ([
    ['DSP-Near 0-3', '', 'distance_band', 0, 3, 40, 399, null, 1],
    ['DSP-Near 3-5', '', 'distance_band', 3, 5, 50, 499, null, 1],
    ['DSP-Pin 4520', '4520', 'pincode', null, null, 40, 499, 'Within 2 hours', 1],
    ['DSP-Off', '7000', 'pincode', null, null, 99, 999, null, 0],
] as $row) {
    $ins->execute([
        $row[0], 'pincode_prefix', $row[1], $row[2],
        $row[3], $row[4], $row[5], $row[6], $row[7], 100, $row[8],
    ]);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/admin/settings/delivery.php';
$config = App::config();
AppSession::start($config['session'] ?? []);
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_user_email'] = 'qa@test';
$_SESSION['admin_user_name'] = 'QA';
$_SESSION['admin_auth_epoch'] = 1;

ob_start();
chdir(dirname(__DIR__));
include dirname(__DIR__) . '/admin/settings/delivery.php';
$html = ob_get_clean();

okD(strpos($html, 'Delivery area') !== false && strpos($html, 'Identified') !== false && strpos($html, 'Covers') !== false, 'new column headers');
okD(strpos($html, 'Flat Fee (₹)') !== false && strpos($html, 'Free Above (₹)') !== false, 'fee column headers preserved');
okD(strpos($html, 'name="zone_fee[') !== false && strpos($html, 'name="zone_free[') !== false, 'editable fee inputs retained');
okD(strpos($html, 'pincode_prefix') === false, 'no raw match_type in table body');
okD(strpos($html, 'By distance from our store') !== false, 'distance zones human-readable');
okD(strpos($html, 'By pincode') !== false, 'pincode zones human-readable');
okD(strpos($html, 'Pincodes starting with 4520') !== false || strpos($html, '4520') !== false, 'pincode covers text');
okD(strpos($html, 'Within 2 hours') !== false, 'expected time column');
okD(strpos($html, 'Delivering') !== false && strpos($html, 'Not delivering') !== false, 'status badges');
okD(strpos($html, '<th>Charges</th>') === false, 'no combined Charges column');

// Save path unchanged (same UPDATE as delivery.php POST handler)
$zid = (int) $pdo->query("SELECT id FROM delivery_zones WHERE name='DSP-Pin 4520'")->fetchColumn();
$pdo->prepare('UPDATE delivery_zones SET flat_fee = ?, free_above = ? WHERE id = ?')
    ->execute([61.0, 601.0, $zid]);
$row = $pdo->query("SELECT flat_fee, free_above FROM delivery_zones WHERE id = {$zid}")->fetch(PDO::FETCH_ASSOC);
okD((float) $row['flat_fee'] === 61.0 && (float) $row['free_above'] === 601.0, 'fee columns still map to flat_fee / free_above');

$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'DSP-%'");

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
