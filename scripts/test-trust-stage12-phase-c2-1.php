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

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'Schema version matches Install::SCHEMA_VERSION');

$table = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'review_moderation_history'"
)->fetchColumn();
ok($table === 1, 'Migration table review_moderation_history exists');

$idxReview = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'review_moderation_history'
       AND index_name = 'idx_review_mod_history_review'"
)->fetchColumn();
ok($idxReview >= 1, 'Index on review_id and created_at');

$adminId = (int) ($pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($adminId > 0, 'Active admin user for moderation actor');

$fixture = stage12_c21_insert_pending_review($pdo);
$reviewId = $fixture['review_id'];

stage12_c21_clear_admin_session();
$unauth = ReviewService::moderate($reviewId, 'approve');
ok($unauth['ok'] === false, 'Unauthenticated moderation blocked');

stage12_c21_admin_session($adminId);
$approve = ReviewService::moderate($reviewId, 'approve');
ok($approve['ok'], 'Approve succeeds with admin session');
$history = ReviewService::moderationHistoryForReview($reviewId);
ok(count($history) === 1, 'Approve writes one history row');
$h0 = $history[0];
ok((int) ($h0['admin_user_id'] ?? 0) === $adminId, 'Approve records server-side admin actor');
ok((string) ($h0['action'] ?? '') === 'approve', 'Approve action label');
ok((string) ($h0['previous_status'] ?? '') === ReviewStatus::PENDING, 'Approve previous status');
ok((string) ($h0['new_status'] ?? '') === ReviewStatus::APPROVED, 'Approve new status');

$fixture2 = stage12_c21_insert_pending_review($pdo);
$rejectId = $fixture2['review_id'];
$rejectReason = 'AT-C21 policy violation';
$reject = ReviewService::moderate($rejectId, 'reject', $rejectReason);
ok($reject['ok'], 'Reject succeeds');
$rh = ReviewService::moderationHistoryForReview($rejectId);
ok(count($rh) === 1 && (string) ($rh[0]['reason'] ?? '') === $rejectReason, 'Reject records reason and transition');
ok(
    (string) ($rh[0]['previous_status'] ?? '') === ReviewStatus::PENDING
    && (string) ($rh[0]['new_status'] ?? '') === ReviewStatus::REJECTED,
    'Reject status transition'
);

$fixture3 = stage12_c21_insert_pending_review($pdo);
$replyId = $fixture3['review_id'];
ReviewService::moderate($replyId, 'approve');
$replyText = 'Thank you for your feedback (AT-C21).';
$reply = ReviewService::moderate($replyId, 'reply', null, $replyText);
ok($reply['ok'], 'Admin reply succeeds');
$rr = ReviewService::moderationHistoryForReview($replyId);
ok(count($rr) === 2, 'Reply appends history without removing prior rows');
$replyRow = $rr[1];
ok((string) ($replyRow['action'] ?? '') === 'admin_reply', 'Admin reply action recorded');
ok((string) ($replyRow['previous_status'] ?? '') === ReviewStatus::APPROVED, 'Reply preserves status in history');
ok((string) ($replyRow['new_status'] ?? '') === ReviewStatus::APPROVED, 'Reply new status unchanged');
$meta = json_decode((string) ($replyRow['metadata_json'] ?? ''), true);
ok(is_array($meta) && ($meta['admin_reply'] ?? '') === $replyText, 'Admin reply body stored in metadata');

ReviewService::testThrowOnNextModerationHistoryInsert(true);
$fixture4 = stage12_c21_insert_pending_review($pdo);
$atomicId = $fixture4['review_id'];
$atomicFail = ReviewService::moderate($atomicId, 'approve');
ok($atomicFail['ok'] === false, 'Moderation fails when history insert fails');
$st = $pdo->prepare('SELECT status FROM product_reviews WHERE id = ?');
$st->execute([$atomicId]);
ok((string) $st->fetchColumn() === ReviewStatus::PENDING, 'Failed audit insert rolls back review status change');
$histCount = $pdo->prepare('SELECT COUNT(*) FROM review_moderation_history WHERE review_id = ?');
$histCount->execute([$atomicId]);
ok((int) $histCount->fetchColumn() === 0, 'Failed audit insert leaves no orphan history row');

stage12_c21_clear_admin_session();
$_SESSION['customer_id'] = $fixture['customer_id'];
stage12_c21_admin_session(999999999);
$forged = ReviewService::moderate($reviewId, 'reject', 'forged');
ok($forged['ok'] === false, 'Forged invalid admin session cannot moderate');
unset($_SESSION['customer_id']);
stage12_c21_admin_session($adminId);

$publicRows = ReviewService::approvedForProduct($fixture['product_id'], 50);
$visible = false;
foreach ($publicRows as $pr) {
    if ((int) ($pr['id'] ?? 0) === $reviewId) {
        $visible = true;
        break;
    }
}
ok($visible, 'Approved review still visible publicly after moderation history');

stage12_c21_clear_admin_session();
$customerHistory = ReviewService::moderationHistoryForReview($reviewId);
ok($customerHistory === [], 'Moderation history hidden without admin auth');

stage12_c21_admin_session($adminId);
$beforeIds = array_map(static function (array $r): int {
    return (int) ($r['id'] ?? 0);
}, ReviewService::moderationHistoryForReview($replyId));
ok($beforeIds !== [] && $beforeIds[0] > 0, 'History rows have stable ids');
$afterIds = array_map(static function (array $r): int {
    return (int) ($r['id'] ?? 0);
}, ReviewService::moderationHistoryForReview($replyId));
ok($beforeIds === $afterIds, 'Re-read does not drop or replace prior history rows (append-only)');

$badSubmit = ReviewService::submit($fixture['customer_id'], 999999999, 5, 'AT-C21 foreign item body text');
ok($badSubmit['ok'] === false, 'Customer review submission rules unchanged');

stage12_c21_cleanup($pdo);
stage12_c21_clear_admin_session();

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 Phase C.2.1 review moderation history.\n";
exit(0);
