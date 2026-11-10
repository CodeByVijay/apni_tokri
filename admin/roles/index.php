<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('roles');

$listSelf = url_base() . '/admin/roles/index.php';
admin_process_list_delete('roles', $listSelf);

admin_layout_start(['pageTitle' => 'Roles & permissions', 'activeAdminNav' => 'roles']);
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
                $roleName = (string) ($role['name'] ?? '');
                $roleSlug = (string) ($role['slug'] ?? '');
                $isSystem = AdminRbacService::isSystemRoleRow($role);
                $slugs = $pdoReady ? AdminRbacService::permissionSlugsForRole($rid) : [];
                if ($roleSlug === AdminRbacService::SUPER_ADMIN_SLUG) {
                    $permLabel = 'All permissions';
                } else {
                    $permLabel = $slugs !== [] ? implode(', ', $slugs) : '—';
                }
                $editHref = url_base() . '/admin/roles/edit.php?id=' . $rid;
                $actions = admin_action_edit_link($editHref, 'Edit');
                if (!$isSystem) {
                    $actions .= admin_role_delete_button($rid, $roleName, $roleSlug);
                }
                ?>
                <tr>
                    <td>
                        <strong><?= Security::escape($roleName) ?></strong>
                        <span class="block text-xs at-muted"><?= Security::escape($roleSlug) ?></span>
                    </td>
                    <td><?= $isSystem ? '<span class="at-status at-status--info">System</span>' : '<span class="at-status at-status--neutral">Custom</span>' ?></td>
                    <td class="text-xs max-w-xl break-words"><?= Security::escape($permLabel) ?></td>
                    <td class="is-actions"><span class="at-admin-actions"><?= $actions ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="text-sm at-muted mt-4">See <code class="text-xs">docs/rbac-permission-matrix.md</code> for system role definitions.</p>

<div id="at-admin-role-delete-modal" class="at-modal-backdrop" aria-hidden="true" role="presentation">
    <div class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-admin-role-delete-title">
        <form method="post" id="at-admin-role-delete-form">
            <div class="at-modal__header">
                <h2 id="at-admin-role-delete-title" class="at-modal__title">Delete role?</h2>
                <button type="button" class="at-close at-focusable" data-at-admin-role-delete-close aria-label="Close"><span aria-hidden="true">×</span></button>
            </div>
            <div class="at-modal__body space-y-2 text-sm">
                <p class="m-0 at-muted">This action cannot be undone. Users must be reassigned before a role with assignments can be removed.</p>
                <dl class="m-0 space-y-1">
                    <dt class="at-muted text-xs">Role name</dt>
                    <dd class="m-0 font-semibold" id="at-admin-role-delete-name"></dd>
                    <dt class="at-muted text-xs mt-2">Role slug</dt>
                    <dd class="m-0"><code id="at-admin-role-delete-slug"></code></dd>
                </dl>
            </div>
            <div class="at-modal__footer flex flex-wrap gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="admin_delete" value="1">
                <input type="hidden" name="entity" value="admin_role">
                <input type="hidden" name="record_id" value="" id="at-admin-role-delete-id">
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-admin-role-delete-close>Cancel</button>
                <button type="submit" class="at-btn at-btn--danger at-focusable" id="at-admin-role-delete-submit">Delete role</button>
            </div>
        </form>
    </div>
</div>
<script src="<?= Security::escape(asset_url('assets/js/admin-roles-list.js')) ?>"></script>
<?php admin_layout_end();
