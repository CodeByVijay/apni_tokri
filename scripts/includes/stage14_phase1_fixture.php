<?php

declare(strict_types=1);

function stage14_p1_cleanup(PDO $pdo): void
{
    $emails = [
        'at-rbac14-inv@apnitokri.local',
        'at-rbac14-super@apnitokri.local',
        'at-rbac14-inactive@apnitokri.local',
        'at-rbac14-super-a@apnitokri.local',
        'at-rbac14-super-b@apnitokri.local',
        'at-rbac14-custom-user@apnitokri.local',
        'at-rbac14-audit@apnitokri.local',
    ];
    foreach ($emails as $email) {
        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $uid = (int) ($stmt->fetchColumn() ?: 0);
        if ($uid > 0) {
            $pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$uid]);
        }
    }
    $pdo->exec("DELETE FROM admin_role_permissions WHERE role_id IN (SELECT id FROM admin_roles WHERE slug = 'at-rbac14-custom')");
    $pdo->exec("DELETE FROM admin_user_roles WHERE role_id IN (SELECT id FROM admin_roles WHERE slug = 'at-rbac14-custom')");
    $pdo->exec("DELETE FROM admin_roles WHERE slug IN ('at-rbac14-custom', 'at-rbac14-custom-bad')");
}

function stage14_p1_session(int $adminUserId, string $email = '', string $name = 'Test'): void
{
    $_SESSION['admin_user_id'] = $adminUserId;
    $_SESSION['admin_user_email'] = $email;
    $_SESSION['admin_user_name'] = $name;
    Permission::clearCache();
}

function stage14_p1_clear_session(): void
{
    unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name']);
    Permission::clearCache();
}

function stage14_p1_create_user_with_role(PDO $pdo, string $email, string $roleSlug, bool $active = true): int
{
    $hash = password_hash('TestPass1234!', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO admin_users (email, password_hash, name, is_active) VALUES (?, ?, ?, ?)')
        ->execute([$email, $hash, 'AT RBAC14', $active ? 1 : 0]);
    $uid = (int) $pdo->lastInsertId();
    $rid = AdminRbacService::roleIdBySlug($roleSlug);
    if ($rid !== null) {
        $pdo->prepare('INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$uid, $rid]);
    }

    return $uid;
}

function stage14_p1_super_admin_id(PDO $pdo): int
{
    return (int) ($pdo->query(
        "SELECT u.id FROM admin_users u
         INNER JOIN admin_user_roles ur ON ur.user_id = u.id
         INNER JOIN admin_roles r ON r.id = ur.role_id AND r.slug = 'super_admin'
         WHERE u.is_active = 1 ORDER BY u.id LIMIT 1"
    )->fetchColumn() ?: 0);
}
