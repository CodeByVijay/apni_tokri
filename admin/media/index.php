<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('products');

$useDb = Install::isSchemaReady() && AdminAuth::check() && MediaLibrary::isEnabled();
$ajaxUrl = url_base() . '/admin/ajax/media-library.php';
$csrf = Csrf::token();

$tab = isset($_GET['tab']) && $_GET['tab'] === 'trash' ? 'trash' : 'all';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$usage = isset($_GET['usage']) ? (string) $_GET['usage'] : '';
$sort = isset($_GET['sort']) && $_GET['sort'] === 'oldest' ? 'oldest' : 'newest';
$page = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$perPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
if ($perPage > 48) {
    $perPage = 48;
}

$list = $useDb
    ? ($tab === 'trash'
        ? MediaLibrary::searchTrash($q, $sort, $page, $perPage)
        : MediaLibrary::search($q, in_array($usage, ['used', 'unused'], true) ? $usage : '', $sort, $page, $perPage))
    : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];

$queryBase = array_filter([
    'tab' => $tab === 'trash' ? 'trash' : null,
    'q' => $q !== '' ? $q : null,
    'usage' => $tab === 'all' && $usage !== '' ? $usage : null,
    'sort' => $sort !== 'newest' ? $sort : null,
    'per_page' => $perPage !== AdminServerList::DEFAULT_PER_PAGE ? $perPage : null,
]);
$self = url_base() . '/admin/media/index.php';
$tabAllUrl = $self . '?tab=all' . ($q !== '' ? '&q=' . rawurlencode($q) : '');
$tabTrashUrl = $self . '?tab=trash' . ($q !== '' ? '&q=' . rawurlencode($q) : '');

admin_layout_start(['pageTitle' => 'Media library', 'activeAdminNav' => 'media']);
$pageHeading = 'Media library';
$pageDescription = 'Upload once, reuse across products. Unused images can be moved to trash and restored or permanently deleted.';
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
    data-media-tab="<?= Security::escape($tab) ?>"
    data-ajax-url="<?= Security::escape($ajaxUrl) ?>"
    data-csrf="<?= Security::escape($csrf) ?>"
>
    <nav class="at-media-library__tabs mb-4 flex gap-2 border-b border-neutral-200" role="tablist" aria-label="Media library views">
        <a
            href="<?= Security::escape($tabAllUrl) ?>"
            class="at-media-library__tab px-4 py-2 font-semibold <?= $tab === 'all' ? 'is-active' : '' ?>"
            role="tab"
            aria-selected="<?= $tab === 'all' ? 'true' : 'false' ?>"
        >All Images</a>
        <a
            href="<?= Security::escape($tabTrashUrl) ?>"
            class="at-media-library__tab px-4 py-2 font-semibold <?= $tab === 'trash' ? 'is-active' : '' ?>"
            role="tab"
            aria-selected="<?= $tab === 'trash' ? 'true' : 'false' ?>"
        >Trash</a>
    </nav>

    <div class="at-admin-card mb-4">
        <div class="at-admin-card__body space-y-4">
            <form method="get" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="tab" value="<?= Security::escape($tab) ?>">
                <div class="flex-1 min-w-[12rem]">
                    <label class="at-label" for="media-q">Search filename</label>
                    <input id="media-q" name="q" class="at-input at-focusable" value="<?= Security::escape($q) ?>" placeholder="e.g. mango">
                </div>
                <?php if ($tab === 'all'): ?>
                <div>
                    <label class="at-label" for="media-usage">Usage</label>
                    <select id="media-usage" name="usage" class="at-input at-focusable">
                        <option value="">All</option>
                        <option value="used"<?= $usage === 'used' ? ' selected' : '' ?>>Used</option>
                        <option value="unused"<?= $usage === 'unused' ? ' selected' : '' ?>>Unused</option>
                    </select>
                </div>
                <?php endif; ?>
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

            <?php if ($tab === 'all'): ?>
            <div class="at-media-library__upload border border-dashed border-neutral-300 rounded-lg p-4" data-at-media-dropzone tabindex="0" role="button" aria-label="Upload images">
                <p class="font-semibold">Upload images</p>
                <p class="text-sm at-muted">JPEG, PNG or WebP · max 5 MB each · multiple files supported</p>
                <input type="file" class="sr-only" data-at-media-file-input multiple accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" class="at-btn at-btn--primary at-focusable" data-at-media-upload-trigger>Browse files</button>
                </div>
                <div class="mt-2 text-sm space-y-1" data-at-media-upload-status role="status" aria-live="polite"></div>
            </div>
            <?php else: ?>
            <p class="text-sm at-muted">Trashed images stay on disk until permanently deleted. Referenced files cannot be removed.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($list['rows'] === []): ?>
        <p class="text-sm at-muted"><?= $tab === 'trash' ? 'Trash is empty.' : 'No media found.' ?></p>
    <?php else: ?>
        <div
            class="at-media-bulk-toolbar mb-3"
            data-at-media-bulk-toolbar
            role="toolbar"
            aria-label="Bulk media actions"
        >
            <label class="at-media-bulk-toolbar__select-all flex items-center gap-2 text-sm">
                <input type="checkbox" class="at-focusable" data-at-media-select-all-page aria-label="Select all on this page">
                <span>Select all on this page</span>
            </label>
            <p class="at-media-bulk-toolbar__count text-sm at-muted mb-0" data-at-media-selected-count>0 selected</p>
            <?php if ($tab === 'all'): ?>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-media-bulk-trash disabled>Move to trash</button>
            <?php else: ?>
                <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-media-bulk-restore disabled>Restore selected</button>
                <button type="button" class="at-btn at-btn--danger at-btn--sm at-focusable" data-at-media-bulk-purge disabled>Permanently delete selected</button>
            <?php endif; ?>
            <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-media-clear-selection disabled>Clear selection</button>
        </div>
        <div class="at-media-bulk-results hidden text-sm mb-3" data-at-media-bulk-results role="status" aria-live="polite"></div>
        <ul class="at-media-library__grid" role="list" data-at-media-grid>
            <?php foreach ($list['rows'] as $row):
                $client = $tab === 'trash' ? MediaLibrary::rowForClientTrash($row) : MediaLibrary::rowForClient($row);
                $mid = (int) $client['id'];
                $usageCount = (int) $client['usage_count'];
                $canSelect = $tab === 'trash' || $usageCount === 0;
                ?>
                <li
                    class="at-media-card<?= $tab === 'trash' ? ' at-media-card--trash' : ' at-media-card--all' ?>"
                    data-media-id="<?= $mid ?>"
                    data-media-usage="<?= $usageCount ?>"
                    data-media-filename="<?= Security::escape($client['original_filename']) ?>"
                    <?php if ($tab === 'trash'): ?>
                    data-media-url="<?= Security::escape($client['url']) ?>"
                    data-media-size="<?= (int) $client['file_size'] ?>"
                    <?php endif; ?>
                >
                    <label class="at-media-card__select">
                        <input
                            type="checkbox"
                            class="at-focusable at-media-card__checkbox"
                            data-at-media-select="<?= $mid ?>"
                            value="<?= $mid ?>"
                            <?= $canSelect ? '' : 'disabled' ?>
                            aria-label="Select <?= Security::escape($client['original_filename']) ?>"
                        >
                    </label>
                    <a href="<?= Security::escape($client['url']) ?>" target="_blank" rel="noopener" class="at-media-card__thumb-link">
                        <img src="<?= Security::escape($client['url']) ?>" alt="" class="at-media-card__thumb" width="160" height="160" loading="lazy">
                    </a>
                    <div class="at-media-card__content">
                        <p class="at-media-card__name" title="<?= Security::escape($client['original_filename']) ?>">
                            <?= Security::escape($client['original_filename']) ?>
                        </p>
                        <p class="at-media-card__meta text-xs at-muted">
                            <?= (int) $client['width'] ?>×<?= (int) $client['height'] ?>
                            · <?= Security::escape(number_format($client['file_size'] / 1024, 1)) ?> KB
                            <?php if ($tab === 'all'): ?>
                            · <?= $usageCount ?> product<?= $usageCount === 1 ? '' : 's' ?>
                            <?php endif; ?>
                        </p>
                        <p class="at-media-card__meta text-xs at-muted">
                            <?php if ($tab === 'trash' && ($client['deleted_at'] ?? '') !== ''): ?>
                                Deleted <?= Security::escape($client['deleted_at']) ?>
                            <?php else: ?>
                                <?= Security::escape($client['created_at']) ?>
                            <?php endif; ?>
                        </p>
                        <div class="at-media-card__actions at-media-card__actions--stacked">
                            <?php if ($tab === 'all'): ?>
                                <?php if ($usageCount === 0): ?>
                                    <button
                                        type="button"
                                        class="at-btn at-btn--secondary at-btn--sm at-focusable at-media-card__action-btn at-media-card__trash-btn"
                                        data-at-media-soft-delete="<?= $mid ?>"
                                    >
                                        <span class="at-media-card__btn-label">
                                            <svg class="at-media-card__btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                                <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6"/><path d="M14 11v6"/>
                                            </svg>
                                            <span>Move to trash</span>
                                        </span>
                                    </button>
                                <?php else: ?>
                                    <span class="at-media-card__in-use text-xs text-amber-800">In use</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="at-btn at-btn--secondary at-btn--xs at-focusable at-media-card__action-btn"
                                    data-at-media-restore="<?= $mid ?>"
                                >Restore</button>
                                <button
                                    type="button"
                                    class="at-btn at-btn--danger at-btn--xs at-focusable at-media-card__action-btn"
                                    data-at-media-purge="<?= $mid ?>"
                                    data-media-url="<?= Security::escape($client['url']) ?>"
                                    data-media-filename="<?= Security::escape($client['original_filename']) ?>"
                                    data-media-size="<?= (int) $client['file_size'] ?>"
                                >Permanent delete</button>
                            <?php endif; ?>
                        </div>
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

    <div class="at-media-purge-modal hidden" data-at-media-purge-modal role="dialog" aria-modal="true" aria-labelledby="at-media-purge-title">
        <div class="at-media-purge-modal__backdrop" data-at-media-purge-close tabindex="-1"></div>
        <div class="at-media-purge-modal__panel">
            <h2 id="at-media-purge-title" class="font-bold text-lg" data-at-media-purge-title>Permanently delete image?</h2>
            <p class="text-sm text-red-800 font-semibold mt-1" data-at-media-purge-warning>This cannot be undone.</p>
            <div class="mt-4 at-media-purge-modal__single" data-at-media-purge-single>
                <div class="flex flex-wrap gap-4 items-start">
                    <img src="" alt="" class="at-media-purge-modal__preview" data-at-media-purge-preview width="120" height="120">
                    <dl class="text-sm space-y-1 min-w-0 flex-1">
                        <div><dt class="inline font-semibold">File: </dt><dd class="inline break-words" data-at-media-purge-filename></dd></div>
                        <div><dt class="inline font-semibold">Size: </dt><dd class="inline" data-at-media-purge-size></dd></div>
                    </dl>
                </div>
            </div>
            <p class="text-sm at-muted mt-3 hidden" data-at-media-purge-bulk-summary></p>
            <p class="text-sm at-muted mt-3">The database record and file on disk will be removed if nothing else references this image.</p>
            <label class="flex items-center gap-2 mt-4 text-sm">
                <input type="checkbox" data-at-media-purge-confirm class="at-focusable">
                I understand this is permanent
            </label>
            <p class="text-xs text-red-700 mt-2 hidden" data-at-media-purge-confirm-hint>Check the box above to enable delete.</p>
            <div class="flex gap-2 justify-end mt-5">
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-media-purge-close>Cancel</button>
                <button type="button" class="at-btn at-btn--danger at-focusable" disabled data-at-media-purge-submit>Delete permanently</button>
            </div>
        </div>
    </div>
</div>

<?php admin_layout_end(); ?>
