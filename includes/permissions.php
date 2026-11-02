<?php

declare(strict_types=1);

final class Permission
{
    /** @var array<string, bool>|null */
    private static $cache = null;

    /** Map admin nav module slug => permission slug prefix/module check */
    private static function modulePermission(string $module): string
    {
        $map = [
            'dashboard' => 'dashboard.view',
            'orders' => 'orders.manage',
            'products' => 'products.manage',
            'categories' => 'categories.manage',
            'inventory' => 'inventory.manage',
            'customers' => 'customers.view',
            'subscriptions' => 'subscriptions.manage',
            'deliveries' => 'deliveries.manage',
            'coupons' => 'coupons.manage',
            'offers' => 'offers.manage',
            'homepage' => 'homepage.manage',
            'faqs' => 'faqs.manage',
            'cms' => 'cms.manage',
            'reviews' => 'reviews.moderate',
            'testimonials' => 'testimonials.manage',
            'support' => 'support.manage',
            'refunds' => 'refunds.manage',
            'payments' => 'payments.view',
            'seo' => 'seo.manage',
            'marketing' => 'marketing.manage',
            'reports' => 'reports.view',
            'settings' => 'settings.manage',
            'notifications' => 'notifications.manage',
            'users' => 'users.manage',
            'roles' => 'roles.manage',
        ];

        return $map[$module] ?? $module;
    }

    public static function can(string $permissionSlug): bool
    {
        if (!AdminAuth::check() || !Install::isSchemaReady()) {
            return false;
        }
        self::loadCache();

        return !empty(self::$cache[$permissionSlug]);
    }

    public static function canModule(string $module): bool
    {
        return self::can(self::modulePermission($module));
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    private static function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $userId = AdminAuth::id();
        if ($userId === null) {
            return;
        }
        $sql = 'SELECT p.slug FROM admin_permissions p
                INNER JOIN admin_role_permissions rp ON rp.permission_id = p.id
                INNER JOIN admin_user_roles ur ON ur.role_id = rp.role_id
                WHERE ur.user_id = ?';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$userId]);
        while ($row = $stmt->fetch()) {
            self::$cache[(string) $row['slug']] = true;
        }
    }
}
