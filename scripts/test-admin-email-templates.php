<?php

declare(strict_types=1);

/**
 * Admin email template management (no real SMTP sends).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-admin-email-templates.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!NotificationTemplateRepository::tableReady()) {
    fwrite(STDERR, "notification_templates missing.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function okT(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function applyMigration130(PDO $pdo): void
{
    if ((int) $pdo->query("SELECT COUNT(*) FROM notification_templates WHERE template_key = 'order.placed.email'")->fetchColumn() > 0) {
        return;
    }
    $sql = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.30.0_customer_order_email_notifications.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $chunk) {
        if ($chunk === '' || stripos($chunk, 'SET NAMES') === 0) {
            continue;
        }
        $pdo->exec($chunk);
    }
}

applyMigration130($pdo);

$key = 'payment.success.email';
$orig = NotificationTemplateAdminService::getEmailTemplate($key);
okT($orig !== null, 'Fixture template exists');

$unknownPreview = NotificationTemplateAdminService::preview(
    $key,
    'Hi {{not_a_real_variable}}',
    '<p>{{customer_name}} {{bogus_var}}</p>'
);
okT(!empty($unknownPreview['ok']), 'Preview succeeds with sample data');
okT(in_array('not_a_real_variable', $unknownPreview['warnings'] ?? [], true), 'Unknown subject variable reported');
okT(in_array('bogus_var', $unknownPreview['warnings'] ?? [], true), 'Unknown body variable reported');

$save = NotificationTemplateAdminService::saveEmailTemplate(
    $key,
    'Test subject {{order_number}}',
    '<p>Hello {{customer_name}}</p><script>alert(1)</script>',
    true
);
okT(!empty($save['ok']), 'Save template succeeds');
$row = NotificationTemplateRepository::findByKeyAny($key);
okT(strpos((string) ($row['body_html'] ?? ''), '<script') === false, 'Script stripped on save');
okT((string) ($row['subject'] ?? '') === 'Test subject {{order_number}}', 'Subject persisted');

$preview = NotificationTemplateAdminService::preview($key, null, null);
okT(!empty($preview['ok']) && strpos((string) ($preview['html'] ?? ''), '#2d7048') !== false, 'Preview includes branded wrapper');
okT(strpos((string) ($preview['html'] ?? ''), 'Sample Customer') !== false, 'Preview uses sample customer name');

NotificationTemplateAdminService::saveEmailTemplate($key, 'Disabled test', '<p>x</p>', false);
okT(NotificationTemplateRepository::findByKey($key) === null, 'Inactive template not returned for queue send');

$disabledRow = NotificationTemplateRepository::findByKeyAny($key);
okT($disabledRow !== null && empty($disabledRow['is_active']), 'Inactive row still in DB for admin');

$restore = NotificationTemplateAdminService::restoreDefault($key);
okT(!empty($restore['ok']), 'Restore default succeeds');
$def = NotificationTemplateDefaults::forKey($key);
$restored = NotificationTemplateRepository::findByKeyAny($key);
okT($def !== null && (string) ($restored['subject'] ?? '') === $def['subject'], 'Restored subject matches default');
okT(!empty($restored['is_active']), 'Restore sets active');

okT(NotificationTemplateAdminService::getEmailTemplate('no.such.template.email') === null, 'Missing template returns null');
okT(
    empty(NotificationTemplateAdminService::saveEmailTemplate('no.such.template.email', 'x', '<p>x</p>', true)['ok']),
    'Save missing template fails'
);

SmtpMailer::setStreamConnector(static function (): mixed {
    return false;
});
$send = NotificationTemplateAdminService::sendTestEmail($key, 'admin@test.example', null, null);
okT(empty($send['ok']), 'Test send fails gracefully with mocked SMTP (no real mail)');

$audit = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type = 'notification_templates' AND entity_id = " . $pdo->quote($key)
)->fetchColumn();
okT($audit >= 1, 'Template changes write audit log');

$indexPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/email-templates/index.php');
$editPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/email-templates/edit.php');
okT(str_contains($indexPhp, "denyIfCannot('notifications')"), 'Index gated by notifications permission');
okT(str_contains($editPhp, 'Csrf::field()'), 'Edit form uses CSRF');
okT(substr_count($editPhp, '<form') >= 3, 'Edit page uses separate forms (save, restore, test — not nested)');

// Restore original subject/body if we had a fixture
if ($orig !== null) {
    NotificationTemplateRepository::updateEmailTemplate(
        $key,
        (string) ($orig['subject'] ?? $def['subject'] ?? ''),
        (string) ($orig['body_html'] ?? $def['body_html'] ?? ''),
        (string) ($orig['body_text'] ?? $def['body_text'] ?? ''),
        !empty($orig['is_active'])
    );
}

SmtpMailer::setStreamConnector(null);

echo $fail === 0 ? "All admin email template tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
