<?php

declare(strict_types=1);

final class FirebaseProjectManager
{
    /** @var array<string, mixed>|null */
    private static $activeCache;

    public static function resetCache(): void
    {
        self::$activeCache = null;
    }

    /**
     * @return array<string, mixed>|null Active project row (no encrypted blob in public views)
     */
    public static function activeProject(bool $includeEncrypted = false): ?array
    {
        if (!PushSchema::tableReady('firebase_projects')) {
            return null;
        }
        if (!$includeEncrypted && self::$activeCache !== null) {
            return self::$activeCache;
        }
        try {
            Install::ensureConnection();
            $platformCols = FirebaseProjectForm::platformColumnsReady() ? ', platform_web, platform_android' : '';
            $cols = $includeEncrypted
                ? '*'
                : 'id, name, firebase_project_id, firebase_project_number, status, is_active_global,
                   web_api_key, web_app_id, web_auth_domain, web_storage_bucket, web_measurement_id,
                   vapid_public_key, android_app_id, android_package_name,
                   service_account_client_email, credential_fingerprint,
                   last_test_status, last_test_at, last_test_message, platform_notes, created_at, updated_at'
                    . $platformCols;
            $stmt = Database::pdo()->query(
                "SELECT {$cols} FROM firebase_projects
                 WHERE is_active_global = 1 AND status = 'active' ORDER BY id DESC LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if (!$includeEncrypted) {
                self::$activeCache = $row;
            }

            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAll(): array
    {
        if (!PushSchema::tableReady('firebase_projects')) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT id, name, firebase_project_id, status, is_active_global,
                    web_app_id, android_app_id, last_test_status, last_test_at, updated_at
             FROM firebase_projects ORDER BY is_active_global DESC, name ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id, bool $includeEncrypted = false): ?array
    {
        if ($id <= 0 || !PushSchema::tableReady('firebase_projects')) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM firebase_projects WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!$includeEncrypted) {
            unset($row['service_account_encrypted']);
        }

        return $row;
    }

    public static function publicWebConfig(): ?array
    {
        $active = self::activeProject(false);
        if ($active === null) {
            return null;
        }
        if (!FirebaseProjectForm::platformsFromRow($active)['web']) {
            return null;
        }
        if (trim((string) ($active['web_app_id'] ?? '')) === ''
            || trim((string) ($active['web_api_key'] ?? '')) === ''
            || trim((string) ($active['vapid_public_key'] ?? '')) === '') {
            return null;
        }

        return [
            'projectRowId' => (int) $active['id'],
            'apiKey' => (string) $active['web_api_key'],
            'authDomain' => (string) ($active['web_auth_domain'] ?? ''),
            'projectId' => (string) $active['firebase_project_id'],
            'storageBucket' => (string) ($active['web_storage_bucket'] ?? ''),
            'messagingSenderId' => (string) ($active['firebase_project_number'] ?? ''),
            'appId' => (string) $active['web_app_id'],
            'measurementId' => (string) ($active['web_measurement_id'] ?? ''),
            'vapidKey' => (string) $active['vapid_public_key'],
            'configVersion' => hash('sha256', implode('|', [
                (string) $active['id'],
                (string) $active['updated_at'],
                (string) $active['web_app_id'],
                (string) $active['vapid_public_key'],
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $serviceAccount
     */
    public static function countDeviceTokens(int $projectId): int
    {
        if (!PushSchema::tableReady('device_push_registrations')) {
            return 0;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM device_push_registrations WHERE firebase_project_id = ? AND status = ?'
        );
        $stmt->execute([$projectId, 'active']);

        return (int) $stmt->fetchColumn();
    }
}
