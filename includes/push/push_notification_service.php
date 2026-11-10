<?php

declare(strict_types=1);

final class PushNotificationService
{
    public static function sendsAllowed(): bool
    {
        if (!PushSchema::ready()) {
            return false;
        }
        if (AppEnvironment::isProduction()) {
            return trim((string) AppSettings::get('push.enabled', '0')) === '1';
        }

        return trim((string) AppSettings::get('push.enabled', '1')) !== '0';
    }

    public static function dryRunOnly(): bool
    {
        if (!AppEnvironment::isProduction()) {
            return trim((string) AppSettings::get('push.dry_run', '1')) !== '0';
        }

        return trim((string) AppSettings::get('push.dry_run', '0')) === '1';
    }

    /**
     * @param array<string, string|int|float|null> $vars
     * @param array<string, string> $dataPayload
     * @return array{ok: bool, sent?: int, skipped?: bool, duplicate?: bool, dry_run?: bool}
     */
    public static function sendToCustomer(
        int $customerId,
        string $templateKey,
        array $vars,
        string $idempotencyKey,
        string $notificationType,
        array $dataPayload = []
    ): array {
        if ($customerId <= 0 || $idempotencyKey === '') {
            return ['ok' => false];
        }
        if (str_starts_with($notificationType, 'admin.manual') && !PushSettings::recordManualSendAttempt()) {
            return [
                'ok' => false,
                'reason' => 'rate_limit',
                'message' => 'Daily manual send limit reached (' . PushSettings::MANUAL_SEND_DAILY_MAX . ').',
            ];
        }
        if (!self::sendsAllowed()) {
            return ['ok' => true, 'skipped' => true];
        }
        if (self::idempotencyExists($idempotencyKey)) {
            return ['ok' => true, 'duplicate' => true];
        }
        $tpl = PushTemplateRepository::findActive($templateKey);
        if ($tpl === null) {
            self::logRow(
                $idempotencyKey,
                $notificationType,
                $customerId,
                null,
                null,
                'skipped',
                null,
                'template_missing',
                null,
                null
            );

            return ['ok' => true, 'skipped' => true];
        }
        $title = PushTemplateRepository::renderField((string) $tpl['title_template'], $vars);
        $body = PushTemplateRepository::renderField((string) $tpl['body_template'], $vars);
        $clickRaw = (string) ($tpl['click_url_template'] ?? '');
        $clickUrl = PushUrlValidator::resolveClickUrl($clickRaw, $vars);

        $devices = PushTokenService::activeTokensForCustomer($customerId);
        if ($devices === []) {
            self::logRow(
                $idempotencyKey,
                $notificationType,
                $customerId,
                null,
                null,
                'skipped',
                null,
                'no_devices',
                null,
                $title
            );

            return ['ok' => true, 'skipped' => true];
        }

        if (self::dryRunOnly()) {
            self::logRow(
                $idempotencyKey,
                $notificationType,
                $customerId,
                null,
                'mixed',
                'dry_run',
                null,
                null,
                null,
                $title
            );

            return ['ok' => true, 'dry_run' => true, 'sent' => 0];
        }

        $project = FirebaseProjectManager::activeProject(true);
        if ($project === null) {
            return ['ok' => false];
        }
        $sa = FirebaseCredentialManager::decryptStored($project['service_account_encrypted'] ?? null);
        if ($sa === null) {
            self::logRow(
                $idempotencyKey,
                $notificationType,
                $customerId,
                (int) $project['id'],
                'mixed',
                'failed',
                null,
                'missing_credentials',
                null,
                $title
            );

            return ['ok' => false];
        }
        $oauth = FirebaseOAuth::obtainAccessToken($sa, (int) $project['id']);
        if (empty($oauth['ok']) || empty($oauth['access_token'])) {
            self::logRow(
                $idempotencyKey,
                $notificationType,
                $customerId,
                (int) $project['id'],
                'mixed',
                'failed',
                null,
                'oauth_failed',
                null,
                $title
            );

            return ['ok' => false];
        }
        $accessToken = (string) $oauth['access_token'];
        $firebaseProjectId = (string) $project['firebase_project_id'];
        $eventId = self::uuidV4();
        $sent = 0;
        foreach ($devices as $dev) {
            $targetType = (string) ($dev['target_type'] ?? 'fcm_token');
            if ((string) ($dev['platform'] ?? '') === 'web' && $targetType !== 'fid') {
                $targetType = 'fid';
            }
            $message = self::buildMessage(
                (string) $dev['fcm_token'],
                (string) $dev['platform'],
                $targetType,
                $title,
                $body,
                $clickUrl,
                array_merge($dataPayload, [
                    'notification_type' => $notificationType,
                    'event_id' => $eventId,
                ])
            );
            $result = FirebaseFcmClient::send($firebaseProjectId, $accessToken, $message);
            if (!empty($result['ok'])) {
                ++$sent;
            }
            if (!empty($result['token_invalid'])) {
                PushTokenService::markInvalid((int) $dev['id']);
            }
        }
        self::logRow(
            $idempotencyKey,
            $notificationType,
            $customerId,
            (int) $project['id'],
            'mixed',
            $sent > 0 ? 'sent' : 'failed',
            $sent > 0 ? 200 : 0,
            $sent > 0 ? null : 'fcm_send_failed',
            $eventId,
            $title
        );

        return ['ok' => $sent > 0, 'sent' => $sent];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function sendAdminTest(string $platform, string $fcmToken, string $title, string $body): array
    {
        if (!Permission::can('firebase.manage')) {
            return ['ok' => false, 'message' => 'Forbidden'];
        }
        if (!PushSettings::recordManualSendAttempt()) {
            return [
                'ok' => false,
                'message' => 'Daily manual send limit reached (' . PushSettings::MANUAL_SEND_DAILY_MAX . ').',
            ];
        }
        $fcmToken = trim($fcmToken);
        if ($fcmToken === '') {
            return ['ok' => false, 'message' => 'Token required.'];
        }
        if (self::dryRunOnly()) {
            return ['ok' => true, 'message' => 'Dry-run mode: test message recorded locally, not sent to FCM.'];
        }
        $project = FirebaseProjectManager::activeProject(true);
        if ($project === null) {
            return ['ok' => false, 'message' => 'No active Firebase project.'];
        }
        $sa = FirebaseCredentialManager::decryptStored($project['service_account_encrypted'] ?? null);
        if ($sa === null) {
            return ['ok' => false, 'message' => 'Service account not configured.'];
        }
        $oauth = FirebaseOAuth::obtainAccessToken($sa, (int) $project['id']);
        if (empty($oauth['access_token'])) {
            return ['ok' => false, 'message' => 'Could not obtain access token.'];
        }
        $targetType = strtolower($platform) === 'web' ? 'fid' : 'fcm_token';
        $message = self::buildMessage(
            $fcmToken,
            $platform,
            $targetType,
            $title !== '' ? $title : 'Test',
            $body !== '' ? $body : 'Test notification from admin.',
            SeoUrl::homepageCanonicalUrl(),
            ['notification_type' => 'admin.test']
        );
        $result = FirebaseFcmClient::send(
            (string) $project['firebase_project_id'],
            (string) $oauth['access_token'],
            $message
        );
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => 'FCM rejected the message (' . (string) ($result['error_code'] ?? '') . ').'];
        }

        return ['ok' => true, 'message' => 'Test message accepted by FCM.'];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private static function buildMessage(
        string $target,
        string $platform,
        string $targetType,
        string $title,
        string $body,
        ?string $clickUrl,
        array $data
    ): array {
        $data = array_map(static fn ($v) => (string) $v, $data);
        if ($clickUrl !== null) {
            $data['click_url'] = $clickUrl;
        }
        $msg = [
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ];
        if ($targetType === 'fid' || $platform === 'web') {
            $msg['fid'] = $target;
        } else {
            $msg['token'] = $target;
        }
        if ($platform === 'android') {
            $msg['android'] = [
                'priority' => 'HIGH',
                'notification' => [
                    'click_action' => 'OPEN_URL',
                ],
            ];
        }
        if ($platform === 'web' && $clickUrl !== null) {
            $msg['webpush'] = [
                'fcm_options' => ['link' => $clickUrl],
            ];
        }

        return $msg;
    }

    private static function idempotencyExists(string $key): bool
    {
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT id FROM push_notification_logs WHERE idempotency_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function logRow(
        string $idempotencyKey,
        string $notificationType,
        ?int $customerId,
        ?int $projectId,
        ?string $platform,
        string $status,
        ?int $httpStatus,
        ?string $failureCategory,
        ?string $eventId,
        ?string $title
    ): void {
        if (!PushSchema::tableReady('push_notification_logs')) {
            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'INSERT INTO push_notification_logs
                 (event_id, firebase_project_id, platform, customer_id, notification_type,
                  status, http_status, failure_category, idempotency_key, title_redacted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $eventId ?? self::uuidV4(),
                $projectId,
                $platform ?? 'mixed',
                $customerId,
                $notificationType,
                $status,
                $httpStatus,
                $failureCategory,
                $idempotencyKey,
                $title !== null ? mb_substr($title, 0, 191) : null,
            ]);
        } catch (Throwable $e) {
            error_log('PushNotificationService::logRow');
        }
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
