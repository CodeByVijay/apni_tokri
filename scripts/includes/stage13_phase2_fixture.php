<?php

declare(strict_types=1);

require_once __DIR__ . '/stage13_phase1_fixture.php';

function stage13_p2_cleanup(PDO $pdo): void
{
    stage13_p1_cleanup($pdo);
}

function stage13_p2_admin_with_cms(PDO $pdo): int
{
    $id = (int) ($pdo->query(
        "SELECT u.id FROM admin_users u
         INNER JOIN admin_user_roles ur ON ur.user_id = u.id
         INNER JOIN admin_role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN admin_permissions p ON p.id = rp.permission_id
         WHERE u.is_active = 1 AND p.slug = 'cms.manage'
         ORDER BY u.id LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($id <= 0) {
        $id = (int) ($pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    }

    return $id;
}

function stage13_p2_admin_session(int $adminUserId): void
{
    $_SESSION['admin_user_id'] = $adminUserId;
    Permission::clearCache();
}

function stage13_p2_clear_admin_session(): void
{
    unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name']);
    Permission::clearCache();
}
