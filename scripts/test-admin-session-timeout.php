<?php

declare(strict_types=1);

/**
 * VT-2026-07: admin session idle/absolute timeout regression test.
 * Runs ONLY against a disposable database:
 *   php scripts/run-in-disposable-db.php scripts/test-admin-session-timeout.php
 *
 * Drives controlled timestamps through $_SESSION — no real-time waiting.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// Keep output buffered so AppSession::regenerate() can send/replace the cookie
// header mid-test (CLI would otherwise mark headers sent after first echo).
ob_start();

$fail = 0;
$check = static function (bool $condition, string $label): void {
    global $fail;
    echo ($condition ? 'OK' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$condition) {
        ++$fail;
    }
};

$pdo = Database::pdo();
$tok = substr(bin2hex(random_bytes(4)), 0, 8);
$email = 'timeout-' . $tok . '@apnitokri.local';
$password = 'TimeoutTest!' . $tok;

$pdo->prepare(
    "INSERT INTO admin_users (email, password_hash, is_active, name) VALUES (?, ?, 1, 'Timeout Test')"
)->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();
$adminId > 0 || exit('Fixture insert failed' . PHP_EOL);
$epoch = (int) $pdo->query("SELECT auth_epoch FROM admin_users WHERE id = {$adminId}")->fetchColumn();

$_SERVER['SCRIPT_NAME'] = '/admin/dashboard.php';

$clearSession = static function (): void {
    foreach (array_keys($_SESSION) as $k) {
        unset($_SESSION[$k]);
    }
};

// Simulate an already-authenticated session state with chosen timestamps.
$establish = static function (?int $issuedAt, ?int $lastActivity) use ($adminId, $email, $epoch): void {
    $_SESSION['admin_user_id'] = $adminId;
    $_SESSION['admin_user_email'] = $email;
    $_SESSION['admin_user_name'] = 'Timeout Test';
    $_SESSION['admin_auth_epoch'] = $epoch;
    if ($issuedAt !== null) {
        $_SESSION['admin_auth_issued_at'] = $issuedAt;
    } else {
        unset($_SESSION['admin_auth_issued_at']);
    }
    if ($lastActivity !== null) {
        $_SESSION['admin_auth_last_activity'] = $lastActivity;
    } else {
        unset($_SESSION['admin_auth_last_activity']);
    }
};

$now = time();

// 1. Fresh login via the real login path stamps both timestamps.
$clearSession();
$check(AdminAuth::attempt($email, $password) === true, 'fresh admin login succeeds');
$check(isset($_SESSION['admin_auth_issued_at']) && abs($_SESSION['admin_auth_issued_at'] - $now) <= 2, 'login stamps admin_auth_issued_at');
$check(isset($_SESSION['admin_auth_last_activity']) && abs($_SESSION['admin_auth_last_activity'] - $now) <= 2, 'login stamps admin_auth_last_activity');
$check(AdminAuth::check() === true, 'fresh session passes check()');

// 2. Normal activity refreshes the idle timestamp for /admin/ requests.
$clearSession();
$establish($now, $now - 600);
$check(AdminAuth::check() === true, 'request inside idle window is valid');
$check(abs($_SESSION['admin_auth_last_activity'] - time()) <= 2, 'activity updates last_activity for /admin/ scripts');

// 3. Idle timeout: 30m1s of inactivity expires the session server-side.
$clearSession();
$establish($now, $now - 1801);
$check(AdminAuth::check() === false, 'session idle >1800s is expired');
$check(!isset($_SESSION['admin_user_id']), 'expiry clears admin_user_id');
$check(!isset($_SESSION['admin_auth_issued_at'], $_SESSION['admin_auth_last_activity']), 'expiry clears timeout timestamps');
$check(($_SESSION['admin_login_notice'] ?? '') !== '', 'expiry leaves a login notice (no sensitive details)');
$check(strpos($_SESSION['admin_login_notice'], (string) $adminId) === false, 'notice exposes no identifiers');

// 4. Just inside the idle window stays valid.
$clearSession();
$establish($now, $now - 1700);
$check(AdminAuth::check() === true, 'session idle ~1700s remains valid');

// 5. Absolute cap: >8h expires even with fresh activity.
$clearSession();
$establish($now - 28801, $now);
$check(AdminAuth::check() === false, 'session older than 28800s is expired despite activity');

// 6. Absolute lifetime is not reset by activity.
$clearSession();
$establish($now - 28700, $now - 100);
for ($i = 0; $i < 5; $i++) {
    AdminAuth::check();
}
$check($_SESSION['admin_auth_issued_at'] === $now - 28700, 'repeated activity never resets admin_auth_issued_at');
$check(AdminAuth::check() === true, 'session at ~28700s remains valid');

// 7. Legacy sessions (no timestamps) are backfilled, not force-expired.
$clearSession();
$establish(null, null);
$check(AdminAuth::check() === true, 'pre-change session without timestamps stays valid');
$check($_SESSION['admin_auth_issued_at'] > 0 && $_SESSION['admin_auth_last_activity'] > 0, 'missing timestamps are backfilled');

// 8. Expired session cannot pass the gate used by admin pages and AJAX.
$clearSession();
$establish($now, $now - 1801);
$check(AdminAuth::check() === false, 'expired session fails guard check (pages)');
$_SERVER['SCRIPT_NAME'] = '/admin/ajax/orders.php';
$clearSession();
$establish($now, $now - 1801);
$check(AdminAuth::check() === false, 'expired session fails guard check (admin AJAX)');
$_SERVER['SCRIPT_NAME'] = '/admin/dashboard.php';

// 9. Logout still clears everything.
$clearSession();
$establish($now, $now);
AdminAuth::logout();
$check(!isset($_SESSION['admin_user_id'], $_SESSION['admin_auth_issued_at'], $_SESSION['admin_auth_last_activity']), 'logout clears auth and timeout state');
$check(AdminAuth::check() === false, 'check() fails after logout');

// 10. Customer session keys are untouched by admin expiry.
$clearSession();
$_SESSION['customer_id'] = 42;
$establish($now, $now - 1801);
AdminAuth::check();
$check($_SESSION['customer_id'] === 42, 'customer session keys survive admin expiry');
unset($_SESSION['customer_id']);

// 11. Storefront requests do not refresh admin activity.
$clearSession();
$_SERVER['SCRIPT_NAME'] = '/cart.php';
$establish($now, $now - 100);
$check(AdminAuth::check() === true, 'storefront request still authenticates');
$check($_SESSION['admin_auth_last_activity'] === $now - 100, 'storefront request does not extend idle window');
$_SERVER['SCRIPT_NAME'] = '/admin/dashboard.php';

// 12. Password-change path re-stamps lifetime on success (same code path the
// mandatory-change flow uses; the seed-hash gate lives in the guard).
$clearSession();
$establish($now - 1000, $now - 1000);
$res = AdminAuth::changePasswordMandatory($password, 'NewStrong!Pass99', 'NewStrong!Pass99');
$check($res['ok'] === true, 'mandatory password change succeeds');
$check(($_SESSION['admin_auth_issued_at'] ?? 0) > $now - 1000, 'password change starts a fresh session lifetime');

// 13. Session ID regeneration on login still occurs (regenerate ran inside attempt()).
$check(session_status() === PHP_SESSION_ACTIVE, 'session remains active after login regeneration');

// Cleanup
$clearSession();
$pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$adminId]);

echo PHP_EOL . ($fail === 0 ? 'ALL TESTS PASSED' : $fail . ' FAILURES') . PHP_EOL;
ob_end_flush();
exit($fail === 0 ? 0 : 1);
