<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('reviews');

$reviewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
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
admin_flash_render();

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
    <p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reviews/index.php">← Back to queue</a></p>
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
<?php
$rows = [];
if (Install::isSchemaReady()) {
    foreach (ReviewService::listPending(100) as $r) {
        $rows[] = [
            'product' => (string) ($r['product_name'] ?? ''),
            'rating' => (int) ($r['rating'] ?? 0) . '★',
            'status' => ucfirst((string) ($r['status'] ?? '')),
            'verified' => !empty($r['verified_purchase']) ? 'Yes' : 'No',
            'link' => 'View',
            'action_href' => url_base() . '/admin/reviews/index.php?id=' . (int) $r['id'],
        ];
    }
}
$columns = [
    ['label' => 'Product', 'key' => 'product'],
    ['label' => 'Rating', 'key' => 'rating'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => 'Verified', 'key' => 'verified'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if ($rows === []) {
    echo '<p class="text-sm at-muted mt-3">No pending reviews.</p>';
}
admin_layout_end();
