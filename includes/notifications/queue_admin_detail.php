<?php

declare(strict_types=1);

/**
 * Read-only admin view model for notification_queue rows (delivery logs detail).
 */
final class NotificationQueueAdminDetailService
{
    public static function findJob(int $jobId): ?array
    {
        if ($jobId <= 0 || !NotificationQueueService::tableReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, event_type, channel, recipient, recipient_ref, template_key, payload_json, idempotency_key,
                    status, attempts, max_attempts, error_code, error_message,
                    created_at, updated_at, sent_at, processed_at, scheduled_at, next_retry_at, processing_started_at
             FROM notification_queue WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function viewModel(int $jobId): ?array
    {
        $job = self::findJob($jobId);
        if ($job === null) {
            return null;
        }

        $payload = self::decodePayload($job['payload_json'] ?? null);
        $sanitizedPayload = NotificationTemplateRenderer::sanitizePayloadForLog($payload);
        $channel = (string) ($job['channel'] ?? '');
        $status = (string) ($job['status'] ?? '');
        $templateKey = (string) ($job['template_key'] ?? '');
        $template = $templateKey !== '' ? NotificationTemplateRepository::findByKeyAny($templateKey) : null;

        $preview = self::regeneratedPreview($channel, $template, $payload);
        $relations = self::resolveRelations($job, $payload);
        $emailLogs = self::deliveryEmailLogsForQueue($jobId);
        $smtpFrom = self::smtpFromLabel();

        return [
            'job_id' => (int) $job['id'],
            'overview' => self::overviewBlock($job),
            'email' => $channel === 'email' ? self::emailBlock($job, $preview, $smtpFrom, $relations) : null,
            'sms' => $channel === 'sms' ? self::smsBlock($job, $preview) : null,
            'in_app' => $channel === 'in_app' ? self::inAppBlock($job, $preview) : null,
            'payload' => $sanitizedPayload,
            'payload_json_pretty' => self::prettyJson($sanitizedPayload),
            'preview' => $preview,
            'relations' => $relations,
            'delivery_email_logs' => $emailLogs,
            'attempt_notes' => self::attemptNotes($job, $emailLogs),
            'limitations' => self::limitations($channel, $emailLogs),
            'back_query' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function overviewBlock(array $job): array
    {
        $status = (string) ($job['status'] ?? '');
        $cancelledAt = $status === NotificationQueueService::STATUS_CANCELLED
            ? (string) ($job['processed_at'] ?? '')
            : null;
        $lastAttemptAt = (string) ($job['processing_started_at'] ?? '');
        if ($lastAttemptAt === '' && $status === NotificationQueueService::STATUS_FAILED) {
            $lastAttemptAt = (string) ($job['processed_at'] ?? '');
        }

        return [
            'id' => (int) ($job['id'] ?? 0),
            'event_type' => (string) ($job['event_type'] ?? ''),
            'channel' => (string) ($job['channel'] ?? ''),
            'channel_label' => self::channelLabel((string) ($job['channel'] ?? '')),
            'recipient' => (string) ($job['recipient'] ?? ''),
            'recipient_ref' => (string) ($job['recipient_ref'] ?? ''),
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'attempts' => (int) ($job['attempts'] ?? 0),
            'max_attempts' => (int) ($job['max_attempts'] ?? NotificationQueueService::DEFAULT_MAX_ATTEMPTS),
            'template_key' => (string) ($job['template_key'] ?? ''),
            'idempotency_key' => (string) ($job['idempotency_key'] ?? ''),
            'error_code' => (string) ($job['error_code'] ?? ''),
            'error_message' => self::safeErrorMessage($job),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
            'sent_at' => (string) ($job['sent_at'] ?? ''),
            'processed_at' => (string) ($job['processed_at'] ?? ''),
            'cancelled_at' => $cancelledAt,
            'last_attempt_at' => $lastAttemptAt,
            'scheduled_at' => (string) ($job['scheduled_at'] ?? ''),
            'next_retry_at' => (string) ($job['next_retry_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $template
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function regeneratedPreview(string $channel, ?array $template, array $payload): ?array
    {
        if ($template === null) {
            return [
                'available' => false,
                'is_regenerated' => true,
                'notice' => 'Template not found. Rendered body was not stored with this job.',
            ];
        }

        $rendered = NotificationTemplateRenderer::renderForChannel($template, $payload);
        $notice = 'The exact message delivered is not stored in the database. This preview re-renders the '
            . 'current template using variables saved on the queue job at enqueue time. '
            . 'If the template changed after send, the preview may differ from what the recipient received.';

        if ($channel === 'email') {
            $html = trim((string) ($rendered['html'] ?? ''));
            $safeHtml = '';
            if ($html !== '') {
                $san = NotificationEmailHtmlSanitizer::sanitize($html);
                $safeHtml = !empty($san['ok']) ? (string) $san['html'] : '';
            }

            return [
                'available' => true,
                'is_regenerated' => true,
                'stored_body_available' => false,
                'notice' => $notice,
                'subject' => trim((string) ($rendered['subject'] ?? '')),
                'html' => $safeHtml,
                'text' => trim((string) ($rendered['text'] ?? '')),
            ];
        }
        if ($channel === 'sms') {
            return [
                'available' => true,
                'is_regenerated' => true,
                'stored_body_available' => false,
                'notice' => $notice,
                'sms_body' => trim((string) ($rendered['sms'] ?? '')),
            ];
        }
        if ($channel === 'in_app') {
            return [
                'available' => true,
                'is_regenerated' => true,
                'stored_body_available' => false,
                'notice' => $notice,
                'title' => trim((string) ($rendered['in_app_title'] ?? '')),
                'body' => trim((string) ($rendered['in_app_body'] ?? '')),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed>|null $preview
     * @param list<array<string, mixed>> $relations
     * @return array<string, mixed>
     */
    private static function emailBlock(array $job, ?array $preview, ?string $smtpFrom, array $relations): array
    {
        return [
            'from' => $smtpFrom,
            'to' => (string) ($job['recipient'] ?? ''),
            'cc' => null,
            'bcc' => null,
            'subject' => $preview !== null ? (string) ($preview['subject'] ?? '') : '',
            'subject_is_regenerated' => true,
            'html' => $preview['html'] ?? '',
            'text' => $preview['text'] ?? '',
            'order_number' => self::relationLabel($relations, 'order'),
            'refund_request_number' => self::relationLabel($relations, 'refund'),
        ];
    }

    /** @param array<string, mixed>|null $preview */
    private static function smsBlock(array $job, ?array $preview): array
    {
        $mobile = (string) ($job['recipient'] ?? '');
        $provider = trim((string) (AppSettings::get('sms.provider', '') ?? ''));
        if ($provider === '') {
            $provider = 'simulate';
        }

        return [
            'mobile_masked' => self::maskMobileIndia($mobile),
            'body' => $preview !== null ? (string) ($preview['sms_body'] ?? '') : '',
            'body_is_regenerated' => true,
            'provider' => $provider,
            'provider_message_id' => null,
            'provider_status' => (string) ($job['status'] ?? ''),
            'provider_response' => self::safeErrorMessage($job),
        ];
    }

    /** @param array<string, mixed>|null $preview */
    private static function inAppBlock(array $job, ?array $preview): array
    {
        return [
            'recipient_ref' => (string) ($job['recipient_ref'] ?? ''),
            'title' => $preview !== null ? (string) ($preview['title'] ?? '') : '',
            'body' => $preview !== null ? (string) ($preview['body'] ?? '') : '',
            'content_is_regenerated' => true,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function resolveRelations(array $job, array $payload): array
    {
        $out = [];
        $base = url_base();
        $customerId = 0;
        $ref = (string) ($job['recipient_ref'] ?? '');
        if (preg_match('/^customer:(\d+)$/', $ref, $m)) {
            $customerId = (int) $m[1];
        }

        $orderId = isset($payload['order_id']) ? (int) $payload['order_id'] : 0;
        $orderNumber = trim((string) ($payload['order_number'] ?? ''));
        if ($orderId <= 0 && $orderNumber !== '' && Install::isSchemaReady()) {
            $orderId = self::orderIdFromNumber($orderNumber);
        }
        if ($orderId <= 0) {
            $orderId = self::orderIdFromIdempotency((string) ($job['idempotency_key'] ?? ''));
        }
        if ($orderId > 0) {
            $num = $orderNumber !== '' ? $orderNumber : self::orderNumberFromId($orderId);
            $out[] = [
                'kind' => 'order',
                'label' => $num !== '' ? $num : 'Order #' . $orderId,
                'href' => $base . '/admin/orders/view.php?id=' . $orderId,
            ];
            if ($customerId <= 0) {
                $customerId = self::customerIdForOrder($orderId);
            }
        }

        $requestNumber = trim((string) ($payload['request_number'] ?? ''));
        $refundId = self::refundIdFromIdempotency((string) ($job['idempotency_key'] ?? ''));
        if ($refundId <= 0 && $requestNumber !== '') {
            $refundId = self::refundIdFromNumber($requestNumber);
        }
        if ($refundId > 0) {
            $label = $requestNumber !== '' ? $requestNumber : 'Refund #' . $refundId;
            $out[] = [
                'kind' => 'refund',
                'label' => $label,
                'href' => $base . '/admin/refunds/index.php?id=' . $refundId,
            ];
        }

        if ($customerId > 0 && AdminDemoPerms::can('customers')) {
            $name = self::customerName($customerId);
            $out[] = [
                'kind' => 'customer',
                'label' => $name !== '' ? $name : 'Customer #' . $customerId,
                'href' => $base . '/admin/customers/view.php?id=' . $customerId,
            ];
        }

        $ticketNumber = trim((string) ($payload['ticket_number'] ?? ''));
        if ($ticketNumber !== '') {
            $ticketId = self::ticketIdFromNumber($ticketNumber);
            if ($ticketId > 0) {
                $out[] = [
                    'kind' => 'support',
                    'label' => $ticketNumber,
                    'href' => $base . '/admin/support/index.php?id=' . $ticketId,
                ];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function deliveryEmailLogsForQueue(int $queueId): array
    {
        if ($queueId <= 0 || !DeliveryOrderEmailService::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, order_id, event_key, status, attempt_number, error_message, created_at, sent_at
             FROM delivery_email_logs WHERE queue_id = ? ORDER BY attempt_number ASC, id ASC'
        );
        $stmt->execute([$queueId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'source' => 'delivery_email_logs',
                'attempt_number' => (int) ($row['attempt_number'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'started_at' => (string) ($row['created_at'] ?? ''),
                'completed_at' => (string) ($row['sent_at'] ?? ''),
                'error' => (string) ($row['error_message'] ?? ''),
                'order_id' => (int) ($row['order_id'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $job
     * @param list<array<string, mixed>> $emailLogs
     * @return list<string>
     */
    private static function attemptNotes(array $job, array $emailLogs): array
    {
        $notes = [];
        $attempts = (int) ($job['attempts'] ?? 0);
        if ($attempts > 0) {
            $notes[] = 'Queue row records ' . $attempts . ' transport attempt(s); per-attempt timestamps are not stored except on linked delivery email logs.';
        } else {
            $notes[] = 'No transport attempts recorded yet on this queue row.';
        }
        if ($emailLogs === []) {
            $notes[] = 'No delivery_email_logs rows linked to this queue job.';
        }

        return $notes;
    }

    /**
     * @param list<array<string, mixed>> $emailLogs
     * @return list<string>
     */
    private static function limitations(string $channel, array $emailLogs): array
    {
        $limits = [
            'Rendered email/SMS/in-app body is not persisted on notification_queue — only template_key and payload_json.',
        ];
        if ($channel === 'email' && $emailLogs === []) {
            $limits[] = 'delivery_email_logs may exist for some order emails; when absent, only queue metadata is available.';
        }
        if ($channel === 'sms') {
            $limits[] = 'Delivery-status SMS uses delivery_sms_logs separately and is not linked to notification_queue rows.';
        }
        $limits[] = 'Per-attempt provider responses are not stored in a dedicated attempt history table.';

        return $limits;
    }

    /** @return array<string, mixed> */
    private static function decodePayload(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @param array<string, mixed> $payload */
    private static function prettyJson(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    private static function safeErrorMessage(array $job): string
    {
        $code = (string) ($job['error_code'] ?? '');
        $msg = trim((string) ($job['error_message'] ?? ''));
        if ($msg === '' && $code !== '') {
            return NotificationQueueService::publicErrorMessage($code);
        }
        if ($msg !== '' && self::looksSensitive($msg)) {
            return NotificationQueueService::publicErrorMessage($code !== '' ? $code : 'send_failed');
        }

        return $msg;
    }

    private static function looksSensitive(string $msg): bool
    {
        $lower = strtolower($msg);

        return str_contains($lower, 'password')
            || str_contains($lower, 'api_key')
            || str_contains($lower, 'authorization')
            || str_contains($lower, 'bearer ');
    }

    private static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'email' => 'Email',
            'sms' => 'SMS',
            'in_app' => 'In-app',
            default => $channel !== '' ? ucfirst($channel) : '—',
        };
    }

    private static function smtpFromLabel(): ?string
    {
        try {
            $from = trim((string) (AppSettings::get(SmtpSettingsService::KEY_FROM_EMAIL, '') ?? ''));
            $name = trim((string) (AppSettings::get(SmtpSettingsService::KEY_FROM_NAME, '') ?? ''));
            if ($from === '') {
                return null;
            }

            return $name !== '' ? $name . ' <' . $from . '>' : $from;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function maskMobileIndia(string $mobile): string
    {
        $digits = Validation::normalizeMobileIndia($mobile);
        if ($digits === null || strlen($digits) < 4) {
            return '••••';
        }

        return '••••••' . substr($digits, -4);
    }

    /**
     * @param list<array<string, mixed>> $relations
     */
    private static function relationLabel(array $relations, string $kind): ?string
    {
        foreach ($relations as $rel) {
            if (($rel['kind'] ?? '') === $kind) {
                return (string) ($rel['label'] ?? '');
            }
        }

        return null;
    }

    private static function orderIdFromNumber(string $orderNumber): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function orderNumberFromId(int $orderId): string
    {
        $stmt = Database::pdo()->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    private static function customerIdForOrder(int $orderId): int
    {
        $stmt = Database::pdo()->prepare('SELECT customer_id FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function customerName(int $customerId): string
    {
        $stmt = Database::pdo()->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $name = trim((string) ($stmt->fetchColumn() ?: ''));

        return $name;
    }

    private static function refundIdFromNumber(string $requestNumber): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM refund_requests WHERE request_number = ? LIMIT 1');
        $stmt->execute([$requestNumber]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function ticketIdFromNumber(string $ticketNumber): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1');
        $stmt->execute([$ticketNumber]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private static function orderIdFromIdempotency(string $key): int
    {
        if (preg_match('/:order:(\d+):/', $key, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private static function refundIdFromIdempotency(string $key): int
    {
        if (preg_match('/^refund\.[^:]+:(\d+):/', $key, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
