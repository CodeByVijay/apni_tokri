<?php

declare(strict_types=1);

final class PushSettings
{
    public const KEY_ENABLED = 'push.enabled';
    public const KEY_DRY_RUN = 'push.dry_run';
    public const KEY_ROLLBACK_PROJECT_ID = 'push.firebase.rollback_project_id';
    public const MANUAL_SEND_DAILY_MAX = 20;

    public static function isEnabled(): bool
    {
        return PushNotificationService::sendsAllowed();
    }

    public static function isDryRun(): bool
    {
        return PushNotificationService::dryRunOnly();
    }

    /**
     * @return array{enabled: string, dry_run: string, environment: string, is_production: bool}
     */
    public static function adminFormValues(): array
    {
        return [
            'enabled' => trim((string) (AppSettings::get(self::KEY_ENABLED, AppEnvironment::isProduction() ? '0' : '1') ?? '')),
            'dry_run' => trim((string) (AppSettings::get(self::KEY_DRY_RUN, AppEnvironment::isProduction() ? '0' : '1') ?? '')),
            'environment' => AppEnvironment::env(),
            'is_production' => AppEnvironment::isProduction(),
            'rollback_project_id' => (int) (AppSettings::get(self::KEY_ROLLBACK_PROJECT_ID, '0') ?? '0'),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: list<string>}
     */
    public static function saveFromRequest(array $post): array
    {
        $enabled = !empty($post['push_enabled']) ? '1' : '0';
        $dryRun = !empty($post['push_dry_run']) ? '1' : '0';
        if (AppEnvironment::isProduction() && $enabled === '1' && $dryRun === '0') {
            $active = FirebaseProjectManager::activeProject(false);
            if ($active === null || (string) ($active['last_test_status'] ?? '') !== 'passed') {
                return [
                    'ok' => false,
                    'errors' => ['On production, enabling live push requires an active Firebase project with a passed credential test.'],
                ];
            }
        }
        AppSettings::set(self::KEY_ENABLED, $enabled);
        AppSettings::set(self::KEY_DRY_RUN, $dryRun);

        return ['ok' => true, 'message' => 'Push settings saved.'];
    }

    public static function recordManualSendAttempt(): bool
    {
        if (!Install::isSchemaReady() || !AdminAuth::check()) {
            return false;
        }
        $adminId = (int) AdminAuth::id();
        if ($adminId <= 0) {
            return false;
        }
        $day = date('Y-m-d');
        $key = 'push.manual_send_count.' . $adminId . '.' . $day;
        $count = (int) (AppSettings::get($key, '0') ?? '0');
        if ($count >= self::MANUAL_SEND_DAILY_MAX) {
            return false;
        }
        AppSettings::set($key, (string) ($count + 1));

        return true;
    }

    public static function manualSendRemainingToday(): int
    {
        if (!AdminAuth::check()) {
            return 0;
        }
        $key = 'push.manual_send_count.' . (int) AdminAuth::id() . '.' . date('Y-m-d');
        $count = (int) (AppSettings::get($key, '0') ?? '0');

        return max(0, self::MANUAL_SEND_DAILY_MAX - $count);
    }
}
