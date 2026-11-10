<?php

declare(strict_types=1);

/**
 * Helpers for scripts/run-in-disposable-db.php — disposable DB naming, MySQL CLI, migrations.
 */

const DISPOSABLE_TMP_DB_REGEX = '/^apni_tokri_tmp_[0-9]{14}_[0-9a-f]{4}$/';

function disposable_is_tmp_db_name(string $name): bool
{
    return $name !== '' && preg_match(DISPOSABLE_TMP_DB_REGEX, $name) === 1;
}

function disposable_assert_tmp_db_name(string $name, string $context): void
{
    if (!disposable_is_tmp_db_name($name)) {
        throw new RuntimeException('Refusing ' . $context . ': invalid disposable database name.');
    }
}

function disposable_refuse_drop(string $name, string $originalAppDb): bool
{
    if (!disposable_is_tmp_db_name($name)) {
        fwrite(STDERR, "ABORT: refusing DROP — not a disposable database name.\n");

        return true;
    }
    if ($name === $originalAppDb) {
        fwrite(STDERR, "ABORT: refusing to DROP application database `{$name}`.\n");

        return true;
    }

    return false;
}

function disposable_generate_tmp_db_name(): string
{
    $name = 'apni_tokri_tmp_' . date('YmdHis') . '_' . bin2hex(random_bytes(2));
    disposable_assert_tmp_db_name($name, 'CREATE DATABASE');

    return $name;
}

function disposable_resolve_mysql_cli(): string
{
    $env = getenv('MYSQL_BIN');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }
    $xampp = dirname(APNI_TOKRI_ROOT) . '/../mysql/bin/mysql.exe';
    $xampp = realpath($xampp);
    if (is_string($xampp) && is_file($xampp)) {
        return $xampp;
    }
    $xamppAlt = 'D:/xampp/mysql/bin/mysql.exe';
    if (is_file($xamppAlt)) {
        return $xamppAlt;
    }

    return 'mysql';
}

/**
 * Optional CLI credential override for disposable runs only (e.g. empty XAMPP root password).
 * Set APNI_TOKRI_DISPOSABLE_MYSQL_PASS in the environment (empty string allowed).
 *
 * @param array<string, mixed> $db
 * @return array<string, mixed>
 */
function disposable_apply_cli_credential_override(array $db): array
{
    $raw = getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS');
    if ($raw === false) {
        return $db;
    }
    $db['password'] = $raw;
    unset($db['pass']);

    return $db;
}

/**
 * @param array<string, mixed> $db
 */
function disposable_mysql_exec(array $db, string $extra, ?string $targetDatabase = null): int
{
    $db = disposable_apply_cli_credential_override($db);
    if ($targetDatabase !== null) {
        disposable_assert_tmp_db_name($targetDatabase, 'mysql target database');
    }
    $bin = disposable_resolve_mysql_cli();
    $cmd = escapeshellarg($bin)
        . ' -h ' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1'))
        . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $pass = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($pass !== '') {
        $cmd .= ' --password=' . escapeshellarg($pass);
    }
    // Never pass --password= with empty value; breaks Windows cmd and differs from CLI default.
    $null = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
    if (PHP_OS_FAMILY === 'Windows' && str_contains($extra, '<')) {
        $cmd = 'cmd /C ' . $cmd . ' ' . $extra . ' ' . $null;
    } else {
        $cmd .= ' ' . $null . ' ' . $extra;
    }
    exec($cmd, $out, $code);

    return (int) $code;
}

/**
 * @param array<string, mixed> $db
 */
function disposable_connect_pdo(array $db, ?string $databaseName = null): PDO
{
    $db = disposable_apply_cli_credential_override($db);
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $user = (string) ($db['user'] ?? 'root');
    $pass = (string) ($db['pass'] ?? $db['password'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');
    if ($databaseName !== null) {
        disposable_assert_tmp_db_name($databaseName, 'PDO connect');
        $dsn = "mysql:host={$host};port={$port};dbname={$databaseName};charset={$charset}";
    } else {
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/**
 * Import SQL via PDO (avoids shell redirection; safe on Windows).
 *
 * @param array<string, mixed> $db
 */
function disposable_import_sql_file(array $db, string $tmpDbName, string $absoluteSqlPath): int
{
    disposable_assert_tmp_db_name($tmpDbName, 'import');
    if (!is_readable($absoluteSqlPath)) {
        return 1;
    }
    require_once APNI_TOKRI_ROOT . '/includes/install/sql_importer.php';
    try {
        $pdo = disposable_connect_pdo($db, $tmpDbName);
        $result = InstallSqlImporter::importFile($pdo, $absoluteSqlPath);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Disposable SQL import failed: ' . $e->getMessage() . "\n");

        return 1;
    }

    return !empty($result['ok']) ? 0 : 1;
}

/**
 * Apply a migration SQL file to the current PDO connection (disposable DB only).
 *
 * @return array{ok: bool, message?: string}
 */
function disposable_apply_migration_file(PDO $pdo, string $tmpDbName, string $migrationPath): array
{
    disposable_assert_tmp_db_name($tmpDbName, 'migration');
    $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($current !== $tmpDbName) {
        return ['ok' => false, 'message' => 'Migration refused: PDO is not connected to disposable database.'];
    }
    if (!is_readable($migrationPath)) {
        return ['ok' => false, 'message' => 'Migration file not readable: ' . $migrationPath];
    }
    require_once APNI_TOKRI_ROOT . '/includes/install/sql_importer.php';
    $result = InstallSqlImporter::importFile($pdo, $migrationPath);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Migration failed.')];
    }
    if (str_contains($migrationPath, 'media_library')) {
        require_once APNI_TOKRI_ROOT . '/includes/media/media_library.php';
        if (!MediaLibrary::isEnabled()) {
            return ['ok' => false, 'message' => 'Migration finished but media_assets is still missing.'];
        }
    }

    return ['ok' => true];
}

/**
 * @return list<string>
 */
function disposable_firebase_push_migration_paths(): array
{
    $root = APNI_TOKRI_ROOT . '/database/migrations/';

    return [
        $root . '1.39.0_firebase_push_notifications.sql',
        $root . '1.39.1_push_registration_target_type.sql',
        $root . '1.39.2_push_subscription_templates.sql',
        $root . '1.39.3_push_coupon_template.sql',
    ];
}
