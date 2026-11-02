<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('faqs');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = ($useDb && $editId > 0) ? FaqRepository::findCategoryById($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $useDb) {
    Csrf::requireValid();
    $action = (string) ($_POST['form_action'] ?? 'save');
    if ($action === 'delete') {
        $delId = (int) ($_POST['category_id'] ?? 0);
        $result = FaqService::deleteCategory($delId);
        admin_flash_set($result['ok'] ? 'Category deleted.' : ($result['message'] ?? 'Could not delete.'), $result['ok'] ? 'success' : 'error');
    } elseif ($action === 'toggle_active') {
        $catId = (int) ($_POST['category_id'] ?? 0);
        $row = FaqRepository::findCategoryById($catId);
        if ($row !== null) {
            $result = FaqService::setCategoryActive($catId, empty($row['is_active']));
            admin_flash_set($result['ok'] ? 'Category updated.' : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
        }
    } else {
        $postId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null;
        $input = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_active' => !empty($_POST['is_active']),
        ];
        $result = $postId ? FaqService::updateCategory($postId, $input) : FaqService::createCategory($input);
        admin_flash_set($result['ok'] ? 'Category saved.' : ($result['message'] ?? 'Could not save.'), $result['ok'] ? 'success' : 'error');
        if ($result['ok'] && !$postId) {
            header('Location: ' . url_base() . '/admin/faqs/categories.php?edit=' . (int) $result['id'], true, 302);
            exit;
        }
    }
    header('Location: ' . url_base() . '/admin/faqs/categories.php' . ($editId ? '?edit=' . $editId : ''), true, 302);
    exit;
}

$form = [
    'name' => (string) ($editRow['name'] ?? ''),
    'slug' => (string) ($editRow['slug'] ?? ''),
    'sort_order' => (int) ($editRow['sort_order'] ?? 0),
    'is_active' => !$editRow || !empty($editRow['is_active']),
];
$categories = $useDb ? FaqRepository::listCategoriesForAdmin(true) : [];

admin_layout_start(['pageTitle' => 'FAQ categories', 'activeAdminNav' => 'faqs']);
admin_flash_render();
$pageHeading = 'FAQ categories';
$pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/faqs/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">All FAQs</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<div class="grid lg:grid-cols-2 gap-6">
    <form method="post" class="at-card">
        <div class="at-card__body space-y-3">
            <h3 class="font-bold"><?= $editRow ? 'Edit category' : 'New category' ?></h3>
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="save">
            <?php if ($editRow): ?><input type="hidden" name="category_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div>
                <label class="at-label" for="name">Name</label>
                <input id="name" name="name" class="at-input at-focusable w-full" required maxlength="191"
                       value="<?= Security::escape($form['name']) ?>">
            </div>
            <div>
                <label class="at-label" for="slug">Slug</label>
                <input id="slug" name="slug" class="at-input at-focusable w-full" maxlength="191"
                       placeholder="Auto from name if empty"
                       value="<?= Security::escape($form['slug']) ?>">
            </div>
            <div>
                <label class="at-label" for="sort_order">Sort order</label>
                <input id="sort_order" name="sort_order" type="number" class="at-input at-focusable w-32"
                       value="<?= (int) $form['sort_order'] ?>">
            </div>
            <label class="flex gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>>
                Active
            </label>
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save category</button>
            <?php if ($editRow): ?>
                <a href="<?= Security::escape(url_base()) ?>/admin/faqs/categories.php" class="at-btn at-btn--ghost at-focusable">New instead</a>
            <?php endif; ?>
        </div>
    </form>
    <div>
        <?php
        $columns = [
            ['label' => 'Name', 'key' => 'name'],
            ['label' => 'Slug', 'key' => 'slug'],
            ['label' => 'Order', 'key' => 'sort_order', 'align' => 'num'],
            ['label' => 'Status', 'key' => 'status'],
            ['label' => '', 'key' => 'link', 'align' => 'actions'],
        ];
        $rows = [];
        foreach ($categories as $c) {
            $cid = (int) ($c['id'] ?? 0);
            $rows[] = [
                'name' => (string) ($c['name'] ?? ''),
                'slug' => (string) ($c['slug'] ?? ''),
                'sort_order' => (string) ($c['sort_order'] ?? '0'),
                'status' => !empty($c['is_active']) ? 'active' : 'inactive',
                'link' => 'Edit',
                'action_href' => url_base() . '/admin/faqs/categories.php?edit=' . $cid,
                'cid' => $cid,
            ];
        }
        require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
        ?>
        <?php if ($useDb && $rows !== []): ?>
            <div class="mt-3 space-y-2">
                <?php foreach ($rows as $r):
                    $cid = (int) ($r['cid'] ?? 0);
                    if ($cid <= 0) {
                        continue;
                    }
                    ?>
                <form method="post" class="inline-flex gap-2 text-sm">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="category_id" value="<?= $cid ?>">
                    <button type="submit" name="form_action" value="toggle_active" class="at-btn at-btn--ghost at-btn--sm at-focusable">Toggle active · <?= Security::escape($r['name']) ?></button>
                    <button type="submit" name="form_action" value="delete" class="at-btn at-btn--ghost at-btn--sm at-focusable" onclick="return confirm('Delete this category?');">Delete</button>
                </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php admin_layout_end();
