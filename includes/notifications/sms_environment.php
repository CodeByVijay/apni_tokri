<?php

declare(strict_types=1);

/**
 * Server-side environment gates for SMS test mode (never trust UI or hostname alone).
 */
final class SmsEnvironmentService
{
    /** @var bool|null test-only override */
    private static $forceProduction = null;

    public static function setTestProductionOverride(?bool $isProduction): void
    {
        self::$forceProduction = $isProduction;
    }

    public static function appEnv(): string
    {
        return strtolower(trim((string) (App::env() ?? 'local')));
    }

    public static function isProduction(): bool
    {
        if (self::$forceProduction !== null) {
            return self::$forceProduction;
        }
        $env = self::appEnv();

        return $env === 'production' || $env === 'prod';
    }

    public static function allowsTestMode(): bool
    {
        if (self::isProduction()) {
            return false;
        }
        $env = self::appEnv();

        return in_array($env, ['local', 'staging', 'development', 'dev'], true);
    }

    public static function environmentLabel(): string
    {
        return self::appEnv();
    }
}
