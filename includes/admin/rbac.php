<?php

declare(strict_types=1);

/**
 * Admin users, roles, and permission assignment (Stage 14.1).
 */
final class AdminRbacService
{
    public const SUPER_ADMIN_SLUG = 'super_admin';

    /** @var array<string, list<string>>|null */
    private static $systemRolePermissionMap = null;

    /** @return array<string, list<string>> */
    public static function documentedSystemRolePermissionMap(): array
    {
        if (self::$systemRolePermissionMap !== null) {
            return self::$systemRolePermissionMap;
        }
        self::$systemRolePermissionMap = [
            self::SUPER_ADMIN_SLUG => ['*'],
            'order_manager' => [
                'dashboard.view', 'orders.manage', 'deliveries.manage', 'customers.view',
                'support.manage', 'refunds.manage', 'payments.view',
            ],
            'inventory_manager' => [
                'dashboard.view', 'products.manage', 'categories.manage', 'inventory.manage',
            ],
            'support' => [
                'dashboard.view', 'support.manage', 'reviews.moderate', 'refunds.manage',
                'customers.view', 'faqs.manage', 'testimonials.manage',
            ],
            'finance' => [
                'dashboard.view', 'payments.view', 'refunds.manage', 'reports.view', 'reports.export',
                'coupons.manage', 'offers.manage',
            ],
        ];

        return self::$systemRolePermissionMap;
    }

    /** @return list<string> */
    public static function permissionSlugsForUser(int $userId): array
    {
        if ($userId <= 0 || !Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT p.slug FROM admin_permissions p
             INNER JOIN admin_role_permissions rp ON rp.permission_id = p.id
             INNER JOIN admin_user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?
             ORDER BY p.slug'
        );
        $stmt->execute([$userId]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'slug');
    }

    /** @return list<string> */
    public static function permissionSlugsForRole(int $roleId): array
    {
        if ($roleId <= 0 || !Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT p.slug FROM admin_permissions p
             INNER JOIN admin_role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?
             ORDER BY p.slug'
        );
        $stmt->execute([$roleId]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'slug');
    }

    /** @return list<string> */
    public static function actorPermissionSlugs(): array
    {
        $id = AdminAuth::id();

        return $id !== null ? self::permissionSlugsForUser($id) : [];
    }

    public static function actorHasPermission(string $slug): bool
    {
        return in_array($slug, self::actorPermissionSlugs(), true);
    }

    public static function actorCanGrantPermission(string $permissionSlug): bool
    {
        if (!Permission::can('roles.manage')) {
            return false;
        }

        return self::actorHasPermission($permissionSlug);
    }

    /** @param list<int> $permissionIds */
    public static function actorCanGrantPermissionIds(array $permissionIds): bool
    {
        if ($permissionIds === []) {
            return Permission::can('roles.manage');
        }
        Install::ensureConnection();
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT slug FROM admin_permissions WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($permissionIds));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!self::actorCanGrantPermission((string) $row['slug'])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $postedRoleIds */
    public static function mergeRoleAssignmentForSave(int $userId, array $postedRoleIds): array
    {
        $posted = array_values(array_unique(array_filter(array_map('intval', $postedRoleIds), static function (int $id): bool {
            return $id > 0;
        })));
        $existing = self::roleIdsForUser($userId);
        $locked = [];
        foreach ($existing as $rid) {
            if (!self::actorCanAssignRoles([$rid])) {
                $locked[] = $rid;
            }
        }
        $assignable = [];
        foreach ($posted as $rid) {
            if (self::actorCanAssignRoles([$rid])) {
                $assignable[] = $rid;
            }
        }

        return array_values(array_unique(array_merge($locked, $assignable)));
    }

    /** @param list<int> $roleIds */
    public static function actorCanAssignRoles(array $roleIds): bool
    {
        if (!Permission::can('users.manage')) {
            return false;
        }
        foreach ($roleIds as $roleId) {
            $roleId = (int) $roleId;
            if ($roleId <= 0) {
                continue;
            }
            foreach (self::permissionSlugsForRole($roleId) as $slug) {
                if (!self::actorHasPermission($slug)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function countActiveSuperAdminUsers(?int $excludeUserId = null): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        Install::ensureConnection();
        $sql = 'SELECT COUNT(DISTINCT u.id) FROM admin_users u
                INNER JOIN admin_user_roles ur ON ur.user_id = u.id
                INNER JOIN admin_roles r ON r.id = ur.role_id AND r.slug = ?
                WHERE u.is_active = 1';
        $params = [self::SUPER_ADMIN_SLUG];
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUserId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public static function userHasSuperAdminRole(int $userId): bool
    {
        if ($userId <= 0 || !Install::isSchemaReady()) {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM admin_user_roles ur
             INNER JOIN admin_roles r ON r.id = ur.role_id AND r.slug = ?
             WHERE ur.user_id = ? LIMIT 1'
        );
        $stmt->execute([self::SUPER_ADMIN_SLUG, $userId]);

        return (bool) $stmt->fetchColumn();
    }

    public static function roleIdBySlug(string $slug): ?int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT id FROM admin_roles WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @return array<string, mixed>|null */
    public static function findRoleById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM admin_roles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findUserById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT id, email, name, is_active, last_login_at, created_at FROM admin_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<int> */
    public static function roleIdsForUser(int $userId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT role_id FROM admin_user_roles WHERE user_id = ?');
        $stmt->execute([$userId]);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'role_id'));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function searchUsers(string $query, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = '1=1';
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $where = '(u.name LIKE ? OR u.email LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users u WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            "SELECT u.id, u.email, u.name, u.is_active, u.last_login_at, u.created_at
             FROM admin_users u WHERE $where ORDER BY u.name ASC, u.id ASC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['roles'] = self::roleLabelsForUser((int) $row['id']);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<string> */
    public static function roleLabelsForUser(int $userId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT r.name FROM admin_roles r
             INNER JOIN admin_user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? ORDER BY r.name'
        );
        $stmt->execute([$userId]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /** @return list<array<string, mixed>> */
    public static function listRoles(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query('SELECT id, slug, name, description, is_system FROM admin_roles ORDER BY is_system DESC, name ASC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function listPermissions(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query('SELECT id, slug, name, module FROM admin_permissions ORDER BY module, slug');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int> $roleIds
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function saveUser(
        ?int $userId,
        string $email,
        string $name,
        bool $isActive,
        ?string $newPassword,
        array $roleIds
    ): array {
        if (!Permission::can('users.manage')) {
            self::logEscalationDenied('users.save', ['reason' => 'missing users.manage']);

            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        $email = trim($email);
        $name = trim($name);
        $errors = [];
        if (!Validation::email($email)) {
            $errors['email'] = 'Valid email is required.';
        }
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($roleIds === []) {
            $errors['roles'] = 'At least one role is required.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Please fix the errors below.', 'errors' => $errors];
        }

        if (!self::actorCanAssignRoles($roleIds)) {
            self::logEscalationDenied('users.roles', ['role_ids' => $roleIds]);

            return ['ok' => false, 'message' => 'You cannot assign one or more of these roles.'];
        }

        $actorId = AdminAuth::id();
        $hadSuperAdmin = $userId !== null && self::userHasSuperAdminRole($userId);
        $willHaveSuperAdmin = false;
        foreach ($roleIds as $rid) {
            $role = self::findRoleById($rid);
            if ($role && (string) ($role['slug'] ?? '') === self::SUPER_ADMIN_SLUG) {
                $willHaveSuperAdmin = true;
                break;
            }
        }

        if ($userId !== null && $hadSuperAdmin && !$willHaveSuperAdmin) {
            $userRow = self::findUserById($userId);
            if ($userRow && (int) ($userRow['is_active'] ?? 0) === 1 && self::countActiveSuperAdminUsers($userId) === 0) {
                return ['ok' => false, 'message' => 'Cannot remove the last super-admin role assignment.'];
            }
        }

        if ($userId !== null && !$isActive && self::userHasSuperAdminRole($userId)) {
            if (self::countActiveSuperAdminUsers($userId) === 0) {
                return ['ok' => false, 'message' => 'Cannot deactivate the last active super-admin.'];
            }
        }

        if ($userId !== null && $actorId === $userId) {
            if (!$isActive) {
                return ['ok' => false, 'message' => 'You cannot deactivate your own account.'];
            }
            if (!self::actorCanAssignRoles($roleIds)) {
                self::logEscalationDenied('users.self_roles', ['role_ids' => $roleIds]);

                return ['ok' => false, 'message' => 'You cannot grant yourself higher privileges.'];
            }
        }

        try {
            return Database::transaction(static function () use (
                $userId,
                $email,
                $name,
                $isActive,
                $newPassword,
                $roleIds,
                $actorId
            ): array {
                $pdo = Database::pdo();
                if ($userId === null) {
                    if ($newPassword === null || trim($newPassword) === '') {
                        return ['ok' => false, 'message' => 'Password is required for new users.', 'errors' => ['password' => 'Required.']];
                    }
                    if (strlen($newPassword) < 10) {
                        return ['ok' => false, 'message' => 'Password must be at least 10 characters.', 'errors' => ['password' => 'Too short.']];
                    }
                    $dup = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
                    $dup->execute([$email]);
                    if ($dup->fetchColumn()) {
                        return ['ok' => false, 'message' => 'Email already in use.', 'errors' => ['email' => 'Duplicate.']];
                    }
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare(
                        'INSERT INTO admin_users (email, password_hash, name, is_active) VALUES (?, ?, ?, ?)'
                    );
                    $ins->execute([$email, $hash, $name, $isActive ? 1 : 0]);
                    $userId = (int) $pdo->lastInsertId();
                    AuditLog::write('admin_user.create', 'admin_user', (string) $userId, ['email' => $email]);
                } else {
                    $existing = self::findUserById($userId);
                    if ($existing === null) {
                        return ['ok' => false, 'message' => 'User not found.'];
                    }
                    $dup = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? AND id <> ? LIMIT 1');
                    $dup->execute([$email, $userId]);
                    if ($dup->fetchColumn()) {
                        return ['ok' => false, 'message' => 'Email already in use.', 'errors' => ['email' => 'Duplicate.']];
                    }
                    $pdo->prepare('UPDATE admin_users SET email = ?, name = ?, is_active = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$email, $name, $isActive ? 1 : 0, $userId]);
                    if ($newPassword !== null && trim($newPassword) !== '') {
                        if (strlen($newPassword) < 10) {
                            return ['ok' => false, 'message' => 'Password must be at least 10 characters.', 'errors' => ['password' => 'Too short.']];
                        }
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $pdo->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                            ->execute([$hash, $userId]);
                        AuditLog::write('admin_user.password_reset', 'admin_user', (string) $userId);
                    }
                    AuditLog::write('admin_user.update', 'admin_user', (string) $userId, [
                        'email' => $email,
                        'is_active' => $isActive,
                    ]);
                }

                $pdo->prepare('DELETE FROM admin_user_roles WHERE user_id = ?')->execute([$userId]);
                $link = $pdo->prepare('INSERT INTO admin_user_roles (user_id, role_id) VALUES (?, ?)');
                foreach ($roleIds as $rid) {
                    $link->execute([$userId, $rid]);
                }
                AuditLog::write('admin_user.roles', 'admin_user', (string) $userId, ['role_ids' => $roleIds]);

                if ($actorId === $userId) {
                    Permission::clearCache();
                }

                return ['ok' => true, 'id' => $userId];
            });
        } catch (Throwable $e) {
            error_log('AdminRbacService::saveUser: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save user.'];
        }
    }

    /**
     * @param list<int> $permissionIds
     * @return array{ok: true, id: int}|array{ok: false, message: string}
     */
    public static function saveRole(
        ?int $roleId,
        string $name,
        string $slug,
        ?string $description,
        array $permissionIds
    ): array {
        if (!Permission::can('roles.manage')) {
            self::logEscalationDenied('roles.save', ['reason' => 'missing roles.manage']);

            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        $name = trim($name);
        $slug = strtolower(trim($slug));
        $description = $description !== null ? trim($description) : null;
        if ($name === '') {
            return ['ok' => false, 'message' => 'Role name is required.'];
        }
        if ($roleId === null && !Validation::slug($slug)) {
            return ['ok' => false, 'message' => 'Invalid role slug (lowercase letters, numbers, hyphens).'];
        }

        $existing = $roleId !== null ? self::findRoleById($roleId) : null;
        if ($roleId !== null && $existing === null) {
            return ['ok' => false, 'message' => 'Role not found.'];
        }
        $isSystem = $existing !== null && (int) ($existing['is_system'] ?? 0) === 1;
        $roleSlug = $existing !== null ? (string) ($existing['slug'] ?? '') : $slug;

        if ($roleSlug === self::SUPER_ADMIN_SLUG) {
            return ['ok' => false, 'message' => 'Super-admin permissions are managed by the system and cannot be edited here.'];
        }

        $permissionIds = array_values(array_unique(array_filter(array_map('intval', $permissionIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!self::actorCanGrantPermissionIds($permissionIds)) {
            self::logEscalationDenied('roles.permissions', ['permission_ids' => $permissionIds]);

            return ['ok' => false, 'message' => 'You cannot grant permissions you do not hold.'];
        }

        try {
            return Database::transaction(static function () use (
                $roleId,
                $name,
                $slug,
                $description,
                $permissionIds,
                $existing,
                $isSystem
            ): array {
                $pdo = Database::pdo();
                if ($roleId === null) {
                    $dup = $pdo->prepare('SELECT id FROM admin_roles WHERE slug = ? LIMIT 1');
                    $dup->execute([$slug]);
                    if ($dup->fetchColumn()) {
                        return ['ok' => false, 'message' => 'Role slug already exists.'];
                    }
                    $pdo->prepare(
                        'INSERT INTO admin_roles (slug, name, description, is_system) VALUES (?, ?, ?, 0)'
                    )->execute([$slug, $name, $description]);
                    $roleId = (int) $pdo->lastInsertId();
                    AuditLog::write('admin_role.create', 'admin_role', (string) $roleId, ['slug' => $slug]);
                } else {
                    if (!$isSystem) {
                        $pdo->prepare('UPDATE admin_roles SET name = ?, description = ? WHERE id = ?')
                            ->execute([$name, $description, $roleId]);
                    } else {
                        $pdo->prepare('UPDATE admin_roles SET description = ? WHERE id = ?')
                            ->execute([$description, $roleId]);
                    }
                    AuditLog::write('admin_role.update', 'admin_role', (string) $roleId, ['name' => $name]);
                }

                $pdo->prepare('DELETE FROM admin_role_permissions WHERE role_id = ?')->execute([$roleId]);
                if ($permissionIds !== []) {
                    $ins = $pdo->prepare('INSERT INTO admin_role_permissions (role_id, permission_id) VALUES (?, ?)');
                    foreach ($permissionIds as $pid) {
                        $ins->execute([$roleId, $pid]);
                    }
                }
                AuditLog::write('admin_role.permissions', 'admin_role', (string) $roleId, [
                    'permission_ids' => $permissionIds,
                ]);
                Permission::clearCache();

                return ['ok' => true, 'id' => $roleId];
            });
        } catch (Throwable $e) {
            error_log('AdminRbacService::saveRole: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save role.'];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function deleteRole(int $roleId): array
    {
        if (!Permission::can('roles.manage')) {
            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        $role = self::findRoleById($roleId);
        if ($role === null) {
            return ['ok' => false, 'message' => 'Role not found.'];
        }
        if ((int) ($role['is_system'] ?? 0) === 1) {
            return ['ok' => false, 'message' => 'System roles cannot be deleted.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM admin_user_roles WHERE role_id = ?');
        $stmt->execute([$roleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Remove this role from all users before deleting.'];
        }
        Database::pdo()->prepare('DELETE FROM admin_roles WHERE id = ? AND is_system = 0')->execute([$roleId]);
        AuditLog::write('admin_role.delete', 'admin_role', (string) $roleId, ['slug' => $role['slug'] ?? '']);

        return ['ok' => true];
    }

    /** Verify seeded system roles include expected permission slugs (for tests). */
    public static function systemRoleMappingsMatchDocumentation(): bool
    {
        $map = self::documentedSystemRolePermissionMap();
        foreach ($map as $roleSlug => $expectedSlugs) {
            if ($roleSlug === self::SUPER_ADMIN_SLUG) {
                continue;
            }
            $roleId = self::roleIdBySlug($roleSlug);
            if ($roleId === null) {
                return false;
            }
            $actual = self::permissionSlugsForRole($roleId);
            sort($expectedSlugs);
            $sortedActual = $actual;
            sort($sortedActual);
            if ($expectedSlugs !== $sortedActual) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $detail */
    private static function logEscalationDenied(string $context, array $detail): void
    {
        AuditLog::write('rbac.escalation_denied', null, null, array_merge(['context' => $context], $detail));
    }
}
