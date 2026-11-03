<?php

declare(strict_types=1);

/** Admin UI persistence vs local demo preview (production fails closed). */
final class AdminPersistence
{
    public static function isAvailable(): bool
    {
        return Install::isSchemaReady() && AdminAuth::check();
    }

    /** Demo rows and “saved (demo)” previews when DB or admin session is missing. */
    public static function allowsDemoPreview(): bool
    {
        return !AppEnvironment::isProduction() && !self::isAvailable();
    }

    public static function unavailableMessage(): string
    {
        if (!Install::isSchemaReady()) {
            return 'Database schema is not installed. Import migrations and complete the install checklist before using admin.';
        }
        if (!AdminAuth::check()) {
            return 'Admin session required.';
        }

        return 'Admin persistence is unavailable.';
    }

    public static function renderUnavailableAlert(): void
    {
        if (self::isAvailable()) {
            return;
        }
        $type = AppEnvironment::isProduction() ? 'error' : 'info';
        echo '<div class="at-alert at-alert--' . Security::escape($type) . ' mb-4" role="alert">'
            . Security::escape(self::unavailableMessage()) . '</div>';
    }
}
