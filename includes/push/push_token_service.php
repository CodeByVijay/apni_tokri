<?php

declare(strict_types=1);

final class PushTokenService
{
    public static function deviceKeyFromRequest(): string
    {
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $salt = (string) (App::config()['app']['key'] ?? 'at-push');

        return hash('sha256', $salt . '|' . $ip . '|' . $ua);
    }

    /**
     * @return array{ok: bool, registration_id?: int, message?: string}
     */
    public static function register(
        string $platform,
        string $fcmToken,
        string $permissionStatus,
        ?int $customerId = null,
        ?string $appVersion = null,
        string $targetType = 'fcm_token'
    ): array {
        if (!PushSchema::ready()) {
            return ['ok' => false, 'message' => 'Push not available.'];
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['web', 'android'], true)) {
            return ['ok' => false, 'message' => 'Invalid platform.'];
        }
        $fcmToken = trim($fcmToken);
        $targetType = $targetType === 'fid' ? 'fid' : 'fcm_token';
        if ($platform === 'web') {
            $targetType = 'fid';
        }
        if ($targetType === 'fid') {
            if (strlen($fcmToken) < 10 || strlen($fcmToken) > 128) {
                return ['ok' => false, 'message' => 'Invalid Firebase Installation ID.'];
            }
        } elseif (strlen($fcmToken) < 20 || strlen($fcmToken) > 512) {
            return ['ok' => false, 'message' => 'Invalid FCM registration token.'];
        }
        $active = FirebaseProjectManager::activeProject(false);
        if ($active === null) {
            return ['ok' => false, 'message' => 'No active Firebase project.'];
        }
        $projectId = (int) $active['id'];
        $deviceKey = self::deviceKeyFromRequest();
        $perm = in_array($permissionStatus, ['granted', 'denied', 'default'], true) ? $permissionStatus : 'unknown';
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id FROM device_push_registrations
                 WHERE firebase_project_id = ? AND platform = ? AND fcm_token = ? LIMIT 1'
            );
            $stmt->execute([$projectId, $platform, $fcmToken]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $pdo->prepare(
                    'UPDATE device_push_registrations
                     SET customer_id = ?, device_key = ?, user_agent = ?, app_version = ?,
                         permission_status = ?, status = ?, target_type = ?, last_refresh_at = NOW(), updated_at = NOW()
                     WHERE id = ?'
                )->execute([$customerId, $deviceKey, $ua, $appVersion, $perm, 'active', $targetType, (int) $existing['id']]);

                return ['ok' => true, 'registration_id' => (int) $existing['id']];
            }
            $pdo->prepare(
                'INSERT INTO device_push_registrations
                 (customer_id, firebase_project_id, platform, target_type, fcm_token, device_key,
                  user_agent, app_version, permission_status, status, last_refresh_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $customerId,
                $projectId,
                $platform,
                $targetType,
                $fcmToken,
                $deviceKey,
                $ua,
                $appVersion,
                $perm,
                'active',
            ]);

            return ['ok' => true, 'registration_id' => (int) $pdo->lastInsertId()];
        } catch (Throwable $e) {
            error_log('PushTokenService::register');

            return ['ok' => false, 'message' => 'Could not save registration.'];
        }
    }

    /**
     * @return array{ok: bool}
     */
    public static function unregisterCurrentDevice(string $platform, string $fcmToken, string $targetType = 'fcm_token'): array
    {
        if (!PushSchema::tableReady('device_push_registrations')) {
            return ['ok' => false];
        }
        $platform = strtolower(trim($platform));
        $fcmToken = trim($fcmToken);
        $active = FirebaseProjectManager::activeProject(false);
        if ($active === null || $fcmToken === '') {
            return ['ok' => false];
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE device_push_registrations SET status = ?, updated_at = NOW()
                 WHERE firebase_project_id = ? AND platform = ? AND fcm_token = ? AND device_key = ?'
            )->execute([
                'revoked',
                (int) $active['id'],
                $platform,
                $fcmToken,
                self::deviceKeyFromRequest(),
            ]);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false];
        }
    }

    public static function revokeForCustomerDevice(int $customerId, string $platform, ?string $fcmToken = null): void
    {
        if ($customerId <= 0 || !PushSchema::tableReady('device_push_registrations')) {
            return;
        }
        try {
            Install::ensureConnection();
            if ($fcmToken !== null && $fcmToken !== '') {
                Database::pdo()->prepare(
                    'UPDATE device_push_registrations SET status = ?, updated_at = NOW()
                     WHERE customer_id = ? AND platform = ? AND fcm_token = ?'
                )->execute(['revoked', $customerId, $platform, $fcmToken]);
            } else {
                Database::pdo()->prepare(
                    'UPDATE device_push_registrations SET status = ?, updated_at = NOW()
                     WHERE customer_id = ? AND platform = ? AND device_key = ?'
                )->execute(['revoked', $customerId, $platform, self::deviceKeyFromRequest()]);
            }
        } catch (Throwable $e) {
            error_log('PushTokenService::revokeForCustomerDevice');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activeTokensForCustomer(int $customerId, ?string $platform = null): array
    {
        if ($customerId <= 0 || !PushSchema::tableReady('device_push_registrations')) {
            return [];
        }
        $active = FirebaseProjectManager::activeProject(false);
        if ($active === null) {
            return [];
        }
        $sql = 'SELECT id, platform, fcm_token, target_type FROM device_push_registrations
                WHERE customer_id = ? AND firebase_project_id = ? AND status = ?';
        $params = [$customerId, (int) $active['id'], 'active'];
        if ($platform !== null) {
            $sql .= ' AND platform = ?';
            $params[] = $platform;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function adminRevokeById(int $registrationId): array
    {
        if ($registrationId <= 0 || !PushSchema::tableReady('device_push_registrations')) {
            return ['ok' => false, 'message' => 'Invalid registration.'];
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE device_push_registrations SET status = ?, updated_at = NOW() WHERE id = ?'
            )->execute(['revoked', $registrationId]);
            AuditLog::write('push.device.revoke', 'device_push_registrations', (string) $registrationId);

            return ['ok' => true, 'message' => 'Registration revoked.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not revoke registration.'];
        }
    }

    public static function markInvalid(int $registrationId): void
    {
        if ($registrationId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE device_push_registrations SET status = ?, updated_at = NOW() WHERE id = ?'
            )->execute(['invalid', $registrationId]);
        } catch (Throwable $e) {
            error_log('PushTokenService::markInvalid');
        }
    }
}
