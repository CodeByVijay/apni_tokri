<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase3_fixture.php';

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

stage13_p3_cleanup($pdo);
$token = strtolower(Security::randomToken(4));
$adminId = stage13_p3_admin_with_faqs($pdo);
ok($adminId > 0, 'Admin fixture for FAQ tests');

$catCreate = FaqService::createCategory([
    'name' => 'AT-FAQ13 Cat ' . $token,
    'slug' => 'at-faq13-' . $token,
    'sort_order' => 50,
    'is_active' => 1,
]);
ok($catCreate['ok'], 'FAQ category creation');
$catId = (int) ($catCreate['id'] ?? 0);

$catUpdate = FaqService::updateCategory($catId, [
    'name' => 'AT-FAQ13 Cat Updated ' . $token,
    'slug' => 'at-faq13-' . $token,
    'sort_order' => 51,
    'is_active' => 1,
]);
ok($catUpdate['ok'], 'FAQ category update');

$deactCat = FaqService::setCategoryActive($catId, false);
ok($deactCat['ok'], 'Category deactivation');
$reactCat = FaqService::setCategoryActive($catId, true);
ok($reactCat['ok'], 'Category activation');

$faqCreate = FaqService::createFaq([
    'category_id' => $catId,
    'question' => 'AT-FAQ13-Q delivery charge ' . $token,
    'answer' => 'AT-FAQ13-A Standard delivery windows apply.',
    'sort_order' => 1,
    'is_active' => 1,
]);
ok($faqCreate['ok'], 'FAQ creation');
$faqId = (int) ($faqCreate['id'] ?? 0);

$faqUpdate = FaqService::updateFaq($faqId, [
    'category_id' => $catId,
    'question' => 'AT-FAQ13-Q updated ' . $token,
    'answer' => 'AT-FAQ13-A Updated answer text.',
    'sort_order' => 2,
    'is_active' => 1,
]);
ok($faqUpdate['ok'], 'FAQ update');

$deactFaq = FaqService::setFaqActive($faqId, false);
ok($deactFaq['ok'], 'FAQ deactivation');
$reactFaq = FaqService::setFaqActive($faqId, true);
ok($reactFaq['ok'], 'FAQ activation');

$badFaq = FaqService::createFaq([
    'category_id' => $catId,
    'question' => '',
    'answer' => '',
    'is_active' => 1,
]);
ok($badFaq['ok'] === false, 'Duplicate/invalid input handling (empty fields)');

$badCat = FaqService::createFaq([
    'category_id' => 999999999,
    'question' => 'AT-FAQ13 orphan',
    'answer' => 'No category',
    'is_active' => 1,
]);
ok($badCat['ok'] === false, 'Invalid category association');

$dupSlug = FaqService::createCategory([
    'name' => 'AT-FAQ13 Dup',
    'slug' => 'at-faq13-' . $token,
    'sort_order' => 99,
    'is_active' => 1,
]);
ok($dupSlug['ok'] === false && isset($dupSlug['errors']['slug']), 'Duplicate category slug rejected');

stage13_p3_admin_session($adminId);
ok(Permission::canModule('faqs'), 'Admin permission faqs.manage');
stage13_p3_clear_admin_session();

$noFaqsAdmin = (int) ($pdo->query(
    "SELECT u.id FROM admin_users u WHERE u.is_active = 1 AND NOT EXISTS (
        SELECT 1 FROM admin_user_roles ur
        INNER JOIN admin_role_permissions rp ON rp.role_id = ur.role_id
        INNER JOIN admin_permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = u.id AND p.slug = 'faqs.manage'
    ) ORDER BY u.id LIMIT 1"
)->fetchColumn() ?: 0);
if ($noFaqsAdmin > 0) {
    stage13_p3_admin_session($noFaqsAdmin);
    ok(!Permission::canModule('faqs'), 'Admin permission enforcement without faqs.manage');
    stage13_p3_clear_admin_session();
} else {
    ok(true, 'Permission denial (no non-faqs admin — skipped)');
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
ok(Csrf::validateRequest() === false, 'CSRF rejection');
unset($_SERVER['REQUEST_METHOD'], $_POST);

FaqService::setFaqActive($faqId, false);
$hidden = FaqRepository::listActivePublic('at-faq13-' . $token, null, 1, 50);
ok($hidden['total'] === 0, 'Public FAQ excludes inactive records');
FaqService::setFaqActive($faqId, true);

FaqService::setCategoryActive($catId, false);
$hiddenCat = FaqRepository::listActivePublic('at-faq13-' . $token, null, 1, 50);
ok($hiddenCat['total'] === 0, 'Public FAQ excludes inactive categories');
FaqService::setCategoryActive($catId, true);

$searchQ = FaqRepository::listActivePublic(null, 'AT-FAQ13-Q updated', 1, 50);
ok($searchQ['total'] >= 1, 'Search question');

$searchA = FaqRepository::listActivePublic(null, 'Updated answer text', 1, 50);
ok($searchA['total'] >= 1, 'Search answer');

$filterCat = FaqRepository::listActivePublic('at-faq13-' . $token, null, 1, 50);
ok($filterCat['total'] >= 1, 'Category filter');

$pdo->prepare(
    'INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, ?, 1)'
)->execute([$catId, 'AT-FAQ13-P2 ' . $token, 'Page two', 3]);
$pdo->prepare(
    'INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, ?, 1)'
)->execute([$catId, 'AT-FAQ13-P3 ' . $token, 'Page three', 4]);
$page1 = FaqRepository::searchForAdmin('AT-FAQ13-P', $catId, null, 1, 1);
$page2 = FaqRepository::searchForAdmin('AT-FAQ13-P', $catId, null, 2, 1);
ok($page1['total'] >= 2 && count($page1['rows']) === 1 && count($page2['rows']) === 1
    && (int) $page1['rows'][0]['id'] !== (int) $page2['rows'][0]['id'], 'Pagination');

$xssAnswer = '<script>alert(1)</script>plain';
$escaped = FaqService::escapePlainText($xssAnswer);
ok(str_contains($escaped, '&lt;script') && !str_contains($escaped, '<script'), 'Output escaping');

$empty = FaqRepository::listActivePublic(null, 'xyzzyplugh' . random_int(100000, 999999), 1, 50);
ok($empty['total'] === 0 && $empty['rows'] === [], 'Empty results');

$activeOnInactive = FaqService::setCategoryActive($catId, false);
FaqService::setFaqActive($faqId, true);
$block = FaqService::setFaqActive($faqId, true);
ok($block['ok'] === false, 'Cannot activate FAQ on inactive category');
FaqService::setCategoryActive($catId, true);

stage13_p3_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 3 FAQ integration.\n";
exit(0);
