<?php

declare(strict_types=1);

final class NotificationTemplateAdminService
{
    /** @return list<array<string, mixed>> */
    public static function listEmailTemplates(): array
    {
        if (!NotificationTemplateRepository::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            "SELECT id, template_key, event_type, channel, subject, is_active, updated_at
             FROM notification_templates WHERE channel = 'email' ORDER BY event_type ASC, template_key ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public static function getEmailTemplate(string $templateKey): ?array
    {
        return NotificationTemplateRepository::findByKeyAny($templateKey);
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>, warnings?: list<string>}
     */
    public static function saveEmailTemplate(string $templateKey, string $subject, string $bodyHtml, bool $isActive): array
    {
        $row = self::getEmailTemplate($templateKey);
        if ($row === null || (string) ($row['channel'] ?? '') !== 'email') {
            return ['ok' => false, 'message' => 'Template not found.'];
        }
        $eventType = (string) ($row['event_type'] ?? '');
        $subject = trim($subject);
        if ($subject === '') {
            return ['ok' => false, 'errors' => ['subject' => 'Subject is required.']];
        }
        if (mb_strlen($subject) > 255) {
            return ['ok' => false, 'errors' => ['subject' => 'Subject must be 255 characters or fewer.']];
        }

        $san = NotificationEmailHtmlSanitizer::sanitize($bodyHtml);
        if (empty($san['ok'])) {
            return ['ok' => false, 'errors' => ['body_html' => (string) ($san['message'] ?? 'Invalid HTML.')]];
        }
        $cleanHtml = (string) $san['html'];
        $bodyText = self::htmlToPlainText($cleanHtml);

        $unknown = array_merge(
            NotificationTemplateVariables::findUnknownVariables($subject, $eventType),
            NotificationTemplateVariables::findUnknownVariables($cleanHtml, $eventType),
            NotificationTemplateVariables::findUnknownVariables($bodyText, $eventType)
        );
        $unknown = array_values(array_unique($unknown));

        if (!NotificationTemplateRepository::updateEmailTemplate($templateKey, $subject, $cleanHtml, $bodyText, $isActive)) {
            return ['ok' => false, 'message' => 'Could not save template.'];
        }

        AuditLog::write('notification_template.updated', 'notification_templates', $templateKey, [
            'event_type' => $eventType,
            'is_active' => $isActive ? 1 : 0,
            'unknown_variables' => $unknown,
        ]);

        $out = ['ok' => true, 'message' => 'Email template saved.'];
        if ($unknown !== []) {
            $out['warnings'] = $unknown;
        }

        return $out;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function restoreDefault(string $templateKey): array
    {
        $def = NotificationTemplateDefaults::forKey($templateKey);
        $row = self::getEmailTemplate($templateKey);
        if ($def === null || $row === null) {
            return ['ok' => false, 'message' => 'Template not found or no default available.'];
        }

        if (!NotificationTemplateRepository::updateEmailTemplate(
            $templateKey,
            $def['subject'],
            $def['body_html'],
            $def['body_text'],
            true
        )) {
            return ['ok' => false, 'message' => 'Could not restore default.'];
        }

        AuditLog::write('notification_template.restored', 'notification_templates', $templateKey, []);

        return ['ok' => true, 'message' => 'Default template restored.'];
    }

    /**
     * @param array<string, mixed>|null $variablesOverride
     * @return array{ok: bool, subject?: string, html?: string, text?: string, message?: string, warnings?: list<string>}
     */
    public static function preview(string $templateKey, ?string $subjectOverride, ?string $bodyHtmlOverride, ?array $variablesOverride = null): array
    {
        $row = self::getEmailTemplate($templateKey);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Template not found.'];
        }
        $eventType = (string) ($row['event_type'] ?? '');
        $subject = $subjectOverride !== null ? trim($subjectOverride) : (string) ($row['subject'] ?? '');
        $bodyHtml = $bodyHtmlOverride !== null ? $bodyHtmlOverride : (string) ($row['body_html'] ?? '');

        if ($bodyHtmlOverride !== null) {
            $san = NotificationEmailHtmlSanitizer::sanitize($bodyHtml);
            if (empty($san['ok'])) {
                return ['ok' => false, 'message' => (string) ($san['message'] ?? 'Invalid HTML.')];
            }
            $bodyHtml = (string) $san['html'];
        }

        $vars = $variablesOverride ?? NotificationTemplateVariables::samplePreviewPayload($eventType);
        $draft = $row;
        $draft['subject'] = $subject;
        $draft['body_html'] = $bodyHtml;
        $draft['body_text'] = self::htmlToPlainText($bodyHtml);

        $rendered = NotificationTemplateRenderer::renderForChannel($draft, $vars);
        $unknown = array_merge(
            NotificationTemplateVariables::findUnknownVariables($subject, $eventType),
            NotificationTemplateVariables::findUnknownVariables($bodyHtml, $eventType)
        );

        return [
            'ok' => true,
            'subject' => (string) ($rendered['subject'] ?? ''),
            'html' => (string) ($rendered['html'] ?? ''),
            'text' => (string) ($rendered['text'] ?? ''),
            'warnings' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * @param array<string, mixed>|null $variablesOverride
     * @return array{ok: bool, message?: string}
     */
    public static function sendTestEmail(
        string $templateKey,
        string $recipient,
        ?string $subjectOverride,
        ?string $bodyHtmlOverride,
        ?array $variablesOverride = null
    ): array {
        if (SmtpSettingsService::readinessError() !== null) {
            return ['ok' => false, 'message' => 'SMTP is not configured or enabled.'];
        }
        $recipient = trim($recipient);
        if (!Validation::email($recipient)) {
            return ['ok' => false, 'message' => 'Enter a valid test recipient email.'];
        }

        $preview = self::preview($templateKey, $subjectOverride, $bodyHtmlOverride, $variablesOverride);
        if (empty($preview['ok'])) {
            return ['ok' => false, 'message' => (string) ($preview['message'] ?? 'Preview failed.')];
        }

        $subject = '[Apni Tokri test] ' . (string) ($preview['subject'] ?? 'Template test');
        $text = (string) ($preview['text'] ?? '');
        $html = (string) ($preview['html'] ?? '');
        if ($text === '' && $html === '') {
            return ['ok' => false, 'message' => 'Template rendered empty content.'];
        }

        $mailer = new SmtpMailer();
        $send = $mailer->send($recipient, $subject, $text !== '' ? $text : strip_tags($html), $html !== '' ? $html : null);
        if (empty($send['ok'])) {
            AuditLog::write('notification_template.test_failed', 'notification_templates', $templateKey, [
                'recipient_domain' => self::emailDomain($recipient),
            ]);

            return ['ok' => false, 'message' => 'Test email could not be sent. Check SMTP settings.'];
        }

        AuditLog::write('notification_template.test_sent', 'notification_templates', $templateKey, [
            'recipient_domain' => self::emailDomain($recipient),
        ]);

        return ['ok' => true, 'message' => 'Test email sent to ' . $recipient . '.'];
    }

    public static function adminTestRecipientDefault(): string
    {
        $id = AdminAuth::id();
        if ($id === null || !Install::isSchemaReady()) {
            return '';
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT email FROM admin_users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$id]);
        $email = trim((string) ($stmt->fetchColumn() ?: ''));

        return Validation::email($email) ? $email : '';
    }

    private static function htmlToPlainText(string $html): string
    {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at !== false ? substr($email, $at + 1) : '';
    }
}
