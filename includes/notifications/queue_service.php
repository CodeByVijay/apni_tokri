<?php

declare(strict_types=1);

/**
 * MySQL-backed notification queue (Stage 14.4).
 *
 * External delivery is at-least-once: if SMTP/SMS accepts a message and the worker
 * crashes before marking the row sent, lease recovery may retry and duplicate delivery
 * until idempotency or provider-side dedupe applies. We do not claim exactly-once delivery.
 */
final class NotificationQueueService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Lease must exceed worst-case transport time plus recovery margin (see recommendedLeaseSeconds). */
    public const LEASE_SECONDS = 180;
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_BATCH = 10;
    public const MAX_BATCH = 20;
    public const MAX_WORKER_RUNTIME_SECONDS = 45;

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_queue'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, created?: bool, id?: int, duplicate?: bool}
     */
    public static function enqueue(
        string $eventType,
        string $channel,
        string $recipient,
        ?string $recipientRef,
        string $templateKey,
        array $payload,
        string $idempotencyKey,
        ?string $scheduledAt = null
    ): array {
        if (!self::tableReady()) {
            return ['ok' => false];
        }
        if (!in_array($channel, ['email', 'sms', 'in_app'], true)) {
            return ['ok' => false];
        }
        if ($recipient === '' || $templateKey === '' || $idempotencyKey === '') {
            return ['ok' => false];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false];
        }

        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'INSERT INTO notification_queue
                 (event_type, channel, recipient, recipient_ref, template_key, payload_json, status,
                  idempotency_key, max_attempts, scheduled_at, next_retry_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $eventType,
                $channel,
                $recipient,
                $recipientRef,
                $templateKey,
                $json,
                self::STATUS_PENDING,
                $idempotencyKey,
                self::DEFAULT_MAX_ATTEMPTS,
                $scheduledAt,
                $scheduledAt,
            ]);

            return ['ok' => true, 'created' => true, 'id' => (int) Database::pdo()->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => true, 'created' => false, 'duplicate' => true];
            }
            error_log('NotificationQueueService::enqueue failed');

            return ['ok' => false];
        }
    }

    public static function recoverStaleProcessing(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
             WHERE status = ? AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
        );
        $stmt->execute([self::STATUS_PENDING, self::STATUS_PROCESSING]);

        return $stmt->rowCount();
    }

    /**
     * @return list<int>
     */
    public static function claimBatch(int $limit = self::DEFAULT_BATCH): array
    {
        if (!self::tableReady()) {
            return [];
        }

        return Database::transaction(static function () use ($limit): array {
            $pdo = Database::pdo();
            self::recoverStaleProcessing();
            $stmt = $pdo->prepare(
                "SELECT id FROM notification_queue
                 WHERE status = ?
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                   AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                 ORDER BY id ASC
                 LIMIT " . (int) $limit . ' FOR UPDATE'
            );
            $stmt->execute([self::STATUS_PENDING]);
            $ids = [];
            $leaseSec = self::recommendedLeaseSeconds();
            $upd = $pdo->prepare(
                "UPDATE notification_queue SET status = ?, lease_token = ?, lease_expires_at = ?, processing_started_at = NOW()
                 WHERE id = ? AND status = ?"
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (int) $row['id'];
                $token = Security::randomToken(16);
                $expires = date('Y-m-d H:i:s', time() + $leaseSec);
                $upd->execute([self::STATUS_PROCESSING, $token, $expires, $id, self::STATUS_PENDING]);
                if ($upd->rowCount() === 1) {
                    $ids[] = $id;
                }
            }

            return $ids;
        });
    }

    public static function resolveBatchSize(?int $override = null): int
    {
        $raw = $override;
        if ($raw === null) {
            $env = getenv('NOTIFICATION_QUEUE_BATCH');
            $raw = $env !== false && $env !== '' ? (int) $env : self::DEFAULT_BATCH;
        }
        if ($raw < 1) {
            $raw = self::DEFAULT_BATCH;
        }

        return min(self::MAX_BATCH, $raw);
    }

    public static function resolveMaxRuntimeSeconds(?int $override = null): int
    {
        $raw = $override;
        if ($raw === null) {
            $env = getenv('NOTIFICATION_QUEUE_MAX_RUNTIME');
            $raw = $env !== false && $env !== '' ? (int) $env : self::MAX_WORKER_RUNTIME_SECONDS;
        }
        if ($raw < 10) {
            $raw = self::MAX_WORKER_RUNTIME_SECONDS;
        }

        return min(120, $raw);
    }

    /** Upper bound for a single SMTP or HTTP SMS call (seconds). */
    public static function maxTransportTimeoutSeconds(): int
    {
        $smtp = (int) (AppSettings::get(SmtpSettingsService::KEY_TIMEOUT, '15') ?? '15');
        if ($smtp < 1) {
            $smtp = 15;
        }
        if ($smtp > 120) {
            $smtp = 120;
        }

        return max($smtp, SmsHttpResponsePolicy::timeoutSeconds());
    }

    /** Do not start another job unless at least this many seconds remain in the worker budget. */
    public static function minSecondsRequiredToStartJob(): int
    {
        $transport = self::maxTransportTimeoutSeconds();
        $cap = max(1, self::MAX_WORKER_RUNTIME_SECONDS - 3);

        return min($cap, $transport + 2);
    }

    public static function recommendedLeaseSeconds(): int
    {
        return max(self::LEASE_SECONDS, self::maxTransportTimeoutSeconds() + 60);
    }

    /**
     * One-shot worker pass for cron (no persistent loop). Claims at most $batchLimit jobs,
     * respecting $maxRuntimeSeconds checked between claims and between jobs.
     *
     * @return array{
     *   recovered: int,
     *   claimed: int,
     *   sent: int,
     *   failed: int,
     *   retry_scheduled: int,
     *   stop_reason: string
     * }
     */
    public static function runWorker(int $batchLimit, int $maxRuntimeSeconds, ?float $startedAt = null): array
    {
        $startedAt = $startedAt ?? microtime(true);
        $deadline = $startedAt + $maxRuntimeSeconds;
        $recovered = self::recoverStaleProcessing();
        $claimed = 0;
        $sent = 0;
        $failed = 0;
        $retryScheduled = 0;
        $stopReason = 'empty_queue';

        while ($claimed < $batchLimit) {
            $remainingBudget = $deadline - microtime(true);
            if ($remainingBudget < self::minSecondsRequiredToStartJob()) {
                $stopReason = $claimed > 0 ? 'budget_after_processing' : 'budget_before_claim';
                break;
            }

            $ids = self::claimBatch(1);
            if ($ids === []) {
                $stopReason = $claimed > 0 ? 'batch_complete' : 'empty_queue';
                break;
            }

            foreach ($ids as $id) {
                $claimed++;
                $result = self::processJob((int) $id);
                if (!empty($result['ok'])) {
                    $sent++;
                } elseif (!empty($result['permanent'])) {
                    $failed++;
                } else {
                    $retryScheduled++;
                }

                if ($claimed >= $batchLimit) {
                    $stopReason = 'batch_limit';
                    break 2;
                }

                if ($deadline - microtime(true) < self::minSecondsRequiredToStartJob()) {
                    $stopReason = 'budget_after_processing';
                    break 2;
                }
            }
        }

        if ($claimed >= $batchLimit && $stopReason === 'empty_queue') {
            $stopReason = 'batch_limit';
        }

        return [
            'recovered' => $recovered,
            'claimed' => $claimed,
            'sent' => $sent,
            'failed' => $failed,
            'retry_scheduled' => $retryScheduled,
            'stop_reason' => $stopReason,
        ];
    }

    /**
     * Claim and process a single pending job by idempotency key (does not touch other queue rows).
     *
     * @return array{ok: bool, processed?: bool, duplicate?: bool, error?: string, permanent?: bool}
     */
    public static function claimAndProcessByIdempotencyKey(string $channel, string $idempotencyKey): array
    {
        if (!self::tableReady() || $idempotencyKey === '' || !in_array($channel, ['email', 'sms', 'in_app'], true)) {
            return ['ok' => false, 'error' => 'invalid_request'];
        }

        try {
            $jobId = Database::transaction(static function () use ($channel, $idempotencyKey): int {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT id FROM notification_queue
                     WHERE channel = ? AND idempotency_key = ? AND status = ?
                       AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                       AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                     LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$channel, $idempotencyKey, self::STATUS_PENDING]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return 0;
                }
                $id = (int) $row['id'];
                $token = Security::randomToken(16);
                $expires = date('Y-m-d H:i:s', time() + self::recommendedLeaseSeconds());
                $upd = $pdo->prepare(
                    'UPDATE notification_queue SET status = ?, lease_token = ?, lease_expires_at = ?, processing_started_at = NOW()
                     WHERE id = ? AND status = ?'
                );
                $upd->execute([self::STATUS_PROCESSING, $token, $expires, $id, self::STATUS_PENDING]);
                if ($upd->rowCount() !== 1) {
                    return 0;
                }

                return $id;
            });

            if ($jobId <= 0) {
                return ['ok' => true, 'processed' => false, 'duplicate' => true];
            }

            $result = self::processJob($jobId);

            return array_merge(['processed' => !empty($result['ok'])], $result);
        } catch (Throwable $e) {
            error_log('NotificationQueueService::claimAndProcessByIdempotencyKey');

            return ['ok' => false, 'error' => 'claim_failed'];
        }
    }

    /**
     * @return array{ok: bool, permanent?: bool, error?: string}
     */
    public static function processJob(int $jobId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'error' => 'queue_unavailable'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM notification_queue WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job || (string) ($job['status'] ?? '') !== self::STATUS_PROCESSING) {
            return ['ok' => false, 'error' => 'not_claimed'];
        }

        $leaseToken = (string) ($job['lease_token'] ?? '');
        $eventType = (string) ($job['event_type'] ?? '');
        $templateKey = (string) ($job['template_key'] ?? '');
        if ($eventType === 'order.placed' || $templateKey === 'order.placed.email') {
            $orderId = OrderEmailContext::orderIdFromPlacedEmailIdempotencyKey((string) ($job['idempotency_key'] ?? ''));
            if ($orderId !== null && !OrderEmailContext::shouldSendPendingPaymentEmail($orderId)) {
                self::markCancelledWithLease($jobId, $leaseToken, 'order_already_paid', 'Pending payment email no longer applicable.');

                return ['ok' => true, 'skipped' => true];
            }
        }

        $payload = [];
        if (!empty($job['payload_json'])) {
            try {
                $decoded = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                $payload = is_array($decoded) ? $decoded : [];
            } catch (Throwable $e) {
                self::markFailed($jobId, $leaseToken, 'malformed_payload', 'Notification payload is invalid.', true);

                return ['ok' => false, 'permanent' => true];
            }
        }

        $template = NotificationTemplateRepository::findByKey($templateKey);
        if ($template === null) {
            self::markFailed($jobId, $leaseToken, 'missing_template', 'Template not found or inactive.', true);

            return ['ok' => false, 'permanent' => true];
        }

        $rendered = NotificationTemplateRenderer::renderForChannel($template, $payload);
        $channel = (string) ($job['channel'] ?? '');

        try {
            if ($channel === 'email') {
                $result = self::sendEmail($job, $rendered);
            } elseif ($channel === 'sms') {
                $result = self::sendSms($job, $rendered);
            } elseif ($channel === 'in_app') {
                $result = self::sendInApp($job, $rendered);
            } else {
                self::markFailed($jobId, $leaseToken, 'bad_channel', 'Unsupported channel.', true);

                return ['ok' => false, 'permanent' => true];
            }
        } catch (Throwable $e) {
            error_log('NotificationQueueService::processJob transport error');
            $result = ['ok' => false, 'error' => 'transport_error', 'retryable' => true];
        }

        if (!empty($result['ok'])) {
            if (self::markSentWithLease($jobId, $leaseToken)) {
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'lease_lost'];
        }

        $retryable = !empty($result['retryable']);
        $permanent = !empty($result['permanent']) || !$retryable;
        self::markFailed(
            $jobId,
            $leaseToken,
            (string) ($result['error'] ?? 'send_failed'),
            self::publicErrorMessage((string) ($result['error'] ?? '')),
            $permanent
        );

        return ['ok' => false, 'permanent' => $permanent];
    }

    /** Fence completion so a stale worker cannot overwrite a newer claim. */
    public static function markSentWithLease(int $jobId, string $leaseToken): bool
    {
        if ($leaseToken === '') {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, sent_at = NOW(), processed_at = NOW(),
             error_code = NULL, error_message = NULL, lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
             WHERE id = ? AND status = ? AND lease_token = ?"
        );
        $stmt->execute([self::STATUS_SENT, $jobId, self::STATUS_PROCESSING, $leaseToken]);

        return $stmt->rowCount() === 1;
    }

    public static function markCancelledWithLease(
        int $jobId,
        string $leaseToken,
        string $errorCode,
        string $errorMessage
    ): bool {
        if ($leaseToken === '') {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, processed_at = NOW(),
             error_code = ?, error_message = ?, lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
             WHERE id = ? AND status = ? AND lease_token = ?"
        );
        $stmt->execute([
            self::STATUS_CANCELLED,
            mb_substr($errorCode, 0, 64),
            mb_substr($errorMessage, 0, 255),
            $jobId,
            self::STATUS_PROCESSING,
            $leaseToken,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Drop stale pending-payment email when payment captured (idempotent).
     */
    public static function cancelPendingByIdempotencyKey(string $idempotencyKey, string $reasonCode = 'superseded'): bool
    {
        if (!self::tableReady() || $idempotencyKey === '') {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, processed_at = NOW(), error_code = ?,
             error_message = 'Cancelled before send.', lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
             WHERE idempotency_key = ? AND status IN (?, ?)"
        );
        $stmt->execute([
            self::STATUS_CANCELLED,
            mb_substr($reasonCode, 0, 64),
            $idempotencyKey,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $rendered
     * @return array{ok: bool, error?: string, retryable?: bool, permanent?: bool}
     */
    private static function sendEmail(array $job, array $rendered): array
    {
        if (SmtpSettingsService::readinessError() !== null) {
            return ['ok' => false, 'error' => 'smtp_disabled', 'retryable' => true];
        }
        $to = trim((string) ($job['recipient'] ?? ''));
        if (!Validation::email($to)) {
            return ['ok' => false, 'error' => 'invalid_recipient', 'permanent' => true];
        }
        $subject = trim((string) ($rendered['subject'] ?? ''));
        $text = trim((string) ($rendered['text'] ?? ''));
        $html = trim((string) ($rendered['html'] ?? ''));
        if ($subject === '' || ($text === '' && $html === '')) {
            return ['ok' => false, 'error' => 'empty_content', 'permanent' => true];
        }
        $mailer = new SmtpMailer();
        $send = $mailer->send($to, $subject, $text !== '' ? $text : strip_tags($html), $html !== '' ? $html : null);
        if (empty($send['ok'])) {
            return ['ok' => false, 'error' => (string) ($send['error'] ?? 'smtp_failed'), 'retryable' => true];
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $rendered */
    private static function sendSms(array $job, array $rendered): array
    {
        if (!OtpSmsService::isOutboundAllowed()) {
            return ['ok' => false, 'error' => 'sms_disabled', 'retryable' => true];
        }
        if (SmsSettingsService::readinessErrorForSend() !== null && (AppSettings::get('sms.provider', 'simulate') ?? 'simulate') !== 'simulate') {
            return ['ok' => false, 'error' => 'sms_not_configured', 'retryable' => true];
        }
        $mobile = Validation::normalizeMobileIndia((string) ($job['recipient'] ?? ''));
        if ($mobile === null) {
            return ['ok' => false, 'error' => 'invalid_recipient', 'permanent' => true];
        }
        $body = trim((string) ($rendered['sms'] ?? ''));
        if ($body === '') {
            return ['ok' => false, 'error' => 'empty_content', 'permanent' => true];
        }
        $sender = trim(AppSettings::get('sms.sender_id', '') ?? '');
        $result = SmsGatewayFactory::adapter()->send($mobile, $body, $sender, null);
        if (empty($result['ok']) || trim((string) ($result['message_id'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'sms_rejected', 'retryable' => true];
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $rendered */
    private static function sendInApp(array $job, array $rendered): array
    {
        $ref = (string) ($job['recipient_ref'] ?? '');
        if (!preg_match('/^customer:(\d+)$/', $ref, $m)) {
            return ['ok' => false, 'error' => 'invalid_recipient_ref', 'permanent' => true];
        }
        $customerId = (int) $m[1];
        $title = trim((string) ($rendered['in_app_title'] ?? ''));
        $body = trim((string) ($rendered['in_app_body'] ?? ''));
        if ($title === '' || $body === '') {
            return ['ok' => false, 'error' => 'empty_content', 'permanent' => true];
        }
        CustomerNotificationService::notify($customerId, $title, $body, ['event' => (string) ($job['event_type'] ?? '')]);

        return ['ok' => true];
    }

    private static function markFailed(int $jobId, string $leaseToken, string $code, string $message, bool $permanent): void
    {
        if ($leaseToken === '') {
            return;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT attempts, max_attempts FROM notification_queue WHERE id = ? AND status = ? AND lease_token = ? LIMIT 1'
        );
        $stmt->execute([$jobId, self::STATUS_PROCESSING, $leaseToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $max = (int) ($row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);
        $sanitized = mb_substr($message, 0, 255);

        if (!$permanent && $attempts < $max) {
            $delaySec = min(3600, (int) pow(2, max(1, $attempts)) * 60);
            $next = date('Y-m-d H:i:s', time() + $delaySec);
            $upd = $pdo->prepare(
                "UPDATE notification_queue SET status = ?, attempts = ?, next_retry_at = ?, scheduled_at = ?,
                 error_code = ?, error_message = ?, lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
                 WHERE id = ? AND status = ? AND lease_token = ?"
            );
            $upd->execute([
                self::STATUS_PENDING,
                $attempts,
                $next,
                $next,
                $code,
                $sanitized,
                $jobId,
                self::STATUS_PROCESSING,
                $leaseToken,
            ]);

            return;
        }

        $pdo->prepare(
            "UPDATE notification_queue SET status = ?, attempts = ?, processed_at = NOW(),
             error_code = ?, error_message = ?, lease_token = NULL, lease_expires_at = NULL, processing_started_at = NULL
             WHERE id = ? AND status = ? AND lease_token = ?"
        )->execute([self::STATUS_FAILED, $attempts, $code, $sanitized, $jobId, self::STATUS_PROCESSING, $leaseToken]);
    }

    public static function publicErrorMessage(string $code): string
    {
        if ($code === 'missing_template') {
            return 'Template missing.';
        }
        if ($code === 'malformed_payload') {
            return 'Invalid payload.';
        }

        return 'Delivery failed.';
    }

    /** @return array<string, int> */
    public static function countsByStatus(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $out = [];
        $stmt = Database::pdo()->query(
            'SELECT status, channel, COUNT(*) AS c FROM notification_queue GROUP BY status, channel'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['status'] . ':' . (string) $row['channel'];
            $out[$key] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function adminList(?string $status, ?string $channel, ?string $event, int $page, int $perPage): array
    {
        if (!self::tableReady()) {
            return ['rows' => [], 'total' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($channel !== null && $channel !== '') {
            $where[] = 'channel = ?';
            $params[] = $channel;
        }
        if ($event !== null && $event !== '') {
            $where[] = 'event_type LIKE ?';
            $params[] = '%' . $event . '%';
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notification_queue {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $offset = max(0, ($page - 1) * $perPage);
        $listStmt = $pdo->prepare(
            "SELECT id, event_type, channel, recipient, recipient_ref, template_key, status, attempts, max_attempts,
                    error_code, error_message, created_at, updated_at, sent_at, processed_at
             FROM notification_queue {$sqlWhere} ORDER BY id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            unset($row['payload_json']);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public static function adminRetry(int $id): bool
    {
        if (!self::tableReady()) {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, next_retry_at = NOW(), scheduled_at = NOW(),
             error_code = NULL, error_message = NULL, lease_token = NULL, lease_expires_at = NULL
             WHERE id = ? AND status = ?"
        );
        $stmt->execute([self::STATUS_PENDING, $id, self::STATUS_FAILED]);

        return $stmt->rowCount() === 1;
    }

    public static function adminCancel(int $id): bool
    {
        if (!self::tableReady()) {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "UPDATE notification_queue SET status = ?, processed_at = NOW(), lease_token = NULL, lease_expires_at = NULL
             WHERE id = ? AND status = ?"
        );
        $stmt->execute([self::STATUS_CANCELLED, $id, self::STATUS_PENDING]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<string, mixed>|null Sanitized row for admin detail. */
    public static function adminRow(int $id): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, event_type, channel, recipient, recipient_ref, template_key, status, attempts, max_attempts,
                    error_code, error_message, created_at, sent_at, processed_at
             FROM notification_queue WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
