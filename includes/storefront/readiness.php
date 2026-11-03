<?php

declare(strict_types=1);

/** Storefront operational checks (catalogue published, production rules). */
final class StorefrontReadiness
{
    public static function schemaInstalled(): bool
    {
        return Install::isSchemaReady();
    }

    public static function cataloguePublished(): bool
    {
        if (!self::schemaInstalled()) {
            return false;
        }
        try {
            return ProductRepository::productCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Customer can browse and purchase (or dev demo catalogue). */
    public static function isStorefrontOperational(): bool
    {
        if (!self::schemaInstalled()) {
            return AppEnvironment::allowsDemoCatalogueFallback();
        }
        if (self::cataloguePublished()) {
            return true;
        }
        if (AppEnvironment::isProduction()) {
            return false;
        }

        return AppEnvironment::allowsDemoCatalogueFallback();
    }

    /** @return list<string> human-readable blockers for admin */
    public static function productionBlockers(): array
    {
        $issues = [];
        if (!self::schemaInstalled()) {
            $issues[] = 'Database schema is not installed.';
        }
        if (AppEnvironment::isProduction()) {
            $missing = Install::missingProductionRequiredTables();
            if ($missing !== []) {
                $issues[] = 'Missing required database tables: ' . implode(', ', $missing) . '.';
            }
        }
        if (self::schemaInstalled() && !self::cataloguePublished()) {
            $issues[] = 'No published products in the catalogue. Import or create products before go-live.';
        }
        $pay = AppEnvironment::assertProductionPaymentsReady();
        if (!$pay['ok']) {
            $issues[] = (string) ($pay['message'] ?? 'Payment gateway is not production-ready.');
        }

        return $issues;
    }
}
