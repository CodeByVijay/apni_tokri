<?php

declare(strict_types=1);

final class FirebaseAdminService
{
    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, errors?: list<string>, message?: string}
     */
    public static function saveProject(array $input, ?string $serviceAccountJson, ?int $id = null): array
    {
        if (!PushSchema::tableReady('firebase_projects')) {
            return ['ok' => false, 'errors' => ['Database migration 1.39.0 is not applied.']];
        }
        $name = trim((string) ($input['name'] ?? ''));
        $projectId = trim((string) ($input['firebase_project_id'] ?? ''));
        $errors = [];
        if ($name === '') {
            $errors[] = 'Project name is required.';
        }
        if ($projectId === '' || !preg_match('/^[a-z0-9-]{4,128}$/i', $projectId)) {
            $errors[] = 'Valid Firebase Project ID is required.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $encrypted = null;
        $clientEmail = null;
        $fingerprint = null;
        $previousEncrypted = null;
        if ($id !== null && $id > 0) {
            $existing = FirebaseProjectManager::findById($id, true);
            $previousEncrypted = $existing['service_account_encrypted'] ?? null;
            $clientEmail = $existing['service_account_client_email'] ?? null;
            $fingerprint = $existing['credential_fingerprint'] ?? null;
        }

        if ($serviceAccountJson !== null && trim($serviceAccountJson) !== '') {
            $parsed = FirebaseCredentialManager::parseServiceAccountUpload($serviceAccountJson);
            if (empty($parsed['ok'])) {
                return ['ok' => false, 'errors' => $parsed['errors'] ?? ['Invalid service account.']];
            }
            $test = FirebaseCredentialManager::testCredentials($parsed['json'], $id ?? 0);
            if (empty($test['ok']) && AppEnvironment::isProduction()) {
                return [
                    'ok' => false,
                    'errors' => ['Credential validation failed: ' . (string) ($test['message'] ?? '')],
                ];
            }
            $encrypted = FirebaseCredentialManager::encryptForStorage($parsed['json']);
            $clientEmail = $parsed['client_email'] ?? null;
            $fingerprint = $parsed['fingerprint'] ?? null;
        } elseif ($id === null) {
            return ['ok' => false, 'errors' => ['Service account JSON is required for new projects.']];
        }

        $fields = [
            'name' => $name,
            'firebase_project_id' => $projectId,
            'firebase_project_number' => self::nullableTrim($input['firebase_project_number'] ?? null),
            'web_api_key' => self::nullableTrim($input['web_api_key'] ?? null),
            'web_app_id' => self::nullableTrim($input['web_app_id'] ?? null),
            'web_auth_domain' => self::nullableTrim($input['web_auth_domain'] ?? null),
            'web_storage_bucket' => self::nullableTrim($input['web_storage_bucket'] ?? null),
            'web_measurement_id' => self::nullableTrim($input['web_measurement_id'] ?? null),
            'vapid_public_key' => self::nullableTrim($input['vapid_public_key'] ?? null),
            'android_app_id' => self::nullableTrim($input['android_app_id'] ?? null),
            'android_package_name' => self::nullableTrim($input['android_package_name'] ?? null),
            'platform_notes' => self::nullableTrim($input['platform_notes'] ?? null),
        ];

        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            if ($id !== null && $id > 0) {
                $sql = 'UPDATE firebase_projects SET
                    name = ?, firebase_project_id = ?, firebase_project_number = ?,
                    web_api_key = ?, web_app_id = ?, web_auth_domain = ?, web_storage_bucket = ?,
                    web_measurement_id = ?, vapid_public_key = ?, android_app_id = ?, android_package_name = ?,
                    platform_notes = ?, updated_at = NOW()';
                $params = [
                    $fields['name'], $fields['firebase_project_id'], $fields['firebase_project_number'],
                    $fields['web_api_key'], $fields['web_app_id'], $fields['web_auth_domain'], $fields['web_storage_bucket'],
                    $fields['web_measurement_id'], $fields['vapid_public_key'], $fields['android_app_id'],
                    $fields['android_package_name'], $fields['platform_notes'],
                ];
                if ($encrypted !== null) {
                    $sql .= ', service_account_encrypted = ?, service_account_client_email = ?, credential_fingerprint = ?';
                    $params[] = $encrypted;
                    $params[] = $clientEmail;
                    $params[] = $fingerprint;
                }
                $sql .= ' WHERE id = ?';
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                FirebaseProjectManager::resetCache();
                AuditLog::write('firebase.project.update', 'firebase_projects', (string) $id, ['name' => $name]);

                return ['ok' => true, 'id' => $id, 'message' => 'Firebase project updated.'];
            }

            $pdo->prepare(
                'INSERT INTO firebase_projects
                 (name, firebase_project_id, firebase_project_number, status,
                  web_api_key, web_app_id, web_auth_domain, web_storage_bucket, web_measurement_id,
                  vapid_public_key, android_app_id, android_package_name,
                  service_account_encrypted, service_account_client_email, credential_fingerprint, platform_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $fields['name'],
                $fields['firebase_project_id'],
                $fields['firebase_project_number'],
                'draft',
                $fields['web_api_key'],
                $fields['web_app_id'],
                $fields['web_auth_domain'],
                $fields['web_storage_bucket'],
                $fields['web_measurement_id'],
                $fields['vapid_public_key'],
                $fields['android_app_id'],
                $fields['android_package_name'],
                $encrypted,
                $clientEmail,
                $fingerprint,
                $fields['platform_notes'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            AuditLog::write('firebase.project.create', 'firebase_projects', (string) $newId, ['name' => $name]);

            return ['ok' => true, 'id' => $newId, 'message' => 'Firebase project created.'];
        } catch (Throwable $e) {
            if ($encrypted !== null && $previousEncrypted !== null && $id !== null) {
                try {
                    $pdo->prepare(
                        'UPDATE firebase_projects SET service_account_encrypted = ? WHERE id = ?'
                    )->execute([$previousEncrypted, $id]);
                } catch (Throwable $ignored) {
                }
            }
            error_log('FirebaseAdminService::saveProject');

            return ['ok' => false, 'errors' => ['Could not save Firebase project.']];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function activate(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid project.'];
        }
        $row = FirebaseProjectManager::findById($id, true);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Project not found.'];
        }
        $sa = FirebaseCredentialManager::decryptStored($row['service_account_encrypted'] ?? null);
        if ($sa === null) {
            return ['ok' => false, 'message' => 'Service account required before activation.'];
        }
        $test = FirebaseCredentialManager::testCredentials($sa, $id);
        if (empty($test['ok'])) {
            return ['ok' => false, 'message' => 'Credential test failed: ' . (string) ($test['message'] ?? '')];
        }

        $previousActive = FirebaseProjectManager::activeProject(false);
        $previousActiveId = $previousActive !== null ? (int) $previousActive['id'] : 0;

        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE firebase_projects SET is_active_global = 0 WHERE is_active_global = 1');
            $pdo->prepare(
                'UPDATE firebase_projects
                 SET is_active_global = 1, status = ?, last_test_status = ?, last_test_at = NOW(),
                     last_test_message = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute(['active', 'passed', (string) ($test['message'] ?? 'OK'), $id]);
            $pdo->commit();
            if ($previousActiveId > 0 && $previousActiveId !== $id) {
                AppSettings::set(PushSettings::KEY_ROLLBACK_PROJECT_ID, (string) $previousActiveId);
            }
            FirebaseOAuth::invalidateCache($id);
            FirebaseProjectManager::resetCache();
            AppSettings::resetCache();
            AuditLog::write('firebase.project.activate', 'firebase_projects', (string) $id, [
                'previous_active_id' => $previousActiveId > 0 ? $previousActiveId : null,
            ]);

            return ['ok' => true, 'message' => 'Active Firebase project switched. Devices may need to re-register.'];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Could not activate project.'];
        }
    }

    /**
     * Re-activate the previously active project (stored on last successful switch).
     *
     * @return array{ok: bool, message: string}
     */
    public static function rollbackToPrevious(): array
    {
        $prevId = (int) (AppSettings::get(PushSettings::KEY_ROLLBACK_PROJECT_ID, '0') ?? '0');
        if ($prevId <= 0) {
            return ['ok' => false, 'message' => 'No previous active project recorded.'];
        }
        $row = FirebaseProjectManager::findById($prevId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Previous project no longer exists.'];
        }

        return self::activate($prevId);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function deactivate(int $id): array
    {
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE firebase_projects SET is_active_global = 0, status = ?, updated_at = NOW() WHERE id = ?'
            )->execute(['inactive', $id]);
            FirebaseProjectManager::resetCache();
            AuditLog::write('firebase.project.deactivate', 'firebase_projects', (string) $id);

            return ['ok' => true, 'message' => 'Project deactivated.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not deactivate.'];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid project.'];
        }
        $tokens = FirebaseProjectManager::countDeviceTokens($id);
        if ($tokens > 0) {
            return [
                'ok' => false,
                'message' => 'Cannot delete: ' . $tokens . ' active device registration(s) still reference this project.',
            ];
        }
        $row = FirebaseProjectManager::findById($id);
        if ($row !== null && !empty($row['is_active_global'])) {
            return ['ok' => false, 'message' => 'Deactivate this project before deleting.'];
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare('DELETE FROM firebase_projects WHERE id = ?')->execute([$id]);
            FirebaseOAuth::invalidateCache($id);
            AuditLog::write('firebase.project.delete', 'firebase_projects', (string) $id);

            return ['ok' => true, 'message' => 'Project deleted.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not delete (foreign key constraints may apply).'];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function testExisting(int $id): array
    {
        $row = FirebaseProjectManager::findById($id, true);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Project not found.'];
        }
        $sa = FirebaseCredentialManager::decryptStored($row['service_account_encrypted'] ?? null);
        if ($sa === null) {
            return ['ok' => false, 'message' => 'No service account stored.'];
        }
        FirebaseOAuth::invalidateCache($id);
        $test = FirebaseCredentialManager::testCredentials($sa, $id);
        $status = !empty($test['ok']) ? 'passed' : 'failed';
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'UPDATE firebase_projects SET last_test_status = ?, last_test_at = NOW(), last_test_message = ? WHERE id = ?'
            )->execute([$status, (string) ($test['message'] ?? ''), $id]);
        } catch (Throwable $e) {
        }

        return ['ok' => !empty($test['ok']), 'message' => (string) ($test['message'] ?? '')];
    }

    private static function nullableTrim(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }
}
