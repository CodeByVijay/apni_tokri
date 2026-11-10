<?php

declare(strict_types=1);

final class Install
{
    public const SCHEMA_VERSION = '1.39.4-firebase-platform-flags';

    /** Seeded bootstrap admin (fresh SQL import). Detection uses password hash only — never expose the plain password. */
    public const SEED_ADMIN_EMAIL = 'admin@apnitokri.local';

    public const SEED_ADMIN_PASSWORD_HASH = '$2y$12$ZWyGAkJzaocH1IQRf0yBjeURZhrA92ZSaJXLL8ds07DQL3tf22./2';

    public static function seedAdminPasswordHashMatchesImport(string $storedHash): bool
    {
        return hash_equals(self::SEED_ADMIN_PASSWORD_HASH, $storedHash);
    }

    /** @var list<string> */
    private static $markerTables = ['settings', 'admin_users', 'customers', 'products', 'orders'];

    /** Tables required for production storefront + admin SEO/marketing/subscriptions. */
    /** @var list<string> */
    private static $productionRequiredTables = [
        'settings',
        'admin_users',
        'customers',
        'products',
        'orders',
        'seo_redirects',
        'faq_categories',
        'faqs',
        'subscription_plans',
        'customer_subscriptions',
        'subscription_payments',
    ];

    public static function hasLocalConfig(): bool
    {
        return is_readable(APNI_TOKRI_ROOT . '/includes/config.local.php');
    }

    public static function canConnect(): bool
    {
        if (!self::hasLocalConfig()) {
            return false;
        }
        try {
            return Database::ping(App::config()['database']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensureConnection(): void
    {
        if (!self::hasLocalConfig()) {
            throw new RuntimeException('Missing config.local.php');
        }
        Database::connect(App::config()['database']);
    }

    /** @return list<string> */
    public static function missingTables(): array
    {
        if (!self::canConnect()) {
            return self::$markerTables;
        }
        try {
            self::ensureConnection();
            $pdo = Database::pdo();
            $missing = [];
            foreach (self::$markerTables as $table) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $stmt->execute([$table]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $missing[] = $table;
                }
            }

            return $missing;
        } catch (Throwable $e) {
            return self::$markerTables;
        }
    }

    public static function isSchemaReady(): bool
    {
        return self::missingTables() === [];
    }

    /** @return list<string> */
    public static function missingProductionRequiredTables(): array
    {
        if (!self::canConnect()) {
            return self::$productionRequiredTables;
        }
        try {
            self::ensureConnection();
            $pdo = Database::pdo();
            $missing = [];
            foreach (self::$productionRequiredTables as $table) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $stmt->execute([$table]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $missing[] = $table;
                }
            }

            return $missing;
        } catch (Throwable $e) {
            return self::$productionRequiredTables;
        }
    }

    public static function isProductionSchemaReady(): bool
    {
        return self::missingProductionRequiredTables() === [];
    }

    public static function installedSchemaVersion(): ?string
    {
        if (!self::isSchemaReady()) {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute(['schema_version']);

            return $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isVersionCurrent(): bool
    {
        $v = self::installedSchemaVersion();

        return $v === self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public static function statusReport(): array
    {
        return [
            'config' => self::hasLocalConfig(),
            'connect' => self::canConnect(),
            'schema_ready' => self::isSchemaReady(),
            'schema_version' => self::installedSchemaVersion(),
            'schema_current' => self::isVersionCurrent(),
            'missing_tables' => self::missingTables(),
        ];
    }

    /** Schema imported and version recorded — treat install wizard as complete. */
    public static function isApplicationInstalled(): bool
    {
        return self::isSchemaReady() && self::installedSchemaVersion() !== null && self::installedSchemaVersion() !== '';
    }

    public static function installLockPath(): string
    {
        if (class_exists('InstallWizardService', false) && InstallWizardService::$testLockPath !== null) {
            return InstallWizardService::$testLockPath;
        }

        return APNI_TOKRI_ROOT . '/storage/install.lock';
    }

    public static function hasInstallLock(): bool
    {
        $path = self::installLockPath();

        return is_file($path) && is_readable($path);
    }

    /** Whether public install.php must not be shown (installed site or lock present). */
    public static function isPublicInstallBlocked(): bool
    {
        if (self::hasInstallLock()) {
            return true;
        }

        return self::isApplicationInstalled() && self::hasLocalConfig();
    }

    /**
     * Block public install/health surfaces after installation (AT-SEC-004).
     * Sends 404 and exits when installed.
     */
    public static function denyPublicInstallSurface(): void
    {
        if (!self::isPublicInstallBlocked()) {
            return;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        exit;
    }
}
