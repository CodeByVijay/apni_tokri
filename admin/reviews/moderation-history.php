<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('reviews');

$filters = ReviewService::normalizeModerationHistoryFilters($_GET);
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 25;

admin_layout_start(['pageTitle' => 'Review moderation history', 'activeAdminNav' => 'reviews']);
admin_flash_render();

$pageHeading = 'Review moderation history';
$pageDescription = 'Read-only audit trail of approve, reject, and admin reply actions.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$base = url_base() . '/admin/reviews/moderation-history.php';
$queryBase = $filters;
unset($queryBase['page']);
$buildUrl = static function (array $extra) use ($base, $queryBase): string {
    $params = array_merge($queryBase, $extra);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }

    return $base . ($params === [] ? '' : '?' . http_build_query($params));
};

$result = ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
$adminOptions = [];
if (Install::isSchemaReady()) {
    $result = ReviewService::searchModerationHistoryForAdmin($filters, $page, $perPage);
    $adminOptions = ReviewService::moderationHistoryAdminFilterOptions();
}
$totalPages = $result['per_page'] > 0 ? (int) ceil($result['total'] / $result['per_page']) : 0;
?>
<p class="mb-4 text-sm">
    <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reviews/index.php">← Review queue</a>
</p>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 items-end">
        <div>
            <label class="at-label" for="action">Action</label>
            <select id="action" name="action" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach (ReviewService::moderationHistoryActionOptions() as $act): ?>
                    <option value="<?= Security::escape($act) ?>"<?= ($filters['action'] ?? '') === $act ? ' selected' : '' ?>>
                        <?= Security::escape(ReviewService::moderationActionLabel($act)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="admin_user_id">Admin</label>
            <select id="admin_user_id" name="admin_user_id" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach ($adminOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"<?= (int) ($filters['admin_user_id'] ?? 0) === (int) $opt['id'] ? ' selected' : '' ?>>
                        <?= Security::escape((string) $opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="review_id">Review ID</label>
            <input id="review_id" name="review_id" type="number" min="1" class="at-input at-focusable"
                   value="<?= !empty($filters['review_id']) ? (int) $filters['review_id'] : '' ?>" placeholder="e.g. 42">
        </div>
        <div>
            <label class="at-label" for="date_from">From</label>
            <input id="date_from" name="date_from" type="date" class="at-input at-focusable"
                   value="<?= Security::escape((string) ($filters['date_from'] ?? '')) ?>">
        </div>
        <div>
            <label class="at-label" for="date_to">To</label>
            <input id="date_to" name="date_to" type="date" class="at-input at-focusable"
                   value="<?= Security::escape((string) ($filters['date_to'] ?? '')) ?>">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
            <a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape($base) ?>">Reset</a>
        </div>
    </div>
</form>
<?php
$rows = [];
foreach ($result['rows'] as $h) {
    $detail = '';
    if ($h['reason_display'] ?? '') {
        $detail = 'Reason: ' . (string) $h['reason_display'];
    } elseif ($h['admin_reply_display'] ?? '') {
        $detail = 'Reply: ' . mb_substr((string) $h['admin_reply_display'], 0, 80);
    }
    $statusChange = (string) ($h['previous_status'] ?? '—') . ' → ' . (string) ($h['new_status'] ?? '—');
    $rows[] = [
        'when' => (string) ($h['created_at'] ?? ''),
        'review' => '#' . (int) ($h['review_id'] ?? 0),
        'product' => (string) ($h['product_name'] ?? '—'),
        'action' => (string) ($h['action_label'] ?? ''),
        'status' => $statusChange,
        'actor' => (string) ($h['actor_display'] ?? '—'),
        'detail' => $detail !== '' ? $detail : '—',
        'link' => 'Open review',
        'action_href' => url_base() . '/admin/reviews/index.php?id=' . (int) ($h['review_id'] ?? 0),
    ];
}
$columns = [
    ['label' => 'When', 'key' => 'when'],
    ['label' => 'Review', 'key' => 'review'],
    ['label' => 'Product', 'key' => 'product'],
    ['label' => 'Action', 'key' => 'action'],
    ['label' => 'Status change', 'key' => 'status'],
    ['label' => 'Admin', 'key' => 'actor'],
    ['label' => 'Reason / reply', 'key' => 'detail'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
?>
<p class="text-sm at-muted mt-3">
    <?= (int) $result['total'] ?> record(s)
    <?php if ($totalPages > 1): ?>
        · Page <?= (int) $result['page'] ?> of <?= $totalPages ?>
    <?php endif; ?>
</p>
<?php if ($totalPages > 1): ?>
    <nav class="mt-3 flex flex-wrap gap-2 text-sm" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($buildUrl(['page' => $page - 1])) ?>">← Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
            <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= Security::escape($buildUrl(['page' => $page + 1])) ?>">Next →</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php
admin_layout_end();
