<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply migration 1.12.0_stage14_sms_phase3.sql (expected ' . Install::SCHEMA_VERSION . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

stage14_p1_cleanup($pdo);

$invId = stage14_p1_create_user_with_role($pdo, 'at-rbac14-inv@apnitokri.local', 'inventory_manager');
stage14_p1_session($invId, 'at-rbac14-inv@apnitokri.local');
ok(!Permission::canModule('payments'), 'Limited admin cannot access payments without payments.view');
ok(!Permission::canModule('settings'), 'Limited admin cannot access settings without settings.manage');
ok(!Permission::canModule('users'), 'Limited admin cannot manage users without users.manage');
ok(!Permission::canModule('roles'), 'Limited admin cannot manage roles without roles.manage');
stage14_p1_clear_session();

$superId = stage14_p1_super_admin_id($pdo);
ok($superId > 0, 'Fixture super-admin exists');
stage14_p1_session($superId, 'admin@apnitokri.local', 'Super');

$usersPermId = (int) ($pdo->query("SELECT id FROM admin_permissions WHERE slug = 'users.manage' LIMIT 1")->fetchColumn() ?: 0);
$invRoleId = AdminRbacService::roleIdBySlug('inventory_manager');
ok($invRoleId !== null && $usersPermId > 0, 'Permission and role ids resolved');

stage14_p1_session($invId, 'at-rbac14-inv@apnitokri.local');
$badRole = AdminRbacService::saveRole(null, 'AT RBAC Custom Bad', 'at-rbac14-custom-bad', 'test', [$usersPermId]);
ok($badRole['ok'] === false, 'User cannot grant permissions they do not possess (via custom role)');
stage14_p1_clear_session();

stage14_p1_session($invId, 'at-rbac14-inv@apnitokri.local');
$saRole = AdminRbacService::roleIdBySlug('super_admin') ?? 0;
$selfEsc = AdminRbacService::saveUser(
    $invId,
    'at-rbac14-inv@apnitokri.local',
    'AT RBAC14',
    true,
    null,
    [$saRole]
);
ok($selfEsc['ok'] === false, 'User cannot self-escalate to super-admin');
stage14_p1_clear_session();

stage14_p1_session($superId, 'admin@apnitokri.local', 'Super');
$saRole = AdminRbacService::roleIdBySlug('super_admin') ?? 0;
$superA = stage14_p1_create_user_with_role($pdo, 'at-rbac14-super-a@apnitokri.local', 'super_admin');
$superB = stage14_p1_create_user_with_role($pdo, 'at-rbac14-super-b@apnitokri.local', 'super_admin');
$deactOne = AdminRbacService::saveUser(
    $superA,
    'at-rbac14-super-a@apnitokri.local',
    'Super A',
    false,
    null,
    [$saRole]
);
ok($deactOne['ok'] === true, 'Can deactivate super-admin when another active super-admin exists');
$pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE id = ?')->execute([$superA]);
if (AdminRbacService::countActiveSuperAdminUsers($superB) === 0) {
    $deactLast = AdminRbacService::saveUser(
        $superB,
        'at-rbac14-super-b@apnitokri.local',
        'Super B',
        false,
        null,
        [$saRole]
    );
    ok($deactLast['ok'] === false, 'Last active super-admin cannot be deactivated');
    $stripRole = AdminRbacService::saveUser(
        $superB,
        'at-rbac14-super-b@apnitokri.local',
        'Super B',
        true,
        null,
        [$invRoleId ?? 0]
    );
    ok($stripRole['ok'] === false, 'Last super-admin role assignment cannot be removed');
} else {
    echo "SKIP: last super-admin safeguards (other active super-admins in database)\n";
}
$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id IN (?, ?)')->execute([$superA, $superB]);
$pdo->prepare('DELETE FROM admin_users WHERE id IN (?, ?)')->execute([$superA, $superB]);
stage14_p1_clear_session();

stage14_p1_create_user_with_role($pdo, 'at-rbac14-inactive@apnitokri.local', 'inventory_manager', false);
ok(!AdminAuth::attempt('at-rbac14-inactive@apnitokri.local', 'TestPass1234!'), 'Inactive admin cannot authenticate');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
ok(Csrf::validateRequest() === false, 'CSRF-invalid mutations rejected');
unset($_SERVER['REQUEST_METHOD'], $_POST);

ok(AdminRbacService::systemRoleMappingsMatchDocumentation(), 'Role permission mappings match documented matrix');

stage14_p1_session($invId, 'at-rbac14-inv@apnitokri.local');
$before = Permission::canModule('payments');
Permission::clearCache();
ok($before === false, 'Unauthorized payments module denied internally');
stage14_p1_clear_session();

$sysDel = AdminRbacService::deleteRole(AdminRbacService::roleIdBySlug('order_manager') ?? 0);
ok($sysDel['ok'] === false, 'System roles cannot be deleted');

stage14_p1_session($superId, 'admin@apnitokri.local', 'Super');
$dashPerm = (int) ($pdo->query("SELECT id FROM admin_permissions WHERE slug = 'dashboard.view' LIMIT 1")->fetchColumn() ?: 0);
$custom = AdminRbacService::saveRole(null, 'AT RBAC14 Custom', 'at-rbac14-custom', 'Custom role', [$dashPerm]);
ok($custom['ok'] === true, 'Custom role created');
$customId = (int) ($custom['id'] ?? 0);
$newUser = AdminRbacService::saveUser(
    null,
    'at-rbac14-custom-user@apnitokri.local',
    'Custom User',
    true,
    'LongPassword99!',
    [$customId]
);
ok($newUser['ok'] === true, 'Custom role assigned to new user safely');
$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = (SELECT id FROM admin_users WHERE email = ? LIMIT 1)')
    ->execute(['at-rbac14-custom-user@apnitokri.local']);
$pdo->prepare('DELETE FROM admin_users WHERE email = ?')->execute(['at-rbac14-custom-user@apnitokri.local']);

$auditBefore = (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin_user.create'")->fetchColumn();
AdminRbacService::saveUser(
    null,
    'at-rbac14-audit@apnitokri.local',
    'Audit User',
    true,
    'LongPassword99!',
    [$invRoleId ?? 0]
);
$auditAfter = (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin_user.create'")->fetchColumn();
ok($auditAfter > $auditBefore, 'Audit entries written for sensitive user create');
$pdo->prepare('DELETE FROM admin_user_roles WHERE user_id IN (SELECT id FROM admin_users WHERE email LIKE ?)')->execute(['at-rbac14-audit%']);
$pdo->prepare('DELETE FROM admin_users WHERE email LIKE ?')->execute(['at-rbac14-audit%']);

stage14_p1_clear_session();
stage14_p1_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 14 Phase 1 RBAC.\n";
exit(0);
