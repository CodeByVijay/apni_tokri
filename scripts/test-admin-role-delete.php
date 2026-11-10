<?php

declare(strict_types=1);

/**
 * Admin role delete policy (disposable DB).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-role-delete.php
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

function rdelOk(bool $cond, string $label): void
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
stage14_p1_session($superId, 'admin@apnitokri.local', 'Super Admin');

$financeRoleId = (int) ($pdo->query("SELECT id FROM admin_roles WHERE slug = 'finance' LIMIT 1")->fetchColumn() ?: 0);
$superRoleId = (int) ($pdo->query("SELECT id FROM admin_roles WHERE slug = 'super_admin' LIMIT 1")->fetchColumn() ?: 0);
rdelOk($financeRoleId > 0 && $superRoleId > 0, 'seed system roles exist');

$blockFinance = AdminRbacService::deleteRole($financeRoleId);
rdelOk(empty($blockFinance['ok']) && str_contains((string) ($blockFinance['message'] ?? ''), 'System roles'), 'finance system role delete blocked');

$blockSuper = AdminRbacService::deleteRole($superRoleId);
rdelOk(empty($blockSuper['ok']) && str_contains((string) ($blockSuper['message'] ?? ''), 'System roles'), 'super admin role delete blocked');

$missing = AdminRbacService::deleteRole(99999999);
rdelOk(empty($missing['ok']) && str_contains((string) ($missing['message'] ?? ''), 'not found'), 'invalid role id rejected');

$create = AdminRbacService::saveRole(null, 'QA Custom', 'at-rbac-delete-custom', 'Disposable test role', []);
rdelOk(!empty($create['ok']), 'create custom role for delete test');
$customId = (int) ($create['id'] ?? 0);

$permCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_role_permissions WHERE role_id = ' . (int) $customId)->fetchColumn();
rdelOk($permCount === 0, 'custom role starts with empty permission mappings');

$delOk = AdminRbacService::deleteRole($customId);
rdelOk(!empty($delOk['ok']), 'unassigned custom role delete succeeds');
$gone = AdminRbacService::findRoleById($customId);
rdelOk($gone === null, 'custom role row removed');
$permAfter = (int) $pdo->query('SELECT COUNT(*) FROM admin_role_permissions WHERE role_id = ' . (int) $customId)->fetchColumn();
rdelOk($permAfter === 0, 'role permission mappings removed with role');

$audit = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin_role.delete' AND entity_type = 'admin_role' AND entity_id = '"
    . $customId . "'"
)->fetchColumn();
rdelOk($audit >= 1, 'delete audit row written before removal');

$create2 = AdminRbacService::saveRole(null, 'QA Assigned', 'at-rbac-delete-assigned', 'Role with user', []);
$custom2Id = (int) ($create2['id'] ?? 0);
$userId = stage14_p1_create_user_with_role($pdo, 'at-rbac-delete-user@apnitokri.local', 'at-rbac-delete-assigned');
rdelOk($userId > 0, 'user assigned to custom role');

$blockAssigned = AdminRbacService::deleteRole($custom2Id);
rdelOk(
    empty($blockAssigned['ok'])
    && str_contains((string) ($blockAssigned['message'] ?? ''), 'assigned to 1 admin user'),
    'assigned custom role delete blocked with user count'
);
rdelOk(AdminRbacService::findRoleById($custom2Id) !== null, 'assigned role still exists after blocked delete');

$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$userId]);
$del2 = AdminRbacService::deleteRole($custom2Id);
rdelOk(!empty($del2['ok']), 'custom role delete after unassigning users');

$saveEdit = AdminRbacService::saveRole($superRoleId, 'Hacked', 'hacked-super', 'nope', []);
rdelOk(empty($saveEdit['ok']), 'super admin permissions still not editable via saveRole');

$indexPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/roles/index.php');
$listActions = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/admin/list_actions.php');
rdelOk(str_contains($listActions, 'Csrf::requireValid'), 'list delete handler validates CSRF token');
rdelOk(str_contains($indexPhp, 'admin_process_list_delete') && str_contains($indexPhp, 'Csrf::field'), 'roles index uses POST delete + CSRF');
rdelOk(str_contains($indexPhp, 'admin_role_delete_button'), 'roles index exposes delete control');

stage14_p1_clear_session();
$forbidden = AdminRbacService::deleteRole($financeRoleId);
rdelOk(empty($forbidden['ok']) && ($forbidden['message'] ?? '') === 'Forbidden.', 'delete forbidden without roles.manage session');

stage14_p1_cleanup($pdo);

fwrite(STDOUT, "\nResult: {$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
