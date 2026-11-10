<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && PushSchema::tableReady('push_notification_templates')) {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE push_notification_templates
             SET title_template = ?, body_template = ?, click_url_template = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            trim((string) ($_POST['title_template'] ?? '')),
            trim((string) ($_POST['body_template'] ?? '')),
            trim((string) ($_POST['click_url_template'] ?? '')),
            !empty($_POST['is_active']) ? 1 : 0,
            $id,
        ]);
        admin_flash_set('Template updated.');
    }
    header('Location: ' . url_base() . '/admin/push/templates.php', true, 302);
    exit;
}

$rows = PushTemplateRepository::listAll();

admin_layout_start(['pageTitle' => 'Push templates', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification templates';
$pageDescription = 'Placeholders: {customer_name}, {order_number}, {order_total}, {status_label}, {order_id}';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<?php foreach ($rows as $tpl): ?>
<form method="post" class="at-card mb-4 max-w-3xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
        <p class="font-bold"><?= Security::escape((string) $tpl['template_key']) ?> <span class="at-muted font-normal">(<?= Security::escape((string) $tpl['event_type']) ?>)</span></p>
        <div><label class="at-label">Title</label><input class="at-input" name="title_template" value="<?= Security::escape((string) $tpl['title_template']) ?>"></div>
        <div><label class="at-label">Body</label><textarea class="at-input" name="body_template" rows="2"><?= Security::escape((string) $tpl['body_template']) ?></textarea></div>
        <div><label class="at-label">Click URL template</label><input class="at-input" name="click_url_template" value="<?= Security::escape((string) ($tpl['click_url_template'] ?? '')) ?>"></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= !empty($tpl['is_active']) ? 'checked' : '' ?>> Active</label>
        <button type="submit" class="at-btn at-btn--primary">Save</button>
    </div>
</form>
<?php endforeach; ?>

<?php admin_layout_end(); ?>
