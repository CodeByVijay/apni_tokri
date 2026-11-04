<?php

declare(strict_types=1);

/**
 * Environment detection and production safety guards (no data mutation).
 */
final class AppEnvironment
{
    public static function env(): string
    {
        return strtolower(trim(App::env()));
    }

    public static function isProduction(): bool
    {
        $env = self::env();

        return $env === 'production' || $env === 'prod';
    }

    public static function isStaging(): bool
    {
        return self::env() === 'staging';
    }

    public static function isDevelopment(): bool
    {
        return in_array(self::env(), ['local', 'development', 'dev'], true);
    }

    /** Session cart / demo customer — never in production. */
    public static function allowsSessionDemoCommerce(): bool
    {
        return !self::isProduction() && !self::usePersistedStorefront();
    }

    /** Static DemoCatalogue fallback when the DB catalogue is empty. */
    public static function allowsDemoCatalogueFallback(): bool
    {
        return !self::isProduction() && !CommerceGateway::useDatabase();
    }

    /**
     * Database-backed storefront (cart, customer account) even when the catalogue is empty.
     * Production always uses persisted customer/cart when schema is installed.
     */
    public static function usePersistedStorefront(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        if (self::isProduction()) {
            return true;
        }

        return CommerceGateway::useDatabase();
    }

    /** @return array<string, mixed> */
    public static function databaseConfig(): array
    {
        return (array) (App::config()['database'] ?? []);
    }

    public static function databaseName(): string
    {
        return trim((string) (self::databaseConfig()['name'] ?? ''));
    }

    /**
     * Optional config flag: database.role = production marks a DSN as production-target.
     * Used by CLI guards in addition to app.env.
     */
    public static function databaseRoleIsProduction(): bool
    {
        $role = strtolower(trim((string) (self::databaseConfig()['role'] ?? '')));

        return $role === 'production' || $role === 'prod';
    }

    public static function cliOverrideAllowed(): bool
    {
        return getenv('APNI_TOKRI_ALLOW_PROD_CLI') === '1';
    }

    /** Block test/seed scripts from running against production configuration. */
    public static function guardCliScript(string $scriptPath): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        $path = str_replace('\\', '/', $scriptPath);
        $isTest = (bool) preg_match('#/scripts/(test-|qa-)[^/]+\.php$#', $path);
        $isSeed = (bool) preg_match('#/scripts/seed-catalogue\.php$#', $path);
        if (!$isTest && !$isSeed) {
            return;
        }
        if (self::cliOverrideAllowed()) {
            return;
        }
        if (self::isProduction() || self::databaseRoleIsProduction()) {
            fwrite(STDERR, "Refusing to run {$path}: production environment or production database role.\n");
            fwrite(STDERR, "Use a development/staging DSN. Override only with APNI_TOKRI_ALLOW_PROD_CLI=1 if intentional.\n");
            exit(1);
        }
    }

    /** Cron jobs: validate production storage when app.env is production. */
    public static function assertCronEnvironment(): void
    {
        if (!self::isProduction()) {
            return;
        }
        if (!Install::isSchemaReady()) {
            fwrite(STDERR, "Cron aborted: database schema not ready.\n");
            exit(1);
        }
        try {
            InvoiceProductionSecurity::ensureProductionStorageConfigured();
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'Cron aborted: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    /**
     * Production checkout must not use simulate gateway or sandbox test mode.
     *
     * @return array{ok: bool, message?: string}
     */
    public static function assertProductionPaymentsReady(): array
    {
        if (!self::isProduction()) {
            return ['ok' => true];
        }
        if (PaymentGatewayConfig::activeGateway() === 'simulate') {
            return ['ok' => false, 'message' => 'Simulated payments are disabled in production. Configure Razorpay or Cashfree.'];
        }
        if (PaymentGatewayConfig::isTestMode()) {
            return [
                'ok' => false,
                'message' => 'Payment sandbox/test mode is enabled. On production, turn off Test/Sandbox mode and use live Cashfree credentials, or run checkout on a staging environment for sandbox testing.',
            ];
        }
        $gateway = PaymentGatewayConfig::activeGateway();
        $ready = PaymentGatewayConfig::readinessFor($gateway);
        if (!$ready['ok']) {
            return ['ok' => false, 'message' => (string) ($ready['message'] ?? 'Payment gateway is not ready.')];
        }

        return ['ok' => true];
    }

    public static function allowsDebugOtpDisplay(): bool
    {
        return App::isDebug() && !self::isProduction();
    }

    public static function allowsSimulatedPaymentEndpoint(): bool
    {
        return !self::isProduction();
    }
}
