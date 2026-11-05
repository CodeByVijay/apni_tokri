<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('notifications');

if (!NotificationTemplateRepository::tableReady()) {
    admin_layout_start(['pageTitle' => 'Email templates', 'activeAdminNav' => 'settings']);
    echo '<div class="at-alert at-alert--error">Notification templates table is missing. Apply database migrations.</div>';
    admin_layout_end();
    exit;
}

admin_layout_start(['pageTitle' => 'Email templates', 'activeAdminNav' => 'settings']);
$pageHeading = 'Email templates';
$pageDescription = 'Customer notification emails (payment, orders, refunds). Branded wrapper is applied automatically.';
$breadcrumbs = [
    ['label' => 'Settings', 'href' => url_base() . '/admin/settings/index.php'],
    ['label' => 'Email templates'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'email_templates';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';

$templates = NotificationTemplateAdminService::listEmailTemplates();
?>
<div class="at-card">
    <div class="at-card__body">
        <?php if ($templates === []): ?>
            <p class="text-sm at-muted">No email templates found.</p>
        <?php else: ?>
            <div class="at-admin-table-wrap">
                <table class="at-admin-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Template key</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $tpl): ?>
                        <tr>
                            <td><?= Security::escape(NotificationTemplateVariables::eventTypeLabel((string) ($tpl['event_type'] ?? ''))) ?></td>
                            <td class="is-id"><code><?= Security::escape((string) ($tpl['template_key'] ?? '')) ?></code></td>
                            <td><?= Security::escape((string) ($tpl['subject'] ?? '')) ?></td>
                            <td>
                                <?php if (!empty($tpl['is_active'])): ?>
                                    <span class="at-badge at-badge--success">Active</span>
                                <?php else: ?>
                                    <span class="at-badge at-badge--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="is-actions">
                                <a class="at-link-arrow at-focusable" href="<?= Security::escape(url_base()) ?>/admin/settings/email-templates/edit.php?key=<?= rawurlencode((string) ($tpl['template_key'] ?? '')) ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php admin_layout_end(); ?>
