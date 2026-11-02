<?php

declare(strict_types=1);

function stage13_p3_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            "DELETE f FROM faqs f
             INNER JOIN faq_categories c ON c.id = f.category_id
             WHERE c.slug LIKE 'at-faq13-%' OR f.question LIKE 'AT-FAQ13-%'"
        );
        $pdo->exec("DELETE FROM faqs WHERE question LIKE 'AT-FAQ13-%'");
        $pdo->exec("DELETE FROM faq_categories WHERE slug LIKE 'at-faq13-%'");
    });
}

function stage13_p3_admin_session(int $adminUserId): void
{
    $_SESSION['admin_user_id'] = $adminUserId;
    Permission::clearCache();
}

function stage13_p3_clear_admin_session(): void
{
    unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name']);
    Permission::clearCache();
}

function stage13_p3_admin_with_faqs(PDO $pdo): int
{
    return (int) ($pdo->query(
        "SELECT u.id FROM admin_users u
         INNER JOIN admin_user_roles ur ON ur.user_id = u.id
         INNER JOIN admin_role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN admin_permissions p ON p.id = rp.permission_id
         WHERE u.is_active = 1 AND p.slug = 'faqs.manage'
         ORDER BY u.id LIMIT 1"
    )->fetchColumn() ?: (int) ($pdo->query(
        'SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1'
    )->fetchColumn() ?: 0));
}
