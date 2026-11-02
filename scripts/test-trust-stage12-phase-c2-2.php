<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage12_phase_c21_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

stage12_c21_cleanup($pdo);
stage12_c21_clear_admin_session();

ok(ReviewService::searchModerationHistoryForAdmin([], 1, 10)['total'] === 0, 'Search blocked without admin auth');

$adminId = (int) ($pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($adminId > 0, 'Active admin fixture');

stage12_c21_admin_session($adminId);
$fixture = stage12_c21_insert_pending_review($pdo);
$reviewId = $fixture['review_id'];
ReviewService::moderate($reviewId, 'approve');
ReviewService::moderate($reviewId, 'reply', null, 'AT-C22 public reply text');

$norm = ReviewService::normalizeModerationHistoryFilters([
    'action' => 'approve',
    'admin_user_id' => (string) $adminId,
    'review_id' => (string) $reviewId,
    'date_from' => '2099-01-01',
    'date_to' => '2000-01-01',
]);
ok(($norm['action'] ?? '') === 'approve', 'Filter normalizes valid action');
ok((int) ($norm['admin_user_id'] ?? 0) === $adminId, 'Filter normalizes admin_user_id');
ok((int) ($norm['review_id'] ?? 0) === $reviewId, 'Filter normalizes review_id');
ok(!isset($norm['date_to']), 'Invalid date range drops date_to');

$bad = ReviewService::normalizeModerationHistoryFilters(['action' => 'delete_all', 'date_from' => 'not-a-date']);
ok(!isset($bad['action']) && !isset($bad['date_from']), 'Unknown action and bad dates rejected');

$all = ReviewService::searchModerationHistoryForAdmin(['review_id' => $reviewId], 1, 10);
ok($all['total'] >= 2, 'Search returns moderation rows for review');
ok(count($all['rows']) >= 2, 'Search page includes rows');

$approveOnly = ReviewService::searchModerationHistoryForAdmin(['review_id' => $reviewId, 'action' => 'approve'], 1, 10);
ok($approveOnly['total'] === 1, 'Action filter approve');
ok(($approveOnly['rows'][0]['action'] ?? '') === 'approve', 'Filtered row action approve');

$replyRow = null;
foreach ($all['rows'] as $row) {
    if (($row['action'] ?? '') === 'admin_reply') {
        $replyRow = $row;
        break;
    }
}
ok($replyRow !== null, 'Admin reply row present');
ok(($replyRow['admin_reply_display'] ?? '') === 'AT-C22 public reply text', 'Reply decoded from metadata not raw JSON');
ok(strpos((string) ($replyRow['metadata_json'] ?? ''), '{') === false || ($replyRow['admin_reply_display'] ?? '') !== '', 'Display uses decoded reply field');

$present = ReviewService::presentModerationHistoryRow([
    'action' => 'reject',
    'admin_name' => 'Mod Admin',
    'metadata_json' => '{"admin_reply":"hidden","evil":"<script>"}',
    'reason' => 'policy',
    'previous_status' => 'pending',
    'new_status' => 'rejected',
]);
ok(($present['action_label'] ?? '') === 'Rejected', 'Action label for reject');
ok(($present['actor_display'] ?? '') === 'Mod Admin', 'Actor display from admin name');
ok(($present['admin_reply_display'] ?? null) === 'hidden', 'Only admin_reply key read from metadata');

$history = ReviewService::moderationHistoryForReview($reviewId);
ok(count($history) >= 2, 'Per-review history for admin');

stage12_c21_clear_admin_session();
ok(ReviewService::moderationHistoryForReview($reviewId) === [], 'Per-review history hidden without admin');

$reportFile = dirname(__DIR__) . '/admin/reviews/moderation-history.php';
ok(is_readable($reportFile), 'Admin moderation history page exists');
$indexFile = dirname(__DIR__) . '/admin/reviews/index.php';
$indexSrc = (string) file_get_contents($indexFile);
ok(strpos($indexSrc, 'review-moderation-history.php') !== false, 'Review detail embeds moderation history partial');
ok(strpos($indexSrc, 'moderation-history.php') !== false, 'Review queue links moderation report');

stage12_c21_cleanup($pdo);
stage12_c21_clear_admin_session();

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 Phase C.2.2 admin moderation history UI.\n";
exit(0);
