<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('users');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$user = ($useDb && $id) ? AdminRbacService::findUserById($id) : null;
if ($id && $user === null && $useDb) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'User not found', 'activeAdminNav' => 'users']);
    echo '<div class="at-alert at-alert--error">User not found.</div>';
    admin_layout_end();
    exit;
}

$allRoles = $useDb ? AdminRbacService::listRoles() : [];
$selectedRoleIds = ($useDb && $id) ? AdminRbacService::roleIdsForUser($id) : [];

$form = [
    'email' => (string) ($user['email'] ?? ''),
    'name' => (string) ($user['name'] ?? ''),
    'is_active' => !isset($user) || !empty($user['is_active']),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('User saved (demo UI).');
        header('Location: ' . url_base() . '/admin/users/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $roleIds = isset($_POST['role_ids']) && is_array($_POST['role_ids'])
        ? array_map('intval', $_POST['role_ids']) : [];
    if ($postId) {
        $roleIds = AdminRbacService::mergeRoleAssignmentForSave($postId, $roleIds);
    }
    $newPassword = trim((string) ($_POST['password'] ?? ''));
    $result = AdminRbacService::saveUser(
        $postId,
        trim((string) ($_POST['email'] ?? '')),
        trim((string) ($_POST['name'] ?? '')),
        !empty($_POST['is_active']),
        $newPassword !== '' ? $newPassword : null,
        $roleIds
    );
    if ($result['ok']) {
        admin_flash_set($postId ? 'User updated.' : 'User created.');
        header('Location: ' . url_base() . '/admin/users/edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
    admin_flash_set($result['message'] ?? 'Could not save.', 'error');
    $errors = $result['errors'] ?? [];
    $form = [
        'email' => trim((string) ($_POST['email'] ?? '')),
        'name' => trim((string) ($_POST['name'] ?? '')),
        'is_active' => !empty($_POST['is_active']),
    ];
    $selectedRoleIds = $roleIds;
}

admin_layout_start(['pageTitle' => $id ? 'Edit admin user' : 'New admin user', 'activeAdminNav' => 'users']);
admin_flash_render();
$pageHeading = $id ? 'Edit admin user' : 'New admin user';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div>
            <label class="at-label" for="name">Name</label>
            <input id="name" name="name" class="at-input at-focusable" required value="<?= Security::escape($form['name']) ?>">
            <?php if (isset($errors['name'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['name']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="at-input at-focusable" required autocomplete="off" value="<?= Security::escape($form['email']) ?>">
            <?php if (isset($errors['email'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['email']) ?></p><?php endif; ?>
        </div>
        <div>
            <label class="at-label" for="password"><?= $id ? 'New password (leave blank to keep)' : 'Password' ?></label>
            <input id="password" name="password" type="password" class="at-input at-focusable" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
            <?php if (isset($errors['password'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['password']) ?></p><?php endif; ?>
        </div>
        <label class="flex gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>>
            Active
        </label>
        <fieldset>
            <legend class="at-label">Roles</legend>
            <div class="space-y-2 mt-2">
                <?php foreach ($allRoles as $role):
                    $rid = (int) ($role['id'] ?? 0);
                    if ($rid <= 0) {
                        continue;
                    }
                    $canAssign = AdminRbacService::actorCanAssignRoles([$rid]);
                    $checked = in_array($rid, $selectedRoleIds, true);
                    $locked = $id && $checked && !$canAssign;
                    ?>
                    <?php if ($locked): ?>
                        <input type="hidden" name="role_ids[]" value="<?= $rid ?>">
                    <?php endif; ?>
                    <label class="flex gap-2 text-sm <?= $canAssign || $locked ? '' : 'opacity-50' ?>">
                        <input type="checkbox" name="role_ids[]" value="<?= $rid ?>"
                            <?= $checked ? 'checked' : '' ?>
                            <?= ($canAssign && !$locked) ? '' : 'disabled' ?>>
                        <?= Security::escape((string) ($role['name'] ?? '')) ?>
                        <?php if ((int) ($role['is_system'] ?? 0) === 1): ?><span class="at-muted text-xs">(system)</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (isset($errors['roles'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['roles']) ?></p><?php endif; ?>
        </fieldset>
        <div class="flex gap-2 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/users/index.php" class="at-btn at-btn--ghost at-focusable">Back</a>
        </div>
    </div>
</form>
<?php admin_layout_end();
