<?php

declare(strict_types=1);

final class SubscriptionCronLock
{
    private const LOCK_NAME = 'apni_tokri_subscription_cron';

    /** @return array{ok: bool, message?: string} */
    public static function acquire(string $job): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        Install::ensureConnection();
        $key = self::LOCK_NAME . '_' . $job;
        $stmt = Database::pdo()->query("SELECT GET_LOCK(" . Database::pdo()->quote($key) . ", 0)");
        $got = (int) ($stmt ? $stmt->fetchColumn() : 0);

        return $got === 1;
    }

    public static function release(string $job): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        Install::ensureConnection();
        $key = self::LOCK_NAME . '_' . $job;
        Database::pdo()->query('SELECT RELEASE_LOCK(' . Database::pdo()->quote($key) . ')');
    }
}
