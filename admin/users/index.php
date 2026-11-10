<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/icons.php';

AdminDemoPerms::denyIfCannot('users');

$listSelf = url_base() . '/admin/users/index.php';
admin_process_list_delete('users', $listSelf);

$useDb = Install::isSchemaReady() && AdminAuth::check();
$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;

admin_layout_start(['pageTitle' => 'Admin users', 'activeAdminNav' => 'users']);
$pageHeading = 'Admin users';
$pageDescription = 'Roles control module access server-side. Passwords are never shown.';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/users/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add user</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$base = $listSelf;
$hasSearch = $q !== '';
?>
<form method="get" class="at-admin-card mb-4 at-admin-users-search" role="search">
    <div class="at-admin-card__body at-admin-users-search__row">
        <div class="at-admin-users-search__field">
            <label class="at-label at-admin-users-search__label" for="admin-users-q">Search admin users</label>
            <div class="at-admin-users-search__input-wrap">
                <?= at_icon('search', 'at-admin-users-search__icon') ?>
                <input id="admin-users-q" type="search" name="q" value="<?= Security::escape($q) ?>"
                       placeholder="Search name or email…" class="at-input at-focusable at-admin-users-search__input"
                       autocomplete="off">
            </div>
        </div>
        <div class="at-admin-users-search__actions">
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
            <?php if ($hasSearch): ?>
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($listSelf) ?>">Clear</a>
            <?php else: ?>
                <span class="at-btn at-btn--ghost at-admin-users-search__clear--disabled" aria-disabled="true">Clear</span>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php
if (!$useDb) {
    echo '<div class="at-alert at-alert--info">Database not ready.</div>';
    admin_layout_end();
    exit;
}

$result = AdminRbacService::searchUsers($q, $page, $perPage);
$rows = [];
foreach ($result['rows'] as $u) {
    $uid = (int) $u['id'];
    $editHref = url_base() . '/admin/users/edit.php?id=' . $uid;
    $name = (string) ($u['name'] ?? '');
    $email = (string) ($u['email'] ?? '');
    $isSuperAdmin = AdminRbacService::userHasSuperAdminRole($uid);
    if ($isSuperAdmin) {
        $actions = '<span class="at-admin-actions">' . admin_action_edit_link($editHref, 'Edit') . '</span>';
    } else {
        $actions = '<span class="at-admin-actions">'
            . admin_action_edit_link($editHref, 'Edit')
            . admin_user_delete_form($uid, $name, $email, $page, $perPage, $q)
            . '</span>';
    }
    $rows[] = [
        'name' => $name,
        'email' => $email,
        'role' => implode(', ', (array) ($u['roles'] ?? [])),
        'active' => !empty($u['is_active']) ? 'active' : 'inactive',
        'actions' => $actions,
    ];
}
$columns = [
    ['label' => 'Name', 'key' => 'name'],
    ['label' => 'Email', 'key' => 'email'],
    ['label' => 'Roles', 'key' => 'role'],
    ['label' => 'Active', 'key' => 'active'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';

$total = (int) ($result['total'] ?? 0);
$pages = (int) max(1, ceil($total / $perPage));
if ($pages > 1) {
    echo '<p class="mt-4 text-sm">';
    if ($page > 1) {
        echo '<a class="underline" href="' . Security::escape($base) . '?q=' . rawurlencode($q) . '&page=' . ($page - 1) . '">Previous</a> ';
    }
    echo 'Page ' . (int) $page . ' of ' . $pages;
    if ($page < $pages) {
        echo ' <a class="underline" href="' . Security::escape($base) . '?q=' . rawurlencode($q) . '&page=' . ($page + 1) . '">Next</a>';
    }
    echo '</p>';
}
admin_layout_end();
