<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('categories');

$useDb = Install::isSchemaReady() && AdminAuth::check();

admin_layout_start(['pageTitle' => 'Categories', 'activeAdminNav' => 'categories']);
admin_flash_render();
$pageHeading = 'Categories';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/categories/edit.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Add category</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$columns = [
    ['label' => 'Name', 'key' => 'name'],
    ['label' => 'Slug', 'key' => 'slug'],
    ['label' => 'Homepage', 'key' => 'home'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
$rows = [];

if ($useDb) {
    foreach (CategoryRepository::allForAdmin() as $c) {
        $rows[] = [
            'name' => (string) $c['name'],
            'slug' => (string) $c['slug'],
            'home' => !empty($c['show_on_homepage']) ? 'Yes' : 'No',
            'status' => (string) ($c['status'] ?? ''),
            'link' => 'Edit',
            'action_href' => url_base() . '/admin/categories/edit.php?id=' . (int) $c['id'],
        ];
    }
} else {
    foreach (demo_catalogue()->categories() as $c) {
        $rows[] = [
            'name' => $c['name'],
            'slug' => $c['slug'],
            'home' => 'Yes',
            'status' => 'published (demo)',
            'link' => 'Edit',
        ];
    }
}

require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($useDb) {
    echo '<p class="text-xs at-muted mt-2">Live categories from database.</p>';
} else {
    echo '<p class="text-xs at-muted mt-2">Sign in after schema install to manage categories in the database.</p>';
}
admin_layout_end();
