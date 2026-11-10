<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

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
?>
<form method="get" class="at-search max-w-md mb-4">
    <input type="search" name="q" value="<?= Security::escape($q) ?>" placeholder="Search name or email…" class="at-input at-focusable">
    <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Search</button>
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
