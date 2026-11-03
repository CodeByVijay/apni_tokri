<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('reviews');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'rating' => 'rating', 'product_name' => 'product_name',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'asc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

$reviewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    if (($_POST['admin_delete'] ?? '') === '1') {
        $result = ReviewService::deleteForAdmin((int) ($_POST['record_id'] ?? 0));
        admin_flash_set($result['message'] ?? ($result['ok'] ? 'Deleted.' : 'Failed.'), !empty($result['ok']) ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/reviews/index.php', true, 302);
        exit;
    }
    $rid = (int) ($_POST['review_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $result = ReviewService::moderate(
        $rid,
        $action,
        isset($_POST['reject_reason']) ? (string) $_POST['reject_reason'] : null,
        isset($_POST['admin_reply']) ? (string) $_POST['admin_reply'] : null
    );
    if ($result['ok']) {
        admin_flash_set('Review updated.', 'success');
    } else {
        admin_flash_set($result['message'] ?? 'Could not update review.', 'error');
    }
    header('Location: ' . url_base() . '/admin/reviews/index.php?id=' . $rid);
    exit;
}

admin_layout_start(['pageTitle' => 'Reviews', 'activeAdminNav' => 'reviews']);

if ($reviewId > 0 && Install::isSchemaReady()) {
    Install::ensureConnection();
    $stmt = Database::pdo()->prepare(
        'SELECT r.*, p.name AS product_name FROM product_reviews r
         INNER JOIN products p ON p.id = r.product_id WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) {
        echo '<p>Review not found.</p>';
        admin_layout_end();
        exit;
    }
    $pageHeading = 'Review #' . $reviewId;
    require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
    ?>
    <div class="at-card">
        <div class="at-card__body space-y-3">
            <p><strong><?= Security::escape((string) $review['product_name']) ?></strong> — <?= (int) $review['rating'] ?>★</p>
            <p class="text-sm"><?= Security::escape((string) ($review['body'] ?? '')) ?></p>
            <p class="text-sm at-muted">Status: <?= Security::escape((string) $review['status']) ?>
                <?php if (!empty($review['verified_purchase'])): ?> · Verified purchase<?php endif; ?></p>
            <?php if (!empty($review['rejection_reason'])): ?>
                <p class="text-sm text-red-700">Rejection: <?= Security::escape((string) $review['rejection_reason']) ?></p>
            <?php endif; ?>
            <?php if (!empty($review['admin_reply'])): ?>
                <p class="text-sm"><strong>Admin reply:</strong> <?= Security::escape((string) $review['admin_reply']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $moderationHistoryRows = ReviewService::moderationHistoryForReview($reviewId);
    require APNI_TOKRI_ROOT . '/includes/partials/admin/review-moderation-history.php';
    ?>
    <p class="text-sm mt-2">
        <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reviews/moderation-history.php?review_id=<?= (int) $reviewId ?>">Full moderation report for this review →</a>
    </p>
    <form method="post" class="at-card mt-4">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="review_id" value="<?= (int) $reviewId ?>">
            <p class="text-sm text-neutral-600">Do not reject genuine negative reviews without cause.</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" name="action" value="approve" class="at-btn at-btn--primary at-focusable">Approve</button>
                <button type="submit" name="action" value="reject" class="at-btn at-btn--secondary at-focusable">Reject</button>
            </div>
            <textarea name="reject_reason" class="at-textarea at-focusable" placeholder="Mandatory rejection reason"></textarea>
            <div>
                <label class="at-label">Admin reply (public)</label>
                <textarea name="admin_reply" class="at-textarea at-focusable" placeholder="Optional reply to customer"></textarea>
                <button type="submit" name="action" value="reply" class="at-btn at-btn--secondary at-focusable mt-2">Save reply</button>
            </div>
        </div>
    </form>
    <p class="mt-4 flex flex-wrap gap-3 items-center">
        <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reviews/index.php">← Back to queue</a>
        <span class="at-admin-actions"><?= admin_delete_form('review', (int) $reviewId, 'Review #' . $reviewId) ?></span>
    </p>
    <?php
    admin_layout_end();
    exit;
}

$pageHeading = 'Review moderation queue';
$pageDescription = 'Only approved reviews appear on the storefront.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm">
    <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/reviews/moderation-history.php">Moderation history report →</a>
</p>
<?php if (Install::isSchemaReady()): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search pending</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Product, review text">
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
    </div>
</form>
<?php endif;
$baseList = url_base() . '/admin/reviews/index.php';
$rows = [];
$list = ['total' => 0, 'page' => 1, 'total_pages' => 1];
if (Install::isSchemaReady()) {
    $list = ReviewService::searchPendingForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
    foreach ($list['rows'] as $r) {
        $rows[] = [
            'product' => (string) ($r['product_name'] ?? ''),
            'rating' => (int) ($r['rating'] ?? 0) . '★',
            'status' => ucfirst((string) ($r['status'] ?? '')),
            'verified' => !empty($r['verified_purchase']) ? 'Yes' : 'No',
            'actions' => admin_table_actions(
                $baseList . '?id=' . (int) $r['id'],
                'View',
                'review',
                (int) $r['id'],
                'Review #' . (int) $r['id']
            ),
        ];
    }
}
$columns = [
    ['label' => 'Product', 'label_html' => AdminServerList::sortHeaderLink('product_name', 'Product', $sort, $dir, $baseList, $queryBase), 'key' => 'product'],
    ['label' => 'Rating', 'label_html' => AdminServerList::sortHeaderLink('rating', 'Rating', $sort, $dir, $baseList, $queryBase), 'key' => 'rating'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Verified', 'key' => 'verified'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $baseList);
}
admin_layout_end();
