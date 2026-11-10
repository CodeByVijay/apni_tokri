<?php

declare(strict_types=1);

/**
 * Admin setup progress — verified states only; external steps marked manual.
 */
final class FirebaseSetupProgress
{
    /**
     * @param array<string, mixed>|null $row Saved firebase_projects row (no encrypted blob required).
     * @param array{web: bool, android: bool} $platforms
     * @return array{
     *   shared: list<array{id: string, label: string, status: string, detail: string}>,
     *   website: list<array{id: string, label: string, status: string, detail: string}>,
     *   android: list<array{id: string, label: string, status: string, detail: string}>,
     *   admin_workflow: list<array{id: string, label: string, status: string, detail: string}>
     * }
     */
    public static function build(?array $row, array $platforms): array
    {
        $projectId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $platforms = [
            'web' => !empty($platforms['web']),
            'android' => !empty($platforms['android']),
        ];

        $shared = [
            self::item(
                'project_details',
                'Project details entered',
                self::projectDetailsComplete($row) ? 'done' : 'pending',
                $projectId > 0 ? 'Display name and Firebase Project ID saved.' : 'Save the project after entering general information.'
            ),
            self::item(
                'service_account',
                'Service account uploaded',
                self::serviceAccountStored($row) ? 'done' : 'pending',
                self::serviceAccountStored($row)
                    ? 'Encrypted credential stored server-side.'
                    : 'Upload Service Account JSON when saving a new project.'
            ),
            self::item(
                'credentials_tested',
                'Credentials tested successfully',
                self::credentialsPassed($row) ? 'done' : 'pending',
                self::credentialsDetail($row)
            ),
            self::item(
                'project_activated',
                'Project activated globally',
                self::projectActivated($row) ? 'done' : 'pending',
                self::projectActivated($row)
                    ? 'This project is the active global Firebase project.'
                    : 'Activate from Firebase → Projects after credentials pass.'
            ),
        ];

        $website = [];
        if ($platforms['web']) {
            $webReady = is_array($row) && self::webConfigComplete($row);
            $fidCount = $projectId > 0 ? self::activeDeviceCount($projectId, 'web', 'fid') : 0;
            $storefrontReady = FirebaseProjectManager::publicWebConfig() !== null
                && is_array($row)
                && !empty($row['is_active_global'])
                && $webReady;

            $website = [
                self::item(
                    'web_app_registered',
                    'Web app registered in Firebase Console',
                    'manual',
                    'Manual verification required — confirm a Web app exists under Project Settings → Your apps.'
                ),
                self::item(
                    'web_config',
                    'Web configuration saved in Admin',
                    $webReady ? 'done' : 'pending',
                    $webReady ? 'Required web fields are stored for this project.' : 'Complete Website configuration and save.'
                ),
                self::item(
                    'vapid',
                    'VAPID public key configured',
                    is_array($row) && trim((string) ($row['vapid_public_key'] ?? '')) !== '' ? 'done' : 'pending',
                    'Public VAPID key only — never the private key.'
                ),
                self::item(
                    'https',
                    'HTTPS storefront requirement',
                    self::httpsReady() ? 'done' : 'manual',
                    self::httpsDetail()
                ),
                self::item(
                    'storefront_config',
                    'Storefront push config exposed',
                    $storefrontReady ? 'done' : ($webReady && !empty($row['is_active_global']) ? 'pending' : 'pending'),
                    $storefrontReady
                        ? 'Active project exposes web config via push-config.'
                        : 'Requires active project with complete web fields.'
                ),
                self::item(
                    'fid_registration',
                    'Active web FID registration',
                    $fidCount > 0 ? 'done' : 'pending',
                    $fidCount > 0
                        ? $fidCount . ' active web registration(s) on this project.'
                        : 'Customer enables notifications on the website (FID lifecycle).'
                ),
                self::item(
                    'browser_test',
                    'Browser notification tested',
                    'manual',
                    'Manual verification required — test foreground/background via Admin → Send or real events while dry-run is on.'
                ),
            ];
        }

        $android = [];
        if ($platforms['android']) {
            $andReady = is_array($row) && self::androidConfigComplete($row);
            $tokenCount = $projectId > 0 ? self::activeDeviceCount($projectId, 'android', 'fcm_token') : 0;

            $android = [
                self::item(
                    'android_app_registered',
                    'Android app registered in Firebase Console',
                    'manual',
                    'Manual verification required — confirm Android app with matching package name.'
                ),
                self::item(
                    'package_name',
                    'Package name configured in Admin',
                    is_array($row) && trim((string) ($row['android_package_name'] ?? '')) !== '' ? 'done' : 'pending',
                    'Must exactly match the Android app applicationId.'
                ),
                self::item(
                    'android_app_id',
                    'Android Firebase App ID entered',
                    is_array($row) && trim((string) ($row['android_app_id'] ?? '')) !== '' ? 'done' : 'pending',
                    'From Project Settings → Your apps → Android.'
                ),
                self::item(
                    'google_services_json',
                    'google-services.json in Android project',
                    'manual',
                    'Manual verification required — file belongs in android/app/ for Gradle builds, not PHP Admin.'
                ),
                self::item(
                    'gradle_integration',
                    'Gradle / Firebase Messaging SDK integrated',
                    'manual',
                    'Manual verification required in the Android source repository.'
                ),
                self::item(
                    'native_fcm',
                    'Native FCM implementation completed',
                    'manual',
                    'Manual verification required — FirebaseMessagingService, token sync to PHP, channels, POST_NOTIFICATIONS.'
                ),
                self::item(
                    'apk_built',
                    'APK built and installed on device',
                    'manual',
                    'Manual verification required — Admin Panel alone cannot build an APK.'
                ),
                self::item(
                    'device_token',
                    'Active Android FCM registration',
                    $tokenCount > 0 ? 'done' : 'pending',
                    $tokenCount > 0
                        ? $tokenCount . ' active Android token(s) on this project.'
                        : 'Device registers via ajax/push-register.php with platform=android.'
                ),
                self::item(
                    'device_notification_test',
                    'Real device notification tested',
                    'manual',
                    'Manual verification required after dry-run checks.'
                ),
            ];
        }

        $pushEnabled = PushSettings::isEnabled();
        $dryRun = PushSettings::isDryRun();

        $adminWorkflow = [
            self::item(
                'push_enabled',
                'Push sending enabled in settings',
                $pushEnabled ? 'done' : 'pending',
                'Admin → Push → Settings → Enable push.'
            ),
            self::item(
                'dry_run',
                'Dry run mode understood',
                $dryRun ? 'done' : 'manual',
                $dryRun
                    ? 'Dry run ON — logs without live FCM (recommended while testing).'
                    : 'Dry run OFF — live FCM delivery is possible. Enable dry run unless intentionally going live.'
            ),
        ];

        return [
            'shared' => $shared,
            'website' => $website,
            'android' => $android,
            'admin_workflow' => $adminWorkflow,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string}
     */
    private static function item(string $id, string $label, string $status, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function projectDetailsComplete(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        return trim((string) ($row['name'] ?? '')) !== ''
            && trim((string) ($row['firebase_project_id'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function serviceAccountStored(?array $row): bool
    {
        return is_array($row) && trim((string) ($row['service_account_client_email'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function credentialsPassed(?array $row): bool
    {
        return is_array($row) && (string) ($row['last_test_status'] ?? '') === 'passed';
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function credentialsDetail(?array $row): string
    {
        if (!is_array($row)) {
            return 'Save project, then Test credentials on Firebase → Projects.';
        }
        $status = (string) ($row['last_test_status'] ?? 'unknown');
        if ($status === 'passed') {
            return 'Last credential test passed.';
        }
        if ($status === 'failed') {
            $msg = trim((string) ($row['last_test_message'] ?? ''));

            return $msg !== '' ? 'Last test failed: ' . $msg : 'Last credential test failed.';
        }

        return 'Use Test on Firebase → Projects after saving.';
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function projectActivated(?array $row): bool
    {
        return is_array($row)
            && !empty($row['is_active_global'])
            && (string) ($row['status'] ?? '') === 'active';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function webConfigComplete(array $row): bool
    {
        foreach (
            [
                'web_api_key',
                'web_app_id',
                'web_auth_domain',
                'web_storage_bucket',
                'web_measurement_id',
                'vapid_public_key',
                'firebase_project_number',
            ] as $key
        ) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function androidConfigComplete(array $row): bool
    {
        foreach (['android_app_id', 'android_package_name'] as $key) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private static function activeDeviceCount(int $firebaseProjectId, string $platform, string $targetType): int
    {
        if (!PushSchema::tableReady('device_push_registrations') || $firebaseProjectId <= 0) {
            return 0;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM device_push_registrations
                 WHERE firebase_project_id = ? AND platform = ? AND target_type = ? AND status = ?'
            );
            $stmt->execute([$firebaseProjectId, $platform, $targetType, 'active']);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function httpsReady(): bool
    {
        $base = strtolower(SeoUrl::publicSiteBaseUrl());
        if (str_starts_with($base, 'https://')) {
            return true;
        }
        if (str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            return true;
        }

        return false;
    }

    private static function httpsDetail(): string
    {
        $base = SeoUrl::publicSiteBaseUrl();
        if (self::httpsReady()) {
            return 'Public site base: ' . $base;
        }

        return 'Public site is not HTTPS (' . $base . '). Web push requires HTTPS in production.';
    }
}
