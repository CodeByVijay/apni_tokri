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
            'slug_manual' => !empty($_POST['slug_manual']),
            'parent_id' => $_POST['parent_id'] ?? '',
            'description' => $_POST['description'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'show_on_homepage' => $_POST['show_on_homepage'] ?? null,
            'status' => $_POST['status'] ?? 'published',
            'meta_title' => $_POST['meta_title'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
        ]);
        if ($result['ok']) {
            $savedId = (int) $result['id'];
            $cover = CategoryCoverImage::applyAdminPost($savedId, $_POST, $_FILES);
            if (empty($cover['ok'])) {
                $errors = ['form' => $cover['message'] ?? 'Cover image could not be saved.'];
                $category = CategoryRepository::find($savedId);
            } else {
                CatalogueGateway::reset();
                admin_flash_set('Category saved.');
                header('Location: ' . url_base() . '/admin/categories/edit.php?id=' . $savedId, true, 302);
                exit;
            }
        }
        $errors = $result['errors'] ?? ['form' => $result['message'] ?? 'Save failed.'];
    } else {
        admin_flash_persistence_blocked('Category saved (demo UI only).');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? ''), true, 302);
        exit;
    }
}

$defaults = [
    'name' => '',
    'slug' => '',
    'parent_id' => '',
    'description' => '',
    'sort_order' => 0,
    'show_on_homepage' => 1,
    'status' => 'published',
    'meta_title' => '',
    'meta_description' => '',
    'image_path' => '',
];
if ($category) {
    $defaults = array_merge($defaults, $category);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaults['name'] = trim((string) ($_POST['name'] ?? ''));
    $defaults['slug'] = trim((string) ($_POST['slug'] ?? ''));
}

admin_layout_start(['pageTitle' => $category ? 'Edit category' : 'Add category', 'activeAdminNav' => 'categories']);
$pageHeading = $category ? 'Edit category' : 'Add category';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($errors !== []) {
    echo '<div class="at-alert at-alert--error mb-4">' . Security::escape(implode(' ', $errors)) . '</div>';
}
?>
<form method="post" enctype="multipart/form-data" class="at-admin-card max-w-2xl" data-at-admin-slug data-slug-api="<?= Security::escape(url_base()) ?>/admin/ajax/slug-from-name.php">
    <div class="at-admin-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($category): ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><?php endif; ?>
        <?php
        $slugSourceId = 'name';
        $slugSourceName = 'name';
        $slugSourceLabel = 'Name';
        $slugSourceValue = (string) $defaults['name'];
        $slugTargetId = 'slug';
        $slugTargetValue = (string) $defaults['slug'];
        $slugManualId = 'category-slug-manual';
        $slugError = $errors['slug'] ?? null;
        if (!$category) {
            $slugSourcePlaceholder = 'e.g. Fresh Vegetables';
            $slugTargetPlaceholder = 'Auto-generated from name';
        }
        require APNI_TOKRI_ROOT . '/includes/partials/admin/slug-field.php';
        ?>
        <div>
            <label class="at-label" for="parent_id">Parent category</label>
            <select id="parent_id" name="parent_id" class="at-select at-focusable">
                <option value="">None (top level)</option>
                <?php if ($useDb):
                    $excludeParents = $id ? array_merge([$id], CategoryRepository::descendantIds($id)) : [];
                    foreach (CategoryRepository::allForAdmin() as $c):
                        if (in_array((int) $c['id'], $excludeParents, true)) {
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
                <p class="text-xs at-muted mt-1">To change display order for all categories, use <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/categories/index.php?reorder=1">Reorder categories</a>.</p>
            </div>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_on_homepage" value="1"<?= !empty($defaults['show_on_homepage']) ? ' checked' : '' ?>> Show on homepage
        </label>
        <div class="pt-2 border-t border-neutral-200">
            <h3 class="font-bold text-sm mb-2">Cover image (homepage tile)</h3>
            <?php
            $coverPath = trim((string) ($defaults['image_path'] ?? ''));
            if ($coverPath !== ''):
                ?>
                <div class="mb-3">
                    <img src="<?= Security::escape(asset_url(ltrim($coverPath, '/'))) ?>" alt="" class="max-h-32 rounded-lg border border-neutral-200" width="160" height="120" loading="lazy">
                    <p class="text-xs at-muted mt-1">Current: <?= Security::escape($coverPath) ?></p>
                </div>
                <label class="flex items-center gap-2 text-sm mb-2">
                    <input type="checkbox" name="remove_cover_image" value="1"> Remove current cover image
                </label>
            <?php endif; ?>
            <div>
                <label class="at-label" for="cover_image"><?= $coverPath !== '' ? 'Replace cover image' : 'Upload cover image' ?></label>
                <input id="cover_image" name="cover_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="at-input at-focusable">
                <p class="text-xs at-muted mt-1">JPEG, PNG, WebP or GIF · max 5 MB. Used on the homepage category tile when set.</p>
            </div>
        </div>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save category</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/categories/index.php" class="at-btn at-btn--ghost at-focusable">Back</a>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
