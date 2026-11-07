<?php

declare(strict_types=1);

/**
 * Mobile app store links for the public footer (AppSettings).
 */
final class BrandingAppStores
{
    public const KEY_GOOGLE_ENABLED = 'branding.app.google_enabled';
    public const KEY_GOOGLE_URL = 'branding.app.google_play_url';
    public const KEY_APPLE_ENABLED = 'branding.app.apple_enabled';
    public const KEY_APPLE_URL = 'branding.app.apple_app_store_url';

    public const BADGE_GOOGLE = 'assets/images/badges/google-play-badge.svg';
    public const BADGE_APPLE = 'assets/images/badges/apple-app-store-badge.svg';

    /** @var list<string> */
    private const ALL_KEYS = [
        self::KEY_GOOGLE_ENABLED,
        self::KEY_GOOGLE_URL,
        self::KEY_APPLE_ENABLED,
        self::KEY_APPLE_URL,
    ];

    /**
     * @return array<string, string> form field => value
     */
    public static function adminFormValues(): array
    {
        return [
            'app_google_enabled' => self::isGoogleEnabled() ? '1' : '0',
            'app_google_play_url' => trim(AppSettings::get(self::KEY_GOOGLE_URL, '') ?? ''),
            'app_apple_enabled' => self::isAppleEnabled() ? '1' : '0',
            'app_apple_app_store_url' => trim(AppSettings::get(self::KEY_APPLE_URL, '') ?? ''),
        ];
    }

    public static function isGoogleEnabled(): bool
    {
        return (AppSettings::get(self::KEY_GOOGLE_ENABLED, '') ?? '') === '1';
    }

    public static function isAppleEnabled(): bool
    {
        return (AppSettings::get(self::KEY_APPLE_ENABLED, '') ?? '') === '1';
    }

    /**
     * Store badges to show in the footer (enabled + valid URL only).
     *
     * @return list<array{id: string, url: string, badge_src: string, badge_alt: string}>
     */
    public static function visibleFooterBadges(): array
    {
        $badges = [];
        if (self::isGoogleEnabled()) {
            $url = self::normalizeGooglePlay(trim(AppSettings::get(self::KEY_GOOGLE_URL, '') ?? ''));
            if ($url !== null) {
                $badges[] = [
                    'id' => 'google_play',
                    'url' => $url,
                    'badge_src' => asset_url(self::BADGE_GOOGLE),
                    'badge_alt' => 'Get it on Google Play',
                ];
            }
        }
        if (self::isAppleEnabled()) {
            $url = self::normalizeAppleAppStore(trim(AppSettings::get(self::KEY_APPLE_URL, '') ?? ''));
            if ($url !== null) {
                $badges[] = [
                    'id' => 'apple_app_store',
                    'url' => $url,
                    'badge_src' => asset_url(self::BADGE_APPLE),
                    'badge_alt' => 'Download on the App Store',
                ];
            }
        }

        return $badges;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{normalized: array<string, ?string>, errors: array<string, string>}
     */
    public static function validateFromPost(array $post): array
    {
        $errors = [];
        $normalized = [];

        $googleOn = !empty($post['app_google_enabled']);
        $appleOn = !empty($post['app_apple_enabled']);
        $googleRaw = trim((string) ($post['app_google_play_url'] ?? ''));
        $appleRaw = trim((string) ($post['app_apple_app_store_url'] ?? ''));

        $normalized[self::KEY_GOOGLE_ENABLED] = $googleOn ? '1' : '0';
        $normalized[self::KEY_APPLE_ENABLED] = $appleOn ? '1' : '0';

        if ($googleRaw === '') {
            $normalized[self::KEY_GOOGLE_URL] = null;
        } else {
            $g = self::normalizeGooglePlay($googleRaw);
            if ($g === null) {
                $errors['app_google_play_url'] = 'Enter a valid Google Play URL (https://play.google.com/store/apps/details?id=…).';
            } else {
                $normalized[self::KEY_GOOGLE_URL] = $g;
            }
        }

        if ($appleRaw === '') {
            $normalized[self::KEY_APPLE_URL] = null;
        } else {
            $a = self::normalizeAppleAppStore($appleRaw);
            if ($a === null) {
                $errors['app_apple_app_store_url'] = 'Enter a valid Apple App Store URL (https://apps.apple.com/…/app/…).';
            } else {
                $normalized[self::KEY_APPLE_URL] = $a;
            }
        }

        return ['normalized' => $normalized, 'errors' => $errors];
    }

    /** @return list<string> */
    public static function allSettingKeys(): array
    {
        return self::ALL_KEYS;
    }

    public static function normalizeGooglePlay(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $lower = strtolower($input);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:')) {
            return null;
        }
        $url = SeoUrl::validatePublicUrl($input);
        if ($url === null) {
            return null;
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }
        $host = self::normalizeHost($url);
        if ($host !== 'play.google.com') {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('#^/store/apps/details#', $path)) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = trim((string) ($query['id'] ?? ''));
        if ($id === '' || preg_match('/^[A-Za-z0-9._]+$/', $id) !== 1) {
            return null;
        }

        return 'https://play.google.com/store/apps/details?id=' . $id;
    }

    public static function normalizeAppleAppStore(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $lower = strtolower($input);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:')) {
            return null;
        }
        $url = SeoUrl::validatePublicUrl($input);
        if ($url === null) {
            return null;
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }
        $host = self::normalizeHost($url);
        if ($host !== 'apps.apple.com') {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (strpos(strtolower($path), '/app/') === false) {
            return null;
        }

        return self::stripQueryFragment($url);
    }

    private static function normalizeHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    private static function stripQueryFragment(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '';

        return 'https://' . $parts['host'] . $path;
    }
}
