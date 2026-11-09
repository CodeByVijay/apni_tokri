<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check() && MediaLibrary::isEnabled();
$ajaxUrl = url_base() . '/admin/ajax/media-library.php';
$csrf = Csrf::token();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$usage = isset($_GET['usage']) ? (string) $_GET['usage'] : '';
$sort = isset($_GET['sort']) && $_GET['sort'] === 'oldest' ? 'oldest' : 'newest';
$page = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$perPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
if ($perPage > 48) {
    $perPage = 48;
}

$list = $useDb
    ? MediaLibrary::search($q, in_array($usage, ['used', 'unused'], true) ? $usage : '', $sort, $page, $perPage)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];

$queryBase = array_filter([
    'q' => $q !== '' ? $q : null,
    'usage' => $usage !== '' ? $usage : null,
    'sort' => $sort !== 'newest' ? $sort : null,
    'per_page' => $perPage !== AdminServerList::DEFAULT_PER_PAGE ? $perPage : null,
]);
$self = url_base() . '/admin/media/index.php';

admin_layout_start(['pageTitle' => 'Media library', 'activeAdminNav' => 'media']);
$pageHeading = 'Media library';
$pageDescription = 'Upload once, reuse across products. Removing an image from a product does not delete the shared file.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!$useDb): ?>
<div class="at-admin-card">
    <div class="at-admin-card__body">
        <?php if (!MediaLibrary::isEnabled()): ?>
            <p class="at-alert at-alert--warning">Apply database migration <code>database/migrations/1.37.0_media_library.sql</code> to enable the media library.</p>
        <?php else: ?>
            <p class="text-sm at-muted">Database not ready.</p>
        <?php endif; ?>
    </div>
</div>
<?php admin_layout_end();
return; ?>
<?php endif; ?>

<div
    class="at-media-library"
    data-at-media-library
    data-ajax-url="<?= Security::escape($ajaxUrl) ?>"
    data-csrf="<?= Security::escape($csrf) ?>"
>
    <div class="at-admin-card mb-4">
        <div class="at-admin-card__body space-y-4">
            <form method="get" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[12rem]">
                    <label class="at-label" for="media-q">Search filename</label>
                    <input id="media-q" name="q" class="at-input at-focusable" value="<?= Security::escape($q) ?>" placeholder="e.g. mango">
                </div>
                <div>
                    <label class="at-label" for="media-usage">Usage</label>
                    <select id="media-usage" name="usage" class="at-input at-focusable">
                        <option value="">All</option>
                        <option value="used"<?= $usage === 'used' ? ' selected' : '' ?>>Used</option>
                        <option value="unused"<?= $usage === 'unused' ? ' selected' : '' ?>>Unused</option>
                    </select>
                </div>
                <div>
                    <label class="at-label" for="media-sort">Sort</label>
                    <select id="media-sort" name="sort" class="at-input at-focusable">
                        <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest</option>
                        <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>Oldest</option>
                    </select>
                </div>
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                <button type="submit" class="at-btn at-btn--secondary at-focusable">Apply</button>
            </form>

            <div class="at-media-library__upload border border-dashed border-neutral-300 rounded-lg p-4" data-at-media-dropzone tabindex="0" role="button" aria-label="Upload images">
                <p class="font-semibold">Upload images</p>
                <p class="text-sm at-muted">JPEG, PNG or WebP · max 5 MB each · multiple files supported</p>
                <input type="file" class="sr-only" data-at-media-file-input multiple accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" class="at-btn at-btn--primary at-focusable" data-at-media-upload-trigger>Browse files</button>
                </div>
                <div class="mt-2 text-sm space-y-1" data-at-media-upload-status role="status" aria-live="polite"></div>
            </div>
        </div>
    </div>

    <?php if ($list['rows'] === []): ?>
        <p class="text-sm at-muted">No media found.</p>
    <?php else: ?>
        <ul class="at-media-library__grid" role="list">
            <?php foreach ($list['rows'] as $row):
                $client = MediaLibrary::rowForClient($row);
                $mid = (int) $client['id'];
                $usageCount = (int) $client['usage_count'];
                ?>
                <li class="at-media-card" data-media-id="<?= $mid ?>">
                    <a href="<?= Security::escape($client['url']) ?>" target="_blank" rel="noopener" class="at-media-card__thumb-link">
                        <img src="<?= Security::escape($client['url']) ?>" alt="" class="at-media-card__thumb" width="160" height="160" loading="lazy">
                    </a>
                    <p class="at-media-card__name" title="<?= Security::escape($client['original_filename']) ?>">
                        <?= Security::escape($client['original_filename']) ?>
                    </p>
                    <p class="at-media-card__meta text-xs at-muted">
                        <?= (int) $client['width'] ?>×<?= (int) $client['height'] ?>
                        · <?= Security::escape(number_format($client['file_size'] / 1024, 1)) ?> KB
                        · <?= $usageCount ?> product<?= $usageCount === 1 ? '' : 's' ?>
                    </p>
                    <p class="at-media-card__meta text-xs at-muted"><?= Security::escape($client['created_at']) ?></p>
                    <div class="at-media-card__actions flex flex-wrap gap-1 mt-2">
                        <?php if ($usageCount === 0): ?>
                            <button
                                type="button"
                                class="at-btn at-btn--ghost at-btn--xs at-focusable"
                                data-at-media-soft-delete="<?= $mid ?>"
                            >Soft delete</button>
                        <?php else: ?>
                            <span class="text-xs text-amber-800">In use</span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        if ((int) $list['total_pages'] > 1) {
            admin_list_pagination((int) $list['page'], (int) $list['total'], $perPage, $queryBase, $self);
        }
        ?>
    <?php endif; ?>
</div>

<?php admin_layout_end(); ?>
