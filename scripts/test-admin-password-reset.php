<?php

declare(strict_types=1);

/**
 * Admin forgot/reset password flow (disposable DB).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-password-reset.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$ok = 0;
$fail = 0;

function resetOk(bool $cond, string $label): void
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

$mailCapture = [];
AdminPasswordRecovery::setMailSender(static function (string $to, string $subject, string $text, string $html) use (&$mailCapture): array {
    $mailCapture[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];

    return ['ok' => true];
});

$extractToken = static function (string $body): ?string {
    if (preg_match('/reset-password\?token=([A-Za-z0-9_\-]+)/', $body, $m) === 1) {
        return rawurldecode($m[1]);
    }

    return null;
};

$pdo = Database::pdo();
$adminEmail = 'admin@apnitokri.local';
$adminId = (int) $pdo->query(
    "SELECT id FROM admin_users WHERE email = " . $pdo->quote($adminEmail) . " LIMIT 1"
)->fetchColumn();
resetOk($adminId > 0, 'seed admin account exists');
resetOk(AdminPasswordRecovery::tablesReady(), 'admin_password_reset_tokens table ready');

// Known current password hash (seed) so we can prove it changes.
$oldHash = (string) $pdo->query(
    "SELECT password_hash FROM admin_users WHERE id = " . $adminId
)->fetchColumn();

// --- 1. Request with existing admin email → capture mail ---
$r1 = AdminPasswordRecovery::requestReset($adminEmail);
resetOk(!empty($r1['ok']), 'requestReset returns ok for existing admin email');
resetOk(count($mailCapture) === 1, 'exactly one reset email captured');
resetOk($mailCapture[0]['to'] === $adminEmail, 'email addressed to the admin mailbox');
resetOk($mailCapture[0]['subject'] === 'Reset your Veggie Tokri Admin Password', 'subject line correct');

$plain = $extractToken($mailCapture[0]['html']);
resetOk(is_string($plain) && strlen($plain) >= 32, 'reset URL in HTML contains a token');
resetOk(
    preg_match('#href="https?://[^"]*reset-password\?token=#', $mailCapture[0]['html']) === 1,
    'HTML email link is an absolute URL (scheme + host + path)'
);
resetOk(strpos($mailCapture[0]['html'], '>Reset Password</a>') !== false, 'HTML email has a Reset Password button');
resetOk($extractToken($mailCapture[0]['text']) === $plain, 'plain-text part carries the same reset URL');
resetOk(strpos($mailCapture[0]['html'], '60 minutes') !== false, 'email states 60 minute expiry');
resetOk(strpos($mailCapture[0]['html'], 'did not request') !== false, 'email has ignore-if-not-you note');

// --- Token storage: hash only ---
$row = $pdo->query(
    "SELECT token_hash, used_at, expires_at FROM admin_password_reset_tokens WHERE admin_user_id = {$adminId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
resetOk(is_array($row) && $row['token_hash'] === AdminPasswordRecovery::hashToken((string) $plain), 'only token hash is stored');
resetOk(is_array($row) && $row['token_hash'] !== $plain, 'raw token is never stored');
resetOk(is_array($row) && $row['used_at'] === null, 'new token starts unused');
resetOk(
    is_array($row) && strtotime((string) $row['expires_at']) > time() + 3300,
    'token expiry ~60 minutes ahead'
);

// --- tokenStatus (GET-time display check) ---
resetOk(AdminPasswordRecovery::tokenStatus((string) $plain) === 'ok', 'tokenStatus ok for fresh token');
resetOk(AdminPasswordRecovery::tokenStatus('bogus') === 'invalid', 'tokenStatus invalid for garbage');
resetOk(AdminPasswordRecovery::tokenStatus('') === 'invalid', 'tokenStatus invalid for empty');

// --- 9. Password mismatch rejected ---
$bad = AdminPasswordRecovery::resetWithToken((string) $plain, 'Newpass12345', 'Newpass99999');
resetOk(empty($bad['ok']), 'mismatched passwords rejected');

// --- 8. Invalid token rejected ---
$badTok = str_repeat('a', 64);
resetOk(empty(AdminPasswordRecovery::resetWithToken($badTok, 'Newpass12345', 'Newpass12345')['ok']), 'invalid token rejected');
resetOk(empty(AdminPasswordRecovery::resetWithToken('short', 'Newpass12345', 'Newpass12345')['ok']), 'short token rejected');

// --- Weak password rejected by policy ---
resetOk(empty(AdminPasswordRecovery::resetWithToken((string) $plain, 'short', 'short')['ok']), 'policy rejects weak password');

// --- 3/4/5. Valid token → password changes; new works, old fails ---
$good = AdminPasswordRecovery::resetWithToken((string) $plain, 'Newpass12345', 'Newpass12345');
resetOk(!empty($good['ok']), 'valid token resets password');
$newHash = (string) $pdo->query("SELECT password_hash FROM admin_users WHERE id = {$adminId}")->fetchColumn();
resetOk(password_verify('Newpass12345', $newHash), 'new password verifies');
resetOk(!password_verify('Newpass12345', $oldHash) || $newHash !== $oldHash, 'password hash actually changed');
unset($_SESSION['admin_user_id']);
resetOk(AdminAuth::attempt($adminEmail, 'Newpass12345') === true, 'AdminAuth::attempt works with new password');
unset($_SESSION['admin_user_id']);
resetOk(AdminAuth::attempt($adminEmail, 'Wrongpass000') === false, 'wrong password cannot log in');

// --- 6. Token reuse rejected ---
resetOk(AdminPasswordRecovery::tokenStatus((string) $plain) === 'used', 'tokenStatus used after reset');
resetOk(
    empty(AdminPasswordRecovery::resetWithToken((string) $plain, 'Otherpass123', 'Otherpass123')['ok']),
    'used token rejected'
);

// --- 7. Expired token rejected (separate admin so the rate-limit window for $adminEmail stays clean) ---
$pdo->exec("INSERT INTO admin_users (email, password_hash, is_active, name) VALUES ('second@apnitokri.local', '" . password_hash('X' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT) . "', 1, 'Second Admin')");
$secondId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO admin_password_reset_tokens (admin_user_id, token_hash, expires_at, request_ip)
     VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 5 MINUTE), ?)'
)->execute([$secondId, AdminPasswordRecovery::hashToken('expiredtok' . str_repeat('e', 55)), '127.0.0.1']);
resetOk(AdminPasswordRecovery::tokenStatus('expiredtok' . str_repeat('e', 55)) === 'expired', 'tokenStatus expired');
resetOk(
    empty(AdminPasswordRecovery::resetWithToken('expiredtok' . str_repeat('e', 55), 'Otherpass123', 'Otherpass123')['ok']),
    'expired token rejected'
);

// --- 10. Unknown email → generic confirmation, no mail, no token ---
$mailBefore = count($mailCapture);
$unknown = AdminPasswordRecovery::requestReset('nobody@example.test');
resetOk(!empty($unknown['ok']), 'unknown email still returns generic ok');
resetOk($unknown['message'] === $r1['message'], 'unknown email message identical to known email (no enumeration)');
resetOk(count($mailCapture) === $mailBefore, 'no email sent for unknown account');
resetOk(
    (int) $pdo->query('SELECT COUNT(*) FROM admin_password_reset_tokens t JOIN admin_users u ON u.id = t.admin_user_id WHERE u.email = "nobody@example.test"')->fetchColumn() === 0,
    'no token row created for unknown email'
);

// --- Malformed email → generic ok, no send ---
$mailBefore = count($mailCapture);
$malformed = AdminPasswordRecovery::requestReset('not-an-email');
resetOk(!empty($malformed['ok']) && count($mailCapture) === $mailBefore, 'malformed email handled without sending');

// --- Internal diagnostics: audit breadcrumbs exist for each branch ---
$auditAction = static function (string $action): int {
    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_audit_logs WHERE action = ?');
    $stmt->execute([$action]);

    return (int) $stmt->fetchColumn();
};
resetOk($auditAction('admin.password_reset_request') > 0, 'audit: reset request logged');
resetOk($auditAction('admin.password_reset_lookup_miss') > 0, 'audit: lookup miss logged for unknown email');
resetOk($auditAction('admin.password_reset_requested') > 0, 'audit: successful send logged');
resetOk(
    (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_logs WHERE payload_json LIKE "%token%" AND action LIKE "admin.password_reset%"')->fetchColumn() === 0,
    'audit payloads never contain tokens'
);

// --- Mail send failure: error (not silent success), token rolled back, audited ---
AdminPasswordRecovery::setMailSender(static function (): array {
    return ['ok' => false, 'error' => 'auth_failed'];
});
$tokensBefore = (int) $pdo->query("SELECT COUNT(*) FROM admin_password_reset_tokens WHERE admin_user_id = {$adminId}")->fetchColumn();
$failSend = AdminPasswordRecovery::requestReset($adminEmail);
$tokensAfter = (int) $pdo->query("SELECT COUNT(*) FROM admin_password_reset_tokens WHERE admin_user_id = {$adminId}")->fetchColumn();
resetOk(empty($failSend['ok']), 'mail failure surfaces as error, not green success');
resetOk($tokensAfter === $tokensBefore, 'failed send rolls back the token row');
resetOk($auditAction('admin.password_reset_mail_failed') > 0, 'audit: mail failure logged with smtp code');
AdminPasswordRecovery::setMailSender(static function (string $to, string $subject, string $text, string $html) use (&$mailCapture): array {
    $mailCapture[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];

    return ['ok' => true];
});

// --- 11. Rate limiting (max 3 per email per hour) ---
$mailBefore = count($mailCapture);
AdminPasswordRecovery::requestReset($adminEmail); // 2nd
AdminPasswordRecovery::requestReset($adminEmail); // 3rd
$sentAtLimit = count($mailCapture) - $mailBefore;
resetOk($sentAtLimit === 2, '2nd and 3rd requests still send (limit is 3)');
$limited = AdminPasswordRecovery::requestReset($adminEmail); // 4th — over limit
resetOk(!empty($limited['ok']), 'rate-limited request still returns generic ok');
resetOk(count($mailCapture) === $mailBefore + 2, 'rate-limited request sends no email');

// --- 12. Canonical domain: admin Website URL beats a stale config base_url ---
// Reproduces the production bug: config.local.php had legacy veggietokri.in while
// Admin → Settings → Website URL holds veggietokri.com.
$pdo->prepare('DELETE FROM admin_password_reset_tokens WHERE admin_user_id = ?')->execute([$secondId]);
$cfg = App::config();
$cfg['app']['base_url'] = 'https://veggietokri.in';
$cfg['app']['url_path'] = '';
$cfg['app']['env'] = 'production';
App::setConfig($cfg);

AppSettings::set('business.website_url', 'https://veggietokri.com');
AdminPasswordRecovery::requestReset('second@apnitokri.local');
$prodMail = $mailCapture[count($mailCapture) - 1] ?? null;
resetOk(
    $prodMail !== null
    && preg_match('#https://veggietokri\.com/admin/reset-password\?token=#', $prodMail['html']) === 1,
    'admin Website URL wins over stale .in base_url'
);
resetOk(
    $prodMail !== null
    && strpos($prodMail['html'], 'veggietokri.in') === false
    && strpos($prodMail['text'], 'veggietokri.in') === false,
    'no .in domain anywhere in the email'
);

// No Website URL set + stale .in base_url + production env → canonical veggietokri.com default.
AppSettings::set('business.website_url', '');
AdminPasswordRecovery::requestReset('second@apnitokri.local');
$defMail = $mailCapture[count($mailCapture) - 1] ?? null;
resetOk(
    $defMail !== null
    && strpos($defMail['html'], 'https://veggietokri.com/admin/reset-password?token=') !== false,
    'production default veggietokri.com used when base_url stale and no Website URL'
);

// http Website URL in production → https enforced on reset links.
AppSettings::set('business.website_url', 'http://veggietokri.com');
AdminPasswordRecovery::requestReset('second@apnitokri.local');
$httpMail = $mailCapture[count($mailCapture) - 1] ?? null;
resetOk(
    $httpMail !== null
    && strpos($httpMail['html'], 'https://veggietokri.com/admin/reset-password?token=') !== false
    && strpos($httpMail['html'], 'http://veggietokri.com/admin/reset-password') === false,
    'http Website URL upgraded to https in production'
);
AppSettings::set('business.website_url', '');

// Restore config + clean state for the page-level sanity checks below.
$cfg['app']['base_url'] = 'http://localhost:8080/Apni_Tokri';
$cfg['app']['url_path'] = '/Apni_Tokri';
$cfg['app']['env'] = 'local';
App::setConfig($cfg);

// --- Missing base_url + no Website URL → refuses to send (no host-header fallback) ---
$pdo->prepare('DELETE FROM admin_password_reset_tokens WHERE admin_user_id = ?')->execute([$secondId]);
$cfg2 = App::config();
$cfg2['app']['base_url'] = '';
App::setConfig($cfg2);
$refused = AdminPasswordRecovery::requestReset('second@apnitokri.local');
resetOk(empty($refused['ok']), 'request refused when no canonical URL configured (no host-header poisoning)');
App::setConfig($cfg);

fwrite(STDOUT, "DONE ok={$ok} fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
