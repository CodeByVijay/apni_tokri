<?php

declare(strict_types=1);

final class InstallWizardService
{
    public const SESSION_KEY = 'install_wizard_state';

    /** @var string|null Override config output path in tests. */
    public static $testConfigPath = null;

    /** @var string|null Override install lock path in tests. */
    public static $testLockPath = null;

    /** @var string|null Override run lock path in tests. */
    public static $testRunLockPath = null;

    /** When true, allows completeInstallation in CLI tests despite an existing local site config. */
    public static $testBypassInstallGuard = false;

    public static function productionSqlPath(): string
    {
        return APNI_TOKRI_ROOT . '/database/production/apni_tokri_production.sql';
    }

    public static function configLocalPath(): string
    {
        if (self::$testConfigPath !== null) {
            return self::$testConfigPath;
        }

        return APNI_TOKRI_ROOT . '/includes/config.local.php';
    }

    public static function installLockPath(): string
    {
        if (self::$testLockPath !== null) {
            return self::$testLockPath;
        }

        return APNI_TOKRI_ROOT . '/storage/install.lock';
    }

    public static function runLockPath(): string
    {
        if (self::$testRunLockPath !== null) {
            return self::$testRunLockPath;
        }

        return APNI_TOKRI_ROOT . '/storage/.install-run.lock';
    }

    /** @return array<string, mixed> */
    public static function environmentRequirements(): array
    {
        $ext = static function (string $name): bool {
            return extension_loaded($name);
        };

        return [
            'php_version' => [
                'label' => 'PHP 8.1+',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => PHP_VERSION,
            ],
            'pdo_mysql' => ['label' => 'PDO MySQL', 'ok' => $ext('pdo_mysql'), 'detail' => $ext('pdo_mysql') ? 'loaded' : 'missing'],
            'sodium' => ['label' => 'Sodium (invoice encryption)', 'ok' => $ext('sodium'), 'detail' => $ext('sodium') ? 'loaded' : 'missing'],
            'json' => ['label' => 'JSON', 'ok' => $ext('json'), 'detail' => $ext('json') ? 'loaded' : 'missing'],
            'mbstring' => ['label' => 'Multibyte string', 'ok' => $ext('mbstring'), 'detail' => $ext('mbstring') ? 'loaded' : 'missing'],
            'writable_storage' => [
                'label' => 'Project storage/ writable',
                'ok' => is_dir(APNI_TOKRI_ROOT . '/storage') && is_writable(APNI_TOKRI_ROOT . '/storage'),
                'detail' => APNI_TOKRI_ROOT . '/storage',
            ],
            'production_sql' => [
                'label' => 'Production SQL installer present',
                'ok' => is_readable(self::productionSqlPath()),
                'detail' => self::productionSqlPath(),
            ],
        ];
    }

    public static function requirementsMet(): bool
    {
        foreach (self::environmentRequirements() as $row) {
            if (empty($row['ok'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{host?: string, port?: int|string, name?: string, user?: string, password?: string} $db
     * @return array{ok: bool, message?: string}
     */
    public static function testDatabaseConnection(array $db): array
    {
        $cfg = self::normalizeDbInput($db);
        if ($cfg['name'] === '' || $cfg['user'] === '') {
            return ['ok' => false, 'message' => 'Database name and username are required.'];
        }
        try {
            $pdo = self::pdoForConfig($cfg);
            $pdo->query('SELECT 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not connect. Check host, database name, username, and password.'];
        }
    }

    /** @param array<string, mixed> $db */
    public static function databaseHasApplicationSchema(array $db): bool
    {
        try {
            $cfg = self::normalizeDbInput($db);
            $pdo = self::pdoForConfig($cfg);
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute(['settings']);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
            $ver = $pdo->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1"
            )->fetchColumn();

            return is_string($ver) && trim($ver) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, charset: string} $cfg
     */
    private static function pdoForConfig(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        return new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @param array{host?: string, port?: int|string, name?: string, user?: string, password?: string} $db
     * @return array{ok: bool, message?: string, table_count?: int}
     */
    public static function assertDatabaseEmpty(array $db): array
    {
        $test = self::testDatabaseConnection($db);
        if (!$test['ok']) {
            return $test;
        }
        try {
            $pdo = self::pdoForConfig(self::normalizeDbInput($db));
            $count = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->fetchColumn();
            if ($count > 0) {
                return [
                    'ok' => false,
                    'message' => 'This database is not empty (' . $count . ' tables). Create a new empty database for installation. Consolidated SQL cannot be imported over existing data.',
                    'table_count' => $count,
                ];
            }

            return ['ok' => true, 'table_count' => 0];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not inspect database.'];
        }
    }

    /**
     * @param array{host?: string, port?: int|string, name?: string, user?: string, password?: string} $db
     * @return array{ok: bool, message?: string}
     */
    public static function importProductionSchema(array $db, bool $manageRunLock = true): array
    {
        $empty = self::assertDatabaseEmpty($db);
        if (!$empty['ok']) {
            return $empty;
        }
        if ($manageRunLock && !self::acquireRunLock()) {
            return ['ok' => false, 'message' => 'Another installation is already in progress. Wait a minute and try again.'];
        }
        try {
            return self::importProductionSchemaUnlocked($db);
        } finally {
            if ($manageRunLock) {
                self::releaseRunLock();
            }
        }
    }

    /**
     * @param array<string, mixed> $db
     * @return array{ok: bool, message?: string}
     */
    private static function importProductionSchemaUnlocked(array $db): array
    {
        $cfg = self::normalizeDbInput($db);
        $pdo = self::pdoForConfig($cfg);
        $import = InstallSqlImporter::importFile($pdo, self::productionSqlPath());
        if (!$import['ok']) {
            return $import;
        }
        if (!self::databaseHasApplicationSchema($db)) {
            return ['ok' => false, 'message' => 'Import finished but schema verification failed.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string, created?: bool}
     */
    public static function prepareEncryptionKey(string $masterKeyPath, PDO $pdo): array
    {
        if ($masterKeyPath === '') {
            return ['ok' => false, 'message' => 'Master key path is required.'];
        }
        if (!InstallHostingPaths::isOutsideDocumentRoot($masterKeyPath)) {
            return ['ok' => false, 'message' => 'Master key must be stored outside the web document root.'];
        }
        $dir = dirname($masterKeyPath);
        $dirReady = InstallHostingPaths::ensurePrivateDirectory($dir, 0750);
        if (!$dirReady['ok']) {
            return $dirReady;
        }

        $hasInvoices = self::databaseHasInvoiceArtifacts($pdo);
        if (is_file($masterKeyPath)) {
            if (!is_readable($masterKeyPath)) {
                return ['ok' => false, 'message' => 'Existing master key file is not readable: ' . $masterKeyPath];
            }

            return ['ok' => true, 'created' => false];
        }
        if ($hasInvoices) {
            return [
                'ok' => false,
                'message' => 'Encrypted invoice records exist but the master key file is missing. Restore the original key file — a new key cannot be generated automatically.',
            ];
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (@file_put_contents($masterKeyPath, $key, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write master key file. Set permissions on: ' . $dir];
        }
        @chmod($masterKeyPath, 0600);

        return ['ok' => true, 'created' => true];
    }

    public static function databaseHasInvoiceArtifacts(PDO $pdo): bool
    {
        try {
            $exists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'invoices'"
            )->fetchColumn();
            if ($exists === 0) {
                return false;
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();

            return $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function validateAdminAccount(string $email, string $password, string $confirm): array
    {
        $errors = [];
        $email = trim($email);
        if (!Validation::email($email)) {
            $errors['admin_email'] = 'Enter a valid admin email address.';
        }
        $policy = Validation::adminPasswordPolicy($password);
        if ($policy !== null) {
            $errors['admin_password'] = $policy;
        }
        if ($password !== $confirm) {
            $errors['admin_password_confirm'] = 'Passwords do not match.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Please correct the admin account fields.', 'errors' => $errors];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function setInitialAdmin(PDO $pdo, string $email, string $password, string $name = 'Administrator'): array
    {
        $valid = self::validateAdminAccount($email, $password, $password);
        if (!$valid['ok']) {
            return $valid;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $emailNorm = AdminLoginSecurity::normalizeEmail($email);
        $stmt = $pdo->prepare('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'No admin user row found after schema import.'];
        }
        if (AdminLoginSecurity::authEpochColumnReady()) {
            $pdo->prepare(
                'UPDATE admin_users SET email = ?, password_hash = ?, name = ?, is_active = 1, auth_epoch = auth_epoch + 1, updated_at = NOW() WHERE id = ?'
            )->execute([$emailNorm, $hash, trim($name) !== '' ? trim($name) : 'Administrator', $id]);
        } else {
            $pdo->prepare(
                'UPDATE admin_users SET email = ?, password_hash = ?, name = ?, is_active = 1, updated_at = NOW() WHERE id = ?'
            )->execute([$emailNorm, $hash, trim($name) !== '' ? trim($name) : 'Administrator', $id]);
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $db
     * @return array{ok: bool, message?: string}
     */
    public static function writeConfigLocal(array $db, string $masterKeyPath, string $invoicesPath, bool $productionEnv): array
    {
        $path = self::configLocalPath();
        if (is_file($path) && !is_writable($path)) {
            return ['ok' => false, 'message' => 'config.local.php exists and is not writable. Fix permissions or remove it manually before installing.'];
        }
        $defaultConfig = APNI_TOKRI_ROOT . '/includes/config.local.php';
        if (
            is_file($path)
            && filesize($path) > 0
            && realpath($path) === realpath($defaultConfig)
            && Install::hasLocalConfig()
            && Install::isApplicationInstalled()
            && !self::$testBypassInstallGuard
        ) {
            return ['ok' => false, 'message' => 'Configuration already exists. This wizard does not overwrite an installed site.'];
        }

        $cfg = self::normalizeDbInput($db);
        $urlPath = self::detectUrlPath();
        $baseUrl = self::detectBaseUrl($urlPath);
        $content = self::renderConfigPhp($cfg, $masterKeyPath, $invoicesPath, $productionEnv, $baseUrl, $urlPath);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return ['ok' => false, 'message' => 'Configuration directory missing.'];
        }
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write config.local.php. Ensure the includes/ directory is writable by PHP (recommended 0640).'];
        }
        @chmod($path, 0640);

        return ['ok' => true];
    }

    /** @return array{ok: bool, message?: string} */
    public static function writeInstallLock(): array
    {
        $path = self::installLockPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $payload = json_encode([
            'installed_at' => gmdate('c'),
            'schema_version' => Install::SCHEMA_VERSION,
            'php' => PHP_VERSION,
        ], JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write installation lock file in storage/.'];
        }
        @chmod($path, 0640);

        return ['ok' => true];
    }

    /**
     * Full finish: storage, key, admin, config, lock.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function completeInstallation(array $payload): array
    {
        if (!self::requirementsMet()) {
            return ['ok' => false, 'message' => 'Server requirements are not met.'];
        }
        if (!self::$testBypassInstallGuard && Install::isPublicInstallBlocked()) {
            return ['ok' => false, 'message' => 'Installation is not available on this site.'];
        }
        if (is_file(self::configLocalPath()) && Install::isApplicationInstalled() && Install::hasInstallLock()) {
            return ['ok' => false, 'message' => 'Application is already installed.'];
        }

        $db = self::normalizeDbInput($payload);
        $adminEmail = trim((string) ($payload['admin_email'] ?? ''));
        $adminPassword = (string) ($payload['admin_password'] ?? '');
        $adminConfirm = (string) ($payload['admin_password_confirm'] ?? $adminPassword);
        $adminName = trim((string) ($payload['admin_name'] ?? 'Administrator'));
        $productionEnv = !empty($payload['production_env']);

        $adminValid = self::validateAdminAccount($adminEmail, $adminPassword, $adminConfirm);
        if (!$adminValid['ok']) {
            return $adminValid;
        }

        if (!self::acquireRunLock()) {
            return ['ok' => false, 'message' => 'Another installation is already in progress.'];
        }

        try {
            Database::disconnect();
            $cfg = self::normalizeDbInput($db);
            try {
                $pdo = self::pdoForConfig($cfg);
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => 'Could not connect to the database with the supplied credentials.'];
            }

            if (!self::databaseHasApplicationSchema($db)) {
                $empty = self::assertDatabaseEmpty($db);
                if (!$empty['ok']) {
                    return $empty;
                }
                $import = self::importProductionSchemaUnlocked($db);
                if (!$import['ok']) {
                    return $import;
                }
                $pdo = self::pdoForConfig($cfg);
            }
            $layout = InstallHostingPaths::defaultPrivateLayout();
            $invoicesPath = trim((string) ($payload['invoices_path'] ?? $layout['invoices_dir']));
            $masterKeyPath = trim((string) ($payload['master_key_path'] ?? $layout['master_key_path']));

            $invDir = InstallHostingPaths::ensurePrivateDirectory($invoicesPath, 0750);
            if (!$invDir['ok']) {
                return $invDir;
            }
            $keyResult = self::prepareEncryptionKey($masterKeyPath, $pdo);
            if (!$keyResult['ok']) {
                return $keyResult;
            }

            $admin = self::setInitialAdmin($pdo, $adminEmail, $adminPassword, $adminName);
            if (!$admin['ok']) {
                return $admin;
            }

            $cfg = self::writeConfigLocal($db, $masterKeyPath, $invoicesPath, $productionEnv);
            if (!$cfg['ok']) {
                return $cfg;
            }

            AppSettings::resetCache();
            $lock = self::writeInstallLock();
            if (!$lock['ok']) {
                return $lock;
            }

            return ['ok' => true];
        } finally {
            self::releaseRunLock();
        }
    }

    /** @param array<string, mixed> $db */
    private static function normalizeDbInput(array $db): array
    {
        return [
            'host' => trim((string) ($db['host'] ?? '127.0.0.1')),
            'port' => (int) ($db['port'] ?? 3306),
            'name' => trim((string) ($db['name'] ?? '')),
            'user' => trim((string) ($db['user'] ?? '')),
            'password' => (string) ($db['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
    }

    private static function detectUrlPath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.') {
            return '';
        }

        return rtrim(rawurldecode($dir), '/');
    }

    private static function detectBaseUrl(string $urlPath): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;
        if ($urlPath !== '') {
            $base .= $urlPath;
        }

        return $base;
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, charset: string} $db
     */
    private static function renderConfigPhp(
        array $db,
        string $masterKeyPath,
        string $invoicesPath,
        bool $productionEnv,
        string $baseUrl,
        string $urlPath
    ): string {
        $esc = static function (string $s): string {
            return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
        };
        $env = $productionEnv ? 'production' : 'local';
        $debug = $productionEnv ? 'false' : 'true';
        $cookieSecure = $productionEnv ? 'true' : 'false';

        return <<<PHP
<?php

declare(strict_types=1);

/** Generated by Apni Tokri installation wizard — adjust carefully. */
return [
    'app' => [
        'name' => 'Apni Tokri',
        'tagline' => 'Har Ghar Ki Taazgi',
        'env' => '{$esc($env)}',
        'debug' => {$debug},
        'timezone' => 'Asia/Kolkata',
        'base_url' => '{$esc($baseUrl)}',
        'url_path' => '{$esc($urlPath)}',
    ],
    'database' => [
        'host' => '{$esc($db['host'])}',
        'port' => {$db['port']},
        'name' => '{$esc($db['name'])}',
        'user' => '{$esc($db['user'])}',
        'password' => '{$esc($db['password'])}',
        'charset' => 'utf8mb4',
    ],
    'encryption' => [
        'master_key_path' => '{$esc($masterKeyPath)}',
    ],
    'storage' => [
        'invoices_path' => '{$esc($invoicesPath)}',
    ],
    'session' => [
        'name' => 'APNI_TOKRI_SESSID',
        'lifetime' => 7200,
        'cookie_secure' => {$cookieSecure},
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],
    'install' => [
        'expected_schema_version' => '{$esc(Install::SCHEMA_VERSION)}',
    ],
    'security' => [
        'admin_login' => [
            'max_failures_email' => 5,
            'max_failures_ip' => 20,
            'window_minutes' => 15,
            'lock_minutes' => 15,
        ],
        'admin_password_reset' => [
            'token_ttl_seconds' => 3600,
            'max_requests_email' => 3,
            'max_requests_ip' => 10,
            'window_minutes' => 60,
        ],
        'support_guest' => [
            'max_per_ip' => 5,
            'window_minutes' => 60,
        ],
    ],
];

PHP;
    }

    /** @var resource|null */
    private static $runLockHandle = null;

    public static function acquireRunLock(): bool
    {
        $path = self::runLockPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return false;
        }
        self::$runLockHandle = $fp;

        return true;
    }

    public static function releaseRunLock(): void
    {
        if (self::$runLockHandle !== null) {
            flock(self::$runLockHandle, LOCK_UN);
            fclose(self::$runLockHandle);
            self::$runLockHandle = null;
        }
    }
}
