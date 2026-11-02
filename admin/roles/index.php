<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('roles');

admin_layout_start(['pageTitle' => 'Roles & permissions', 'activeAdminNav' => 'roles']);
admin_flash_render();
$pageHeading = 'Roles & permissions';
$pageDescription = 'Permission mappings below reflect the database. System roles cannot be deleted.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/roles/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Create role</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$pdoReady = Install::isSchemaReady() && AdminAuth::check();
$roles = $pdoReady ? AdminRbacService::listRoles() : [];
$allPerms = $pdoReady ? AdminRbacService::listPermissions() : [];
$permById = [];
foreach ($allPerms as $p) {
    $permById[(int) $p['id']] = (string) ($p['slug'] ?? '');
}
?>
<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead>
            <tr>
                <th>Role</th>
                <th>System</th>
                <th>Permissions</th>
                <th class="is-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($roles as $role):
                $rid = (int) ($role['id'] ?? 0);
                $slugs = $pdoReady ? AdminRbacService::permissionSlugsForRole($rid) : [];
                if (($role['slug'] ?? '') === AdminRbacService::SUPER_ADMIN_SLUG) {
                    $permLabel = 'All permissions';
                } else {
                    $permLabel = $slugs !== [] ? implode(', ', $slugs) : '—';
                }
                ?>
                <tr>
                    <td>
                        <strong><?= Security::escape((string) ($role['name'] ?? '')) ?></strong>
                        <span class="block text-xs at-muted"><?= Security::escape((string) ($role['slug'] ?? '')) ?></span>
                    </td>
                    <td><?= (int) ($role['is_system'] ?? 0) === 1 ? '<span class="at-status at-status--info">System</span>' : '<span class="at-status at-status--neutral">Custom</span>' ?></td>
                    <td class="text-xs max-w-xl break-words"><?= Security::escape($permLabel) ?></td>
                    <td class="is-actions"><a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/admin/roles/edit.php?id=<?= $rid ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="text-sm at-muted mt-4">See <code class="text-xs">docs/rbac-permission-matrix.md</code> for system role definitions.</p>
<?php admin_layout_end();
