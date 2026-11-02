<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('cms');

$useDb = Install::isSchemaReady() && AdminAuth::check() && CmsPageService::schemaReady();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$page = ($useDb && $id) ? CmsPageService::getByIdForAdmin($id) : null;
if ($id && $page === null && $useDb) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'Page not found', 'activeAdminNav' => 'cms']);
    echo '<div class="at-alert at-alert--error">CMS page not found.</div>';
    admin_layout_end();
    exit;
}

$errors = [];
$form = [
    'title' => (string) ($page['title'] ?? ''),
    'slug' => (string) ($page['slug'] ?? ''),
    'body_html' => (string) ($page['body_html'] ?? ''),
    'visibility' => (string) ($page['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC),
    'show_in_footer' => !isset($page) || !empty($page['show_in_footer']),
    'change_summary' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('Page saved (demo UI only — database required).');
        header('Location: ' . url_base() . '/admin/cms/index.php', true, 302);
        exit;
    }

    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $action = (string) ($_POST['form_action'] ?? 'save_draft');
    $input = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => trim((string) ($_POST['slug'] ?? '')),
        'body_html' => (string) ($_POST['body_html'] ?? ''),
        'visibility' => (string) ($_POST['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC),
        'show_in_footer' => !empty($_POST['show_in_footer']),
        'slug_manual' => !empty($_POST['slug_manual']),
        'preserve_slug' => !empty($_POST['preserve_slug']),
        'change_summary' => trim((string) ($_POST['change_summary'] ?? '')),
    ];
    $adminId = AdminAuth::id();

    if ($action === 'unpublish' && $postId) {
        $result = CmsPageService::unpublish($postId, $adminId, $input['change_summary'] !== '' ? $input['change_summary'] : null);
    } elseif ($action === 'publish') {
        if ($postId) {
            $result = CmsPageService::publish($postId, $input, $adminId);
        } else {
            $result = CmsPageService::create($input, $adminId);
            if ($result['ok']) {
                $result = CmsPageService::publish((int) $result['id'], $input, $adminId);
            }
        }
    } elseif ($action === 'save_draft') {
        if ($postId) {
            $result = CmsPageService::saveDraft($postId, $input, $adminId);
        } else {
            $result = CmsPageService::create($input, $adminId);
        }
    } else {
        if (!$postId) {
            $result = CmsPageService::create($input, $adminId);
        } else {
            $summary = $input['change_summary'] !== '' ? $input['change_summary'] : 'Updated';
            $result = CmsPageService::update($postId, $input, $adminId, $summary);
        }
    }

    if ($result['ok']) {
        admin_flash_set($action === 'publish' ? 'Page published.' : ($action === 'unpublish' ? 'Page unpublished.' : 'Page saved.'));
        header('Location: ' . url_base() . '/admin/cms/edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }

    $errors = $result['errors'] ?? [];
    $form = array_merge($form, [
        'title' => $input['title'],
        'slug' => $input['slug'],
        'body_html' => $input['body_html'],
        'visibility' => $input['visibility'],
        'show_in_footer' => $input['show_in_footer'],
        'change_summary' => $input['change_summary'],
    ]);
    admin_flash_set($result['message'] ?? 'Could not save page.', 'error');
}

$isPublished = $page && (string) ($page['status'] ?? '') === PublishStatus::PUBLISHED;
$slugPreview = url_base() . '/page.php?slug=' . rawurlencode($form['slug'] !== '' ? CatalogueSlug::fromName($form['slug']) : '…');

admin_layout_start(['pageTitle' => $id ? 'Edit CMS page' : 'New CMS page', 'activeAdminNav' => 'cms']);
admin_flash_render();
$pageHeading = $id ? 'Edit page' : 'New page';
$pageDescription = 'Content is sanitized on save. Use Preview to see drafts before publishing.';
$pageActionsHtml = '';
if ($id) {
    $pageActionsHtml = '<a href="' . Security::escape(url_base()) . '/admin/cms/history.php?id=' . (int) $id . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">Version history</a>'
        . ' <a href="' . Security::escape(url_base()) . '/admin/cms/preview.php?id=' . (int) $id . '" class="at-btn at-btn--secondary at-btn--sm at-focusable" target="_blank" rel="noopener noreferrer">Preview</a>';
}
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <input type="hidden" name="preserve_slug" value="1" id="cms-preserve-slug">
        <input type="hidden" name="slug_manual" value="0" id="cms-slug-manual">

        <?php if (!empty($errors['title'])): ?>
            <p class="text-sm text-red-600"><?= Security::escape($errors['title']) ?></p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="cms-title">Title</label>
            <input id="cms-title" name="title" class="at-input at-focusable w-full" required maxlength="255"
                   value="<?= Security::escape($form['title']) ?>">
        </div>

        <?php if (!empty($errors['slug'])): ?>
            <p class="text-sm text-red-600"><?= Security::escape($errors['slug']) ?></p>
        <?php endif; ?>
        <div>
            <label class="at-label" for="cms-slug">URL slug</label>
            <input id="cms-slug" name="slug" class="at-input at-focusable w-full" maxlength="191"
                   value="<?= Security::escape($form['slug']) ?>" autocomplete="off">
            <p class="text-xs at-muted mt-1">Public URL: <span id="cms-slug-preview"><?= Security::escape($slugPreview) ?></span></p>
        </div>

        <?php
        $bodyHtml = $form['body_html'];
        $fieldError = $errors['body_html'] ?? null;
        require APNI_TOKRI_ROOT . '/includes/partials/admin/cms-rich-editor.php';
        ?>

        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="cms-visibility">Visibility</label>
                <select id="cms-visibility" name="visibility" class="at-select at-focusable w-full">
                    <option value="<?= CmsPageService::VISIBILITY_PUBLIC ?>"<?= $form['visibility'] === CmsPageService::VISIBILITY_PUBLIC ? ' selected' : '' ?>>Public</option>
                    <option value="<?= CmsPageService::VISIBILITY_PRIVATE ?>"<?= $form['visibility'] === CmsPageService::VISIBILITY_PRIVATE ? ' selected' : '' ?>>Private (admin-only when published)</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex gap-2 text-sm items-center">
                    <input type="checkbox" name="show_in_footer" value="1"<?= $form['show_in_footer'] ? ' checked' : '' ?>>
                    Show link in site footer
                </label>
            </div>
        </div>

        <div>
            <label class="at-label" for="cms-summary">Change summary (optional)</label>
            <input id="cms-summary" name="change_summary" class="at-input at-focusable w-full" maxlength="255"
                   placeholder="e.g. Updated refund wording"
                   value="<?= Security::escape($form['change_summary']) ?>">
        </div>

        <?php if ($page): ?>
            <p class="text-sm at-muted">
                Status: <strong><?= Security::escape((string) ($page['status'] ?? '')) ?></strong>
                <?php if (!empty($page['published_at'])): ?>
                    · Published <?= Security::escape(substr((string) $page['published_at'], 0, 16)) ?>
                <?php endif; ?>
                <?php if (!empty($page['updated_at'])): ?>
                    · Updated <?= Security::escape(substr((string) $page['updated_at'], 0, 16)) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="flex flex-wrap gap-2 pt-2">
            <button type="submit" name="form_action" value="save_draft" class="at-btn at-btn--secondary at-focusable">Save draft</button>
            <?php if ($isPublished): ?>
                <button type="submit" name="form_action" value="save" class="at-btn at-btn--secondary at-focusable">Save changes</button>
                <button type="submit" name="form_action" value="unpublish" class="at-btn at-btn--secondary at-focusable">Unpublish</button>
            <?php endif; ?>
            <button type="submit" name="form_action" value="publish" class="at-btn at-btn--primary at-focusable">Publish</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/cms/index.php" class="at-btn at-btn--ghost at-focusable">Back to list</a>
        </div>
    </div>
</form>
<script>
(function () {
    var base = <?= json_encode(url_base() . '/page.php?slug=', JSON_UNESCAPED_SLASHES) ?>;
    var title = document.getElementById('cms-title');
    var slug = document.getElementById('cms-slug');
    var preview = document.getElementById('cms-slug-preview');
    var slugManual = document.getElementById('cms-slug-manual');
    var preserve = document.getElementById('cms-preserve-slug');
    var origSlug = slug.value;

    function slugify(t) {
        return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').substring(0, 191);
    }
    function updatePreview() {
        var s = slug.value.trim() ? slugify(slug.value) : '…';
        preview.textContent = base + s;
    }
    slug.addEventListener('input', function () {
        slugManual.value = '1';
        preserve.value = '0';
        updatePreview();
    });
    title.addEventListener('input', function () {
        if (slugManual.value !== '1' && !slug.value.trim()) {
            slug.value = slugify(title.value);
            updatePreview();
        } else if (slugManual.value !== '1' && slug.value === origSlug) {
            /* title changed but slug preserved via server flag */
        }
    });
    updatePreview();
})();
</script>
<?php admin_layout_end();
