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
$pageDescription = 'Use {placeholders} replaced at send time. Unsupported placeholders are removed automatically.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<div class="at-alert at-alert--info mb-4 max-w-3xl text-sm">
    <p class="font-medium mb-1">Supported variables (by event)</p>
    <ul class="list-disc ml-5 space-y-1">
        <li>Orders: <code>{customer_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_id}</code>, <code>{status_label}</code></li>
        <li>Refunds: <code>{customer_name}</code>, <code>{request_number}</code>, <code>{status_label}</code></li>
        <li>Subscriptions: <code>{customer_name}</code></li>
        <li>Manual / announcement templates: <code>{title}</code>, <code>{body}</code>, <code>{click_url}</code> (as supplied by the event payload)</li>
    </ul>
</div>

<?php foreach ($rows as $tpl): ?>
<form method="post" class="at-card mb-4 max-w-3xl">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
        <div>
            <p class="font-bold"><?= Security::escape((string) $tpl['template_key']) ?></p>
            <p class="text-xs at-muted">Notification type (event): <code><?= Security::escape((string) $tpl['event_type']) ?></code> — template key is fixed in code and migrations.</p>
        </div>
        <div>
            <label class="at-label" for="title-<?= (int) $tpl['id'] ?>">Notification title</label>
            <input class="at-input at-focusable" id="title-<?= (int) $tpl['id'] ?>" name="title_template"
                   placeholder="e.g. Order confirmed"
                   value="<?= Security::escape((string) $tpl['title_template']) ?>">
        </div>
        <div>
            <label class="at-label" for="body-<?= (int) $tpl['id'] ?>">Notification body</label>
            <textarea class="at-input at-focusable" id="body-<?= (int) $tpl['id'] ?>" name="body_template" rows="2"
                      placeholder="e.g. Hi {customer_name}, order {order_number} is confirmed. Total {order_total}."><?= Security::escape((string) $tpl['body_template']) ?></textarea>
        </div>
        <div>
            <label class="at-label" for="url-<?= (int) $tpl['id'] ?>">Click URL</label>
            <input class="at-input at-focusable" id="url-<?= (int) $tpl['id'] ?>" name="click_url_template"
                   placeholder="e.g. /order-details.php?id={order_id}"
                   value="<?= Security::escape((string) ($tpl['click_url_template'] ?? '')) ?>">
            <p class="text-xs at-muted mt-1">Relative path on your storefront or absolute URL on the canonical site host.</p>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" <?= !empty($tpl['is_active']) ? 'checked' : '' ?>> Active</label>
        <button type="submit" class="at-btn at-btn--primary">Save</button>
    </div>
</form>
<?php endforeach; ?>

<?php admin_layout_end(); ?>
