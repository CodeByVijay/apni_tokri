<?php

declare(strict_types=1);

final class Database
{
    /** @var PDO|null */
    private static $connection = null;

    /** @param array<string, mixed> $dbConfig */
    public static function connect(array $dbConfig): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = (string) ($dbConfig['host'] ?? '127.0.0.1');
        $port = (int) ($dbConfig['port'] ?? 3306);
        $name = (string) ($dbConfig['name'] ?? '');
        $user = (string) ($dbConfig['user'] ?? '');
        $password = (string) ($dbConfig['password'] ?? '');
        $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');

        if ($name === '' || $user === '') {
            throw new RuntimeException('Database name and user must be configured in config.local.php.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        self::$connection = new PDO($dsn, $user, $password, $options);
        self::$connection->exec("SET time_zone = '+05:30'");

        return self::$connection;
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    /** @return string MySQL session time_zone after connect (for tests). */
    public static function sessionTimeZone(): string
    {
        $pdo = self::pdo();

        return (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
    }

    public static function pdo(): PDO
    {
        if (!self::$connection instanceof PDO) {
            throw new RuntimeException('Database not connected. Call Database::connect() first.');
        }

        return self::$connection;
    }

    /** Quick connectivity check for health/install pages. */
    public static function ping(array $dbConfig): bool
    {
        try {
            $pdo = self::connect($dbConfig);
            $pdo->query('SELECT 1');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
