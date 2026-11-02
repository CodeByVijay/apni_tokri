<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('faqs');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$faq = ($useDb && $id) ? FaqRepository::findFaqById($id) : null;
if ($id && $faq === null && $useDb) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'FAQ not found', 'activeAdminNav' => 'faqs']);
    echo '<div class="at-alert at-alert--error">FAQ not found.</div>';
    admin_layout_end();
    exit;
}

$categories = $useDb ? FaqRepository::listCategoriesForAdmin(true) : [];
$form = [
    'category_id' => (int) ($faq['category_id'] ?? 0),
    'question' => (string) ($faq['question'] ?? ''),
    'answer' => (string) ($faq['answer'] ?? ''),
    'sort_order' => (int) ($faq['sort_order'] ?? 0),
    'is_active' => !isset($faq) || !empty($faq['is_active']),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('FAQ saved (demo UI).');
        header('Location: ' . url_base() . '/admin/faqs/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    if ((string) ($_POST['form_action'] ?? '') === 'delete' && $postId) {
        $result = FaqService::deleteFaq($postId);
        admin_flash_set($result['ok'] ? 'FAQ deleted.' : ($result['message'] ?? 'Could not delete.'), $result['ok'] ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/faqs/index.php', true, 302);
        exit;
    }
    $input = [
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'question' => trim((string) ($_POST['question'] ?? '')),
        'answer' => trim((string) ($_POST['answer'] ?? '')),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'is_active' => !empty($_POST['is_active']),
    ];
    $result = $postId ? FaqService::updateFaq($postId, $input) : FaqService::createFaq($input);
    if ($result['ok']) {
        admin_flash_set($postId ? 'FAQ updated.' : 'FAQ created.');
        header('Location: ' . url_base() . '/admin/faqs/edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
    $errors = $result['errors'] ?? [];
    $form = array_merge($form, $input);
    admin_flash_set($result['message'] ?? 'Could not save FAQ.', 'error');
}

admin_layout_start(['pageTitle' => $id ? 'Edit FAQ' : 'New FAQ', 'activeAdminNav' => 'faqs']);
admin_flash_render();
$pageHeading = $id ? 'Edit FAQ' : 'New FAQ';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card max-w-2xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <?php foreach ($errors as $msg): ?>
            <p class="text-sm text-red-600"><?= Security::escape((string) $msg) ?></p>
        <?php endforeach; ?>
        <div>
            <label class="at-label" for="category_id">Category</label>
            <select id="category_id" name="category_id" class="at-select at-focusable w-full" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= $form['category_id'] === (int) $c['id'] ? ' selected' : '' ?>>
                        <?= Security::escape((string) $c['name']) ?><?= empty($c['is_active']) ? ' (inactive)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="at-label" for="question">Question</label>
            <input id="question" name="question" class="at-input at-focusable w-full" required maxlength="255"
                   value="<?= Security::escape($form['question']) ?>">
        </div>
        <div>
            <label class="at-label" for="answer">Answer</label>
            <textarea id="answer" name="answer" class="at-textarea at-focusable w-full" rows="5" required><?= Security::escape($form['answer']) ?></textarea>
        </div>
        <div>
            <label class="at-label" for="sort_order">Sort order</label>
            <input id="sort_order" name="sort_order" type="number" class="at-input at-focusable w-32"
                   value="<?= (int) $form['sort_order'] ?>">
        </div>
        <label class="flex gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>>
            Active (visible on public FAQs when category is active)
        </label>
        <div class="flex flex-wrap gap-2 pt-2">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save FAQ</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/faqs/index.php" class="at-btn at-btn--ghost at-focusable">Back to list</a>
            <?php if ($id): ?>
                <button type="submit" name="form_action" value="delete" class="at-btn at-btn--ghost at-focusable" onclick="return confirm('Delete this FAQ permanently?');">Delete</button>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php admin_layout_end();
