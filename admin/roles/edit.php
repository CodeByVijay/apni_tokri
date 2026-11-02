<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('roles');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$role = ($useDb && $id) ? AdminRbacService::findRoleById($id) : null;
if ($id && $role === null && $useDb) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'Role not found', 'activeAdminNav' => 'roles']);
    echo '<div class="at-alert at-alert--error">Role not found.</div>';
    admin_layout_end();
    exit;
}

$isSystem = $role !== null && (int) ($role['is_system'] ?? 0) === 1;
$isSuperAdmin = $role !== null && (string) ($role['slug'] ?? '') === AdminRbacService::SUPER_ADMIN_SLUG;
$allPerms = $useDb ? AdminRbacService::listPermissions() : [];
$selectedPermIds = [];
if ($useDb && $id) {
    foreach ($allPerms as $p) {
        if (in_array((string) ($p['slug'] ?? ''), AdminRbacService::permissionSlugsForRole($id), true)) {
            $selectedPermIds[] = (int) $p['id'];
        }
    }
}

$form = [
    'name' => (string) ($role['name'] ?? ''),
    'slug' => (string) ($role['slug'] ?? ''),
    'description' => (string) ($role['description'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('Role saved (demo UI).');
        header('Location: ' . url_base() . '/admin/roles/index.php', true, 302);
        exit;
    }
    $action = (string) ($_POST['form_action'] ?? 'save');
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    if ($action === 'delete' && $postId) {
        $del = AdminRbacService::deleteRole($postId);
        admin_flash_set($del['ok'] ? 'Role deleted.' : ($del['message'] ?? 'Could not delete.'), $del['ok'] ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/roles/index.php', true, 302);
        exit;
    }
    $permIds = isset($_POST['permission_ids']) && is_array($_POST['permission_ids'])
        ? array_map('intval', $_POST['permission_ids']) : [];
    $result = AdminRbacService::saveRole(
        $postId,
        trim((string) ($_POST['name'] ?? '')),
        trim((string) ($_POST['slug'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        $permIds
    );
    if ($result['ok']) {
        admin_flash_set($postId ? 'Role updated.' : 'Role created.');
        header('Location: ' . url_base() . '/admin/roles/edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
    admin_flash_set($result['message'] ?? 'Could not save.', 'error');
    $form = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'slug' => trim((string) ($_POST['slug'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
    ];
    $selectedPermIds = $permIds;
}

admin_layout_start(['pageTitle' => $id ? 'Edit role' : 'New role', 'activeAdminNav' => 'roles']);
admin_flash_render();
$pageHeading = $id ? 'Edit role' : 'New role';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="save">
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div>
            <label class="at-label" for="name">Name</label>
            <input id="name" name="name" class="at-input at-focusable" required value="<?= Security::escape($form['name']) ?>" <?= $isSystem && $isSuperAdmin ? '' : '' ?>>
        </div>
        <?php if (!$id): ?>
            <div>
                <label class="at-label" for="slug">Slug</label>
                <input id="slug" name="slug" class="at-input at-focusable" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= Security::escape($form['slug']) ?>">
            </div>
        <?php else: ?>
            <p class="text-sm">Slug: <code><?= Security::escape($form['slug']) ?></code></p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="description">Description</label>
            <input id="description" name="description" class="at-input at-focusable" value="<?= Security::escape($form['description']) ?>">
        </div>
        <?php if ($isSuperAdmin): ?>
            <div class="at-alert at-alert--info text-sm">Super-admin holds all permissions via the system seed. Permissions cannot be edited here.</div>
        <?php else: ?>
            <fieldset>
                <legend class="at-label">Permissions (you can only grant permissions you hold)</legend>
                <div class="grid sm:grid-cols-2 gap-2 mt-2 max-h-96 overflow-y-auto">
                    <?php
                    $byModule = [];
                    foreach ($allPerms as $p) {
                        $byModule[(string) ($p['module'] ?? 'other')][] = $p;
                    }
                    ksort($byModule);
                    foreach ($byModule as $mod => $perms): ?>
                        <div class="col-span-2 font-semibold text-sm mt-2"><?= Security::escape($mod) ?></div>
                        <?php foreach ($perms as $p):
                            $pid = (int) ($p['id'] ?? 0);
                            $canGrant = AdminRbacService::actorCanGrantPermission((string) ($p['slug'] ?? ''));
                            ?>
                            <label class="flex gap-2 text-sm <?= $canGrant ? '' : 'opacity-50' ?>">
                                <input type="checkbox" name="permission_ids[]" value="<?= $pid ?>"
                                    <?= in_array($pid, $selectedPermIds, true) ? 'checked' : '' ?>
                                    <?= $canGrant ? '' : 'disabled' ?>>
                                <?= Security::escape((string) ($p['slug'] ?? '')) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>
        <div class="flex flex-wrap gap-2 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/roles/index.php" class="at-btn at-btn--ghost at-focusable">Back</a>
            <?php if ($id && !$isSystem): ?>
                <button type="submit" name="form_action" value="delete" class="at-btn at-btn--ghost at-focusable text-red-700 ml-auto"
                    onclick="return confirm('Delete this role?');">Delete role</button>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php admin_layout_end();
