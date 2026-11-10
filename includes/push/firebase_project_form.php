<?php

declare(strict_types=1);

/**
 * Firebase project admin form: platform selection, conditional validation, field preservation.
 */
final class FirebaseProjectForm
{
    /** @var array<string, bool>|null */
    private static ?array $platformColumnsCache = null;

    public static function platformColumnsReady(): bool
    {
        return self::platformColumnState()['web'] && self::platformColumnState()['android'];
    }

    /**
     * @return array{web: bool, android: bool}
     */
    public static function platformsFromInput(array $input): array
    {
        $hasWebKey = array_key_exists('platform_web', $input);
        $hasAndKey = array_key_exists('platform_android', $input);
        if (!$hasWebKey && !$hasAndKey) {
            return ['web' => true, 'android' => true];
        }

        return [
            'web' => self::checkboxOn($input['platform_web'] ?? null),
            'android' => self::checkboxOn($input['platform_android'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{web: bool, android: bool}
     */
    public static function platformsFromRow(array $row): array
    {
        if (self::platformColumnsReady()) {
            return [
                'web' => !empty($row['platform_web']),
                'android' => !empty($row['platform_android']),
            ];
        }

        return self::inferPlatformsFromFieldValues($row);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{ok: bool, errors?: list<string>, platforms?: array{web: bool, android: bool}, fields?: array<string, mixed>, serviceAccountJson?: ?string}
     */
    public static function validateAndBuildFields(
        array $input,
        ?string $serviceAccountJson,
        ?array $existing = null
    ): array {
        $platforms = self::platformsFromInput($input);
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        $projectId = trim((string) ($input['firebase_project_id'] ?? ''));
        if ($name === '') {
            $errors[] = 'Display name is required.';
        }
        if ($projectId === '' || !preg_match('/^[a-z0-9-]{4,128}$/i', $projectId)) {
            $errors[] = 'Valid Firebase Project ID is required (letters, numbers, hyphens).';
        }
        if (!$platforms['web'] && !$platforms['android']) {
            $errors[] = 'Select at least one platform: Website Push and/or Android Native Push.';
        }

        $projectNumber = self::nullableTrim($input['firebase_project_number'] ?? null);

        $webFields = [
            'web_api_key' => self::nullableTrim($input['web_api_key'] ?? null),
            'web_app_id' => self::nullableTrim($input['web_app_id'] ?? null),
            'web_auth_domain' => self::nullableTrim($input['web_auth_domain'] ?? null),
            'web_storage_bucket' => self::nullableTrim($input['web_storage_bucket'] ?? null),
            'web_measurement_id' => self::nullableTrim($input['web_measurement_id'] ?? null),
            'vapid_public_key' => self::nullableTrim($input['vapid_public_key'] ?? null),
        ];
        $androidFields = [
            'android_app_id' => self::nullableTrim($input['android_app_id'] ?? null),
            'android_package_name' => self::nullableTrim($input['android_package_name'] ?? null),
        ];

        if ($platforms['web']) {
            foreach (
                [
                    'web_api_key' => 'Web API Key',
                    'web_app_id' => 'Web App ID',
                    'web_auth_domain' => 'Auth domain',
                    'web_storage_bucket' => 'Storage bucket',
                    'web_measurement_id' => 'Measurement ID',
                    'vapid_public_key' => 'VAPID public key',
                ] as $key => $label
            ) {
                if (($webFields[$key] ?? null) === null) {
                    $errors[] = $label . ' is required when Website Push is enabled.';
                }
            }
            if ($projectNumber === null) {
                $errors[] = 'Sender ID / Project number is required when Website Push is enabled.';
            }
            self::collectConsistencyErrors(
                $errors,
                $projectId,
                $projectNumber,
                $webFields['web_app_id'],
                null
            );
        } elseif ($existing !== null) {
            $webFields = self::preserveWebFields($existing);
        }

        if ($platforms['android']) {
            if ($androidFields['android_app_id'] === null) {
                $errors[] = 'Android Firebase App ID is required when Android Native Push is enabled.';
            }
            if ($androidFields['android_package_name'] === null) {
                $errors[] = 'Android package name is required when Android Native Push is enabled.';
            }
            if ($androidFields['android_package_name'] !== null
                && !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $androidFields['android_package_name'])) {
                $errors[] = 'Android package name format is invalid.';
            }
            self::collectConsistencyErrors(
                $errors,
                $projectId,
                $projectNumber,
                null,
                $androidFields['android_app_id']
            );
        } elseif ($existing !== null) {
            $androidFields = self::preserveAndroidFields($existing);
        }

        if ($serviceAccountJson !== null && trim($serviceAccountJson) !== '') {
            $parsed = FirebaseCredentialManager::parseServiceAccountUpload($serviceAccountJson);
            if (empty($parsed['ok'])) {
                return ['ok' => false, 'errors' => $parsed['errors'] ?? ['Invalid service account.']];
            }
            $saProject = trim((string) ($parsed['json']['project_id'] ?? ''));
            if ($saProject !== '' && strcasecmp($saProject, $projectId) !== 0) {
                $errors[] = 'Service account project_id must match the Firebase Project ID.';
            }
        } elseif ($existing === null) {
            $errors[] = 'Service account JSON is required for new projects.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'platforms' => $platforms];
        }

        $fields = array_merge([
            'name' => $name,
            'firebase_project_id' => $projectId,
            'firebase_project_number' => $projectNumber,
            'platform_notes' => self::nullableTrim($input['platform_notes'] ?? null),
            'platform_web' => $platforms['web'] ? 1 : 0,
            'platform_android' => $platforms['android'] ? 1 : 0,
        ], $webFields, $androidFields);

        return [
            'ok' => true,
            'platforms' => $platforms,
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, message?: string}
     */
    public static function validateRowForActivation(array $row): array
    {
        $platforms = self::platformsFromRow($row);
        if (!$platforms['web'] && !$platforms['android']) {
            return ['ok' => false, 'message' => 'Enable at least one push platform before activation.'];
        }
        if ($platforms['web']) {
            foreach (['web_api_key', 'web_app_id', 'vapid_public_key'] as $key) {
                if (trim((string) ($row[$key] ?? '')) === '') {
                    return ['ok' => false, 'message' => 'Website Push is enabled but web configuration is incomplete.'];
                }
            }
            if (trim((string) ($row['firebase_project_number'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Sender ID / Project number is required for Website Push.'];
            }
        }
        if ($platforms['android']) {
            if (trim((string) ($row['android_app_id'] ?? '')) === ''
                || trim((string) ($row['android_package_name'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Android Native Push is enabled but Android configuration is incomplete.'];
            }
        }

        return ['ok' => true];
    }

    public static function extractFirebaseAppNumber(?string $appId): ?string
    {
        $appId = trim((string) $appId);
        if ($appId === '') {
            return null;
        }
        if (preg_match('/^1:(\d+):(web|android):/i', $appId, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param list<string> $errors
     */
    private static function collectConsistencyErrors(
        array &$errors,
        string $firebaseProjectId,
        ?string $projectNumber,
        ?string $webAppId,
        ?string $androidAppId
    ): void {
        if ($webAppId !== null && $webAppId !== '') {
            if (!preg_match('/^1:\d+:web:/i', $webAppId)) {
                $errors[] = 'Web App ID should look like 1:123456789012:web:…';
            }
            $fromWeb = self::extractFirebaseAppNumber($webAppId);
            if ($projectNumber !== null && $fromWeb !== null && $fromWeb !== $projectNumber) {
                $errors[] = 'Web App ID project number does not match Sender ID / Project number.';
            }
        }
        if ($androidAppId !== null && $androidAppId !== '') {
            if (!preg_match('/^1:\d+:android:/i', $androidAppId)) {
                $errors[] = 'Android Firebase App ID should look like 1:123456789012:android:…';
            }
            $fromAnd = self::extractFirebaseAppNumber($androidAppId);
            if ($projectNumber !== null && $fromAnd !== null && $fromAnd !== $projectNumber) {
                $errors[] = 'Android App ID project number does not match Sender ID / Project number.';
            }
            if ($webAppId !== null && $webAppId !== '') {
                $w = self::extractFirebaseAppNumber($webAppId);
                if ($w !== null && $fromAnd !== null && $w !== $fromAnd) {
                    $errors[] = 'Web and Android app IDs must use the same Firebase project number.';
                }
            }
        }
        if ($webAppId !== null && $webAppId !== '' && $firebaseProjectId !== '') {
            // project_id slug is not embedded in app id; no strict match to slug
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{web: bool, android: bool}
     */
    private static function inferPlatformsFromFieldValues(array $row): array
    {
        $web = trim((string) ($row['web_api_key'] ?? '')) !== ''
            || trim((string) ($row['web_app_id'] ?? '')) !== ''
            || trim((string) ($row['vapid_public_key'] ?? '')) !== '';
        $android = trim((string) ($row['android_app_id'] ?? '')) !== ''
            || trim((string) ($row['android_package_name'] ?? '')) !== '';
        if (!$web && !$android) {
            return ['web' => true, 'android' => true];
        }

        return ['web' => $web, 'android' => $android];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preserveWebFields(array $existing): array
    {
        return [
            'web_api_key' => $existing['web_api_key'] ?? null,
            'web_app_id' => $existing['web_app_id'] ?? null,
            'web_auth_domain' => $existing['web_auth_domain'] ?? null,
            'web_storage_bucket' => $existing['web_storage_bucket'] ?? null,
            'web_measurement_id' => $existing['web_measurement_id'] ?? null,
            'vapid_public_key' => $existing['vapid_public_key'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preserveAndroidFields(array $existing): array
    {
        return [
            'android_app_id' => $existing['android_app_id'] ?? null,
            'android_package_name' => $existing['android_package_name'] ?? null,
        ];
    }

    /**
     * @return array{web: bool, android: bool}
     */
    private static function platformColumnState(): array
    {
        if (self::$platformColumnsCache !== null) {
            return self::$platformColumnsCache;
        }
        self::$platformColumnsCache = ['web' => false, 'android' => false];
        if (!PushSchema::tableReady('firebase_projects')) {
            return self::$platformColumnsCache;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            foreach (['platform_web', 'platform_android'] as $col) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $stmt->execute(['firebase_projects', $col]);
                self::$platformColumnsCache[$col === 'platform_web' ? 'web' : 'android'] = ((int) $stmt->fetchColumn()) > 0;
            }
        } catch (Throwable $e) {
        }

        return self::$platformColumnsCache;
    }

    private static function checkboxOn(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'on', 'yes', 'true'], true);
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
