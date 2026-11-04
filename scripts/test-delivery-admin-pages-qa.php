<?php

declare(strict_types=1);

/**
 * CLI page QA for delivery admin UI (HTML structure, conditional fields, persistence).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-delivery-admin-pages-qa.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okP(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function renderAdminPage(string $relativePath, array $get = [], array $post = [], ?int $adminId = 1): string
{
    $root = dirname(__DIR__);
    $_GET = $get;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SCRIPT_NAME'] = '/' . ltrim($relativePath, '/');
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];

    $config = App::config();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    AppSession::start($config['session'] ?? []);
    $_SESSION['admin_user_id'] = $adminId;
    $_SESSION['admin_user_email'] = 'qa@example.test';
    $_SESSION['admin_user_name'] = 'QA';
    $_SESSION['admin_auth_epoch'] = 1;
    if ($post !== [] && !isset($post[Csrf::fieldName()])) {
        $post[Csrf::fieldName()] = Csrf::token();
        $_POST = $post;
    }

    ob_start();
    $cwd = getcwd();
    chdir($root);
    try {
        include $root . '/' . ltrim($relativePath, '/');
    } finally {
        chdir($cwd ?: $root);
    }
    return (string) ob_get_clean();
}

Install::ensureConnection();
$pdo = Database::pdo();
// Avoid mandatory password-change redirect (seed hash) during page render QA.
$pdo->exec("UPDATE admin_users SET password_hash = '" . password_hash('qa-not-seed', PASSWORD_BCRYPT) . "' WHERE id = 1");

// Ensure hybrid test zone exists
$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'QA Hybrid%'");
$pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
)->execute(['QA Hybrid Zone', 'pincode_prefix', '4521', 'hybrid', 0, 8, 35, 400, 50]);
$hybridId = (int) $pdo->lastInsertId();
DeliveryConfig::resetCache();

$html = renderAdminPage('admin/settings/delivery/zones.php');
okP(strpos($html, 'Delivery Area Name') !== false, 'zones: plain-language labels present');
okP(strpos($html, 'How delivery charges are worked out') !== false, 'zones: explanation panel');
okP(strpos($html, 'Test a delivery address') !== false, 'zones: test calculator heading');
okP(strpos($html, 'data-kind-section') !== false, 'zones: conditional field markers');
okP(strpos($html, 'id="zone-kind"') !== false, 'zones: kind select');

// Pincode mode fields visible in default form HTML (JS toggles distance off)
okP(strpos($html, 'Pincode Starting Digits') !== false, 'zones: pincode field label');
okP(strpos($html, 'Minimum Distance') !== false, 'zones: distance fields in DOM for JS toggle');

$editHtml = renderAdminPage('admin/settings/delivery/zones.php', ['edit' => (string) $hybridId]);
okP(strpos($editHtml, 'value="4521"') !== false, 'hybrid edit: pincode prefix preserved');
okP(strpos($editHtml, 'hybrid') !== false && (strpos($editHtml, 'selected') !== false), 'hybrid edit: kind selected');
okP(preg_match('/name="min_distance_km"[^>]*value="[^"]*0/', $editHtml) === 1, 'hybrid edit: min distance preserved');
okP(preg_match('/name="max_distance_km"[^>]*value="[^"]*8/', $editHtml) === 1, 'hybrid edit: max distance preserved');

// Validation error preserves POST (simulate failed save)
$post = [
    'action' => 'save',
    'name' => 'QA Bad Zone',
    'zone_kind' => 'pincode',
    'match_value' => '',
    'flat_fee' => '40',
    'free_above' => '499',
    'is_active' => '1',
];
$badHtml = renderAdminPage('admin/settings/delivery/zones.php', [], $post);
okP(strpos($badHtml, 'QA Bad Zone') !== false, 'validation: name preserved on error');
okP(strpos($badHtml, 'Pincode Starting Digits') !== false && (stripos($badHtml, 'could not save') !== false || stripos($badHtml, 'enter') !== false), 'validation: error shown');

$res = DeliveryZoneAdminService::saveFromPost([
    'name' => 'QA Saved Pin Zone',
    'zone_kind' => 'pincode',
    'match_value' => '4522',
    'flat_fee' => '45',
    'free_above' => '550',
    'is_active' => '1',
], null);
okP($res['ok'], 'save: service accepts valid pincode zone');
$row = $pdo->query("SELECT flat_fee, free_above FROM delivery_zones WHERE name = 'QA Saved Pin Zone' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
okP($row && (float) $row['flat_fee'] === 45.0 && (float) $row['free_above'] === 550.0, 'save: zone persisted to database');

$calcHtml = renderAdminPage('admin/settings/delivery/zones.php', [
    'test_pin' => '452010',
    'test_amount' => '350',
]);
okP(strpos($calcHtml, 'at-delivery-test-result') !== false, 'calculator: stacked result panel markup');
okP(strpos($calcHtml, 'Delivery available') !== false && strpos($calcHtml, 'at-delivery-test-result__fee') !== false, 'calculator: prominent fee block for 452010');
okP(strpos($calcHtml, 'Just below') === false && strpos($calcHtml, 'fee_below') === false, 'calculator: no duplicate below/threshold rows');

$pol = renderAdminPage('admin/settings/delivery/policies.php');
okP(strpos($pol, 'Pincode-based delivery') !== false, 'policies: plain mode labels');
okP(strpos($pol, 'Advanced settings') !== false, 'policies: advanced collapsed section');
okP(strpos($pol, 'Straight-line distance') !== false, 'policies: distance wording');

$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'QA %'");
DeliveryConfig::resetCache();

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
