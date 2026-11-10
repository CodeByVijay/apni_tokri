<?php

declare(strict_types=1);

/**
 * Admin user delete policy (disposable DB).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-user-delete.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$ok = 0;
$fail = 0;

function adelOk(bool $cond, string $label): void
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

$pdo = Database::pdo();
stage14_p1_cleanup($pdo);

$superId = stage14_p1_super_admin_id($pdo);
adelOk($superId > 0, 'seed super admin exists');

$superBId = stage14_p1_create_user_with_role($pdo, 'at-rbac14-super-b@apnitokri.local', 'super_admin');
$supportId = stage14_p1_create_user_with_role($pdo, 'at-del-support@apnitokri.local', 'support');
$financeId = stage14_p1_create_user_with_role($pdo, 'at-del-finance@apnitokri.local', 'finance');

stage14_p1_session($superId, 'admin@apnitokri.local', 'Super Admin');

$blockSuper = AdminRbacService::deleteUser($superBId);
adelOk(
    empty($blockSuper['ok']) && ($blockSuper['message'] ?? '') === 'Super Admin accounts cannot be deleted.',
    'super admin delete blocked server-side'
);

$blockSelf = AdminRbacService::deleteUser($superId);
adelOk(
    empty($blockSelf['ok']) && ($blockSelf['message'] ?? '') === 'You cannot delete your own account.',
    'self delete blocked'
);

stage14_p1_session($superId, 'admin@apnitokri.local', 'Super Admin');
$deleted = AdminRbacService::deleteUser($financeId);
adelOk(!empty($deleted['ok']), 'delete normal admin user');
$gone = AdminRbacService::findUserById($financeId);
adelOk($gone === null, 'user row removed');

$pdo->prepare(
    'INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)'
)->execute([$supportId, 'test.action', 'test', '1']);
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_logs WHERE action = \'test.action\' AND admin_user_id = ' . $supportId)->fetchColumn();
adelOk($auditBefore === 1, 'audit row linked before delete');

stage14_p1_session($superId);
$delSupport = AdminRbacService::deleteUser($supportId);
adelOk(!empty($delSupport['ok']), 'delete second user');
$auditAfter = $pdo->query('SELECT admin_user_id FROM admin_audit_logs WHERE action = \'test.action\' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
adelOk(is_array($auditAfter) && ($auditAfter['admin_user_id'] ?? null) === null, 'audit history preserved with null admin_user_id');

$missing = AdminRbacService::deleteUser(99999999);
adelOk(empty($missing['ok']) && str_contains((string) ($missing['message'] ?? ''), 'not found'), 'missing user rejected');

stage14_p1_clear_session();
$noAuth = AdminRbacService::deleteUser($supportId);
adelOk(empty($noAuth['ok']) && ($noAuth['message'] ?? '') === 'Forbidden.', 'delete forbidden without session permission');

stage14_p1_cleanup($pdo);

fwrite(STDOUT, "\nResult: {$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
