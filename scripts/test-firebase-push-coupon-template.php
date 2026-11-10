<?php

declare(strict_types=1);

/**
 * Promotional coupon push template + validation (unit; disposable DB for coupon rows).
 *
 * php scripts/test-firebase-push-coupon-template.php
 * php scripts/run-in-disposable-db.php --migrate-firebase-push scripts/test-firebase-push-coupon-template.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ok = 0;
$fail = 0;

function couponTestOk(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

$sampleRow = [
    'code' => 'SAVE10',
    'discount_type' => 'percent',
    'discount_value' => '10.00',
    'max_discount' => '100.00',
    'min_cart_value' => '499.00',
    'ends_at' => '2030-12-31 18:30:00',
];
$vars = [
    'customer_name' => 'Vijay',
    'coupon_code' => $sampleRow['code'],
    'discount_value' => PushCouponNotificationService::formatDiscountValue($sampleRow),
    'minimum_order' => PushCouponNotificationService::formatMinimumOrder($sampleRow),
    'expiry_date' => PushCouponNotificationService::formatExpiryDate($sampleRow),
    'click_url' => 'https://example.test/cart.php',
];

$bodyTpl = 'Hi {customer_name}, use {coupon_code} for {discount_value}. Min {minimum_order}. Till {expiry_date}.';
$rendered = PushTemplateRepository::renderField($bodyTpl, $vars);
couponTestOk(str_contains($rendered, 'Vijay') && str_contains($rendered, 'SAVE10'), 'coupon placeholders render');
couponTestOk(!str_contains($rendered, '{coupon_code}') && !str_contains($rendered, '{unknown}'), 'no unreplaced coupon placeholders');

$fixedRow = ['discount_type' => 'fixed', 'discount_value' => '50.00', 'max_discount' => null, 'min_cart_value' => null, 'ends_at' => null];
couponTestOk(str_contains(PushCouponNotificationService::formatDiscountValue($fixedRow), '₹50'), 'fixed discount formatting');
couponTestOk(PushCouponNotificationService::formatMinimumOrder($fixedRow) === 'No minimum', 'empty min_cart_value → No minimum');
couponTestOk(PushCouponNotificationService::formatExpiryDate($fixedRow) === 'No expiry', 'empty ends_at → No expiry');

$dbName = (string) (App::config()['database']['name'] ?? '');
$disposable = getenv('APNI_TOKRI_DISPOSABLE_DB') === '1' || preg_match('/^apni_tokri_(tmp|phase4|phase41|rl|sec_p1)_/i', $dbName);
if ($disposable) {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    $pdo = Database::pdo();
    $pdo->exec(
        "INSERT INTO customers (mobile, name, is_active) VALUES ('9999900001', 'Coupon Push Tester', 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    );
    $custId = (int) $pdo->query("SELECT id FROM customers WHERE mobile = '9999900001' LIMIT 1")->fetchColumn();
    couponTestOk($custId > 0, 'fixture customer');

    $pdo->prepare('DELETE FROM coupons WHERE code IN (?, ?)')->execute(['PUSHTEST_OK', 'PUSHTEST_EXP']);
    $pdo->prepare(
        'INSERT INTO coupons (code, discount_type, discount_value, min_cart_value, is_active, starts_at, ends_at)
         VALUES (?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY))'
    )->execute(['PUSHTEST_OK', 'percent', 15, 200]);
    $activeId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO coupons (code, discount_type, discount_value, is_active, ends_at)
         VALUES (?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 1 DAY))'
    )->execute(['PUSHTEST_EXP', 'fixed', 25]);
    $expiredId = (int) $pdo->lastInsertId();

    $good = PushCouponNotificationService::validateForPromotionalPush($activeId, $custId);
    couponTestOk(!empty($good['ok']), 'active coupon validates for customer');
    $bad = PushCouponNotificationService::validateForPromotionalPush($expiredId, $custId);
    couponTestOk(empty($bad['ok']) && ($bad['reason'] ?? '') === 'expired', 'expired coupon rejected');

    $pdo->prepare('UPDATE coupons SET is_active = 0 WHERE id = ?')->execute([$activeId]);
    $inactive = PushCouponNotificationService::validateForPromotionalPush($activeId, $custId);
    couponTestOk(empty($inactive['ok']) && ($inactive['reason'] ?? '') === 'inactive', 'inactive coupon rejected');

    AppSettings::set(PushSettings::KEY_DRY_RUN, '1');
    AppSettings::set(PushSettings::KEY_ENABLED, '1');
    $adminId = (int) $pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $_SESSION['admin_user_id'] = $adminId > 0 ? $adminId : 1;

    $send = PushCouponNotificationService::sendPromotionalCouponToCustomer($custId, $activeId);
    couponTestOk(empty($send['ok']) && ($send['reason'] ?? '') === 'inactive', 'send blocked when coupon inactive');

    $pdo->prepare('UPDATE coupons SET is_active = 1 WHERE id = ?')->execute([$activeId]);
    $sendDry = PushCouponNotificationService::sendPromotionalCouponToCustomer($custId, $activeId);
    $sendOk = !empty($sendDry['ok']) && (!empty($sendDry['dry_run']) || !empty($sendDry['skipped']));
    couponTestOk($sendOk, 'dry-run send records without live FCM (dry_run or no devices)');

    $tpl = PushTemplateRepository::findActive(PushCouponNotificationService::TEMPLATE_KEY);
    couponTestOk(is_array($tpl) && (string) ($tpl['template_key'] ?? '') === PushCouponNotificationService::TEMPLATE_KEY, 'coupon.promotional.push template seeded');
}

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
