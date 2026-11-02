<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('categories');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$category = ($useDb && $id) ? CategoryRepository::find($id) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $result = CategoryRepository::save($postId, [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'parent_id' => $_POST['parent_id'] ?? '',
            'description' => $_POST['description'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'show_on_homepage' => $_POST['show_on_homepage'] ?? null,
            'status' => $_POST['status'] ?? 'published',
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
        ]);
        if ($result['ok']) {
            CatalogueGateway::reset();
            admin_flash_set('Category saved.');
            header('Location: ' . url_base() . '/admin/categories/edit.php?id=' . (int) $result['id'], true, 302);
            exit;
        }
        $errors = $result['errors'] ?? ['form' => $result['message'] ?? 'Save failed.'];
    } else {
        admin_flash_set('Category saved (demo UI only).');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }
}

$defaults = [
    'name' => 'Fresh Vegetables',
    'slug' => 'vegetables',
    'parent_id' => '',
    'description' => '',
    'sort_order' => 0,
    'show_on_homepage' => 1,
    'status' => 'published',
    'meta_title' => '',
    'meta_description' => '',
];
if ($category) {
    $defaults = array_merge($defaults, $category);
}

admin_layout_start(['pageTitle' => $category ? 'Edit category' : 'Add category', 'activeAdminNav' => 'categories']);
admin_flash_render();
$pageHeading = $category ? 'Edit category' : 'Add category';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($errors !== []) {
    echo '<div class="at-alert at-alert--error mb-4">' . Security::escape(implode(' ', $errors)) . '</div>';
}
?>
<form method="post" class="at-admin-card max-w-2xl">
    <div class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($category): ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><?php endif; ?>
        <div>
            <label class="at-label" for="name">Name</label>
            <input id="name" name="name" class="at-input at-focusable" value="<?= Security::escape((string) $defaults['name']) ?>" required>
        </div>
        <div>
            <label class="at-label" for="slug">Slug</label>
            <input id="slug" name="slug" class="at-input at-focusable" value="<?= Security::escape((string) $defaults['slug']) ?>" pattern="[a-z0-9-]+">
            <p class="at-field-hint">Leave blank to auto-generate from name. Must be unique.</p>
        </div>
        <div>
            <label class="at-label" for="parent_id">Parent category</label>
            <select id="parent_id" name="parent_id" class="at-select at-focusable">
                <option value="">None (top level)</option>
                <?php if ($useDb):
                    foreach (CategoryRepository::allForAdmin() as $c):
                        if ($id && (int) $c['id'] === $id) {
                            continue;
                        }
                        $sel = (string) ($defaults['parent_id'] ?? '') === (string) $c['id'] ? ' selected' : '';
                        ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $sel ?>><?= Security::escape((string) $c['name']) ?></option>
                    <?php endforeach;
                endif; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="description">Description</label>
            <textarea id="description" name="description" class="at-textarea at-focusable" rows="3"><?= Security::escape((string) ($defaults['description'] ?? '')) ?></textarea>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="status">Status</label>
                <select id="status" name="status" class="at-select at-focusable">
                    <option value="published"<?= ($defaults['status'] ?? '') === 'published' ? ' selected' : '' ?>>Published</option>
                    <option value="hidden"<?= ($defaults['status'] ?? '') === 'hidden' ? ' selected' : '' ?>>Hidden</option>
                </select>
            </div>
            <div>
                <label class="at-label" for="sort_order">Sort order</label>
                <input id="sort_order" name="sort_order" type="number" class="at-input at-focusable" value="<?= (int) ($defaults['sort_order'] ?? 0) ?>">
            </div>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_on_homepage" value="1"<?= !empty($defaults['show_on_homepage']) ? ' checked' : '' ?>> Show on homepage
        </label>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save category</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/categories/index.php" class="at-btn at-btn--ghost at-focusable">Back</a>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
