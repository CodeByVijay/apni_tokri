<?php

declare(strict_types=1);

/** Design-time storefront previews (loading skeletons, etc.) — not for public production. */
final class StorefrontDesignPreview
{
    public static function allowsLoadingPreview(): bool
    {
        if (AppEnvironment::isProduction()) {
            return false;
        }
        if (AppEnvironment::isDevelopment()) {
            return true;
        }
        if (AdminAuth::check() && AdminDemoPerms::can('settings')) {
            return true;
        }

        return false;
    }
}
