<?php

declare(strict_types=1);

/**
 * Website footer social profile links (AppSettings keys).
 */
final class BrandingSocialLinks
{
    public const KEY_INSTAGRAM = 'branding.social.instagram';
    public const KEY_FACEBOOK = 'branding.social.facebook';
    public const KEY_YOUTUBE = 'branding.social.youtube';
    public const KEY_WHATSAPP = 'branding.social.whatsapp';
    public const KEY_X = 'branding.social.x';

    /** @var list<string> */
    private const ALL_KEYS = [
        self::KEY_INSTAGRAM,
        self::KEY_FACEBOOK,
        self::KEY_YOUTUBE,
        self::KEY_WHATSAPP,
        self::KEY_X,
    ];

    /** @var array<string, string> post field => settings key */
    private const POST_FIELD_MAP = [
        'social_instagram' => self::KEY_INSTAGRAM,
        'social_facebook' => self::KEY_FACEBOOK,
        'social_youtube' => self::KEY_YOUTUBE,
        'social_whatsapp' => self::KEY_WHATSAPP,
        'social_x' => self::KEY_X,
    ];

    /**
     * @return array<string, string> form field => stored or empty string
     */
    public static function adminFormValues(): array
    {
        $out = [];
        foreach (self::POST_FIELD_MAP as $field => $key) {
            $out[$field] = trim(AppSettings::get($key, '') ?? '');
        }

        return $out;
    }

    /**
     * Links to render in the storefront footer (validated only).
     *
     * @return list<array{id: string, url: string, label: string}>
     */
    public static function visibleFooterLinks(): array
    {
        $links = [];
        foreach (self::platformSpecs() as $id => $spec) {
            $stored = trim(AppSettings::get($spec['key'], '') ?? '');
            if ($stored === '') {
                continue;
            }
            $url = self::normalizePlatformInput($id, $stored);
            if ($url === null) {
                continue;
            }
            $links[] = [
                'id' => $id,
                'url' => $url,
                'label' => $spec['aria_label'],
            ];
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{normalized: array<string, ?string>, errors: array<string, string>}
     */
    public static function validateFromPost(array $post): array
    {
        $errors = [];
        $normalized = [];
        foreach (self::POST_FIELD_MAP as $field => $key) {
            $raw = trim((string) ($post[$field] ?? ''));
            if ($raw === '') {
                $normalized[$key] = null;

                continue;
            }
            $id = self::fieldToPlatformId($field);
            if ($id === null) {
                continue;
            }
            $url = self::normalizePlatformInput($id, $raw);
            if ($url === null) {
                $errors[$field] = self::platformSpecs()[$id]['error'];

                continue;
            }
            $normalized[$key] = $url;
        }

        return ['normalized' => $normalized, 'errors' => $errors];
    }

    /** @return list<string> */
    public static function allSettingKeys(): array
    {
        return self::ALL_KEYS;
    }

    /**
     * @return array<string, array{key: string, label: string, placeholder: string, error: string, aria_label: string}>
     */
    public static function platformSpecs(): array
    {
        return [
            'instagram' => [
                'key' => self::KEY_INSTAGRAM,
                'label' => 'Instagram profile URL',
                'placeholder' => 'https://www.instagram.com/yourshop/',
                'error' => 'Enter a valid Instagram profile URL (https://instagram.com/…).',
                'aria_label' => 'Visit our Instagram page',
            ],
            'facebook' => [
                'key' => self::KEY_FACEBOOK,
                'label' => 'Facebook page URL',
                'placeholder' => 'https://www.facebook.com/yourpage',
                'error' => 'Enter a valid Facebook page or profile URL (https://facebook.com/…).',
                'aria_label' => 'Visit our Facebook page',
            ],
            'youtube' => [
                'key' => self::KEY_YOUTUBE,
                'label' => 'YouTube channel URL',
                'placeholder' => 'https://www.youtube.com/@yourchannel',
                'error' => 'Enter a valid YouTube channel or profile URL (https://youtube.com/…).',
                'aria_label' => 'Visit our YouTube channel',
            ],
            'whatsapp' => [
                'key' => self::KEY_WHATSAPP,
                'label' => 'WhatsApp number or link',
                'placeholder' => '+919876543210 or https://wa.me/919876543210',
                'error' => 'Enter a valid WhatsApp link (https://wa.me/…) or an international number starting with + (country code included).',
                'aria_label' => 'Chat with us on WhatsApp',
            ],
            'x' => [
                'key' => self::KEY_X,
                'label' => 'X profile URL',
                'placeholder' => 'https://x.com/yourhandle',
                'error' => 'Enter a valid X (Twitter) profile URL (https://x.com/… or https://twitter.com/…).',
                'aria_label' => 'Visit our X profile',
            ],
        ];
    }

    private static function fieldToPlatformId(string $field): ?string
    {
        $map = [
            'social_instagram' => 'instagram',
            'social_facebook' => 'facebook',
            'social_youtube' => 'youtube',
            'social_whatsapp' => 'whatsapp',
            'social_x' => 'x',
        ];

        return $map[$field] ?? null;
    }

    public static function normalizePlatformInput(string $platformId, string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $lower = strtolower($input);
        if (str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'file:')
            || str_starts_with($lower, 'vbscript:')) {
            return null;
        }

        switch ($platformId) {
            case 'instagram':
                return self::normalizeInstagram($input);
            case 'facebook':
                return self::normalizeFacebook($input);
            case 'youtube':
                return self::normalizeYouTube($input);
            case 'whatsapp':
                return self::normalizeWhatsApp($input);
            case 'x':
                return self::normalizeX($input);
            default:
                return null;
        }
    }

    private static function normalizeInstagram(string $input): ?string
    {
        $url = self::ensureAbsoluteUrl($input);
        if ($url === null) {
            return null;
        }
        $host = self::normalizeHost($url);
        if (!in_array($host, ['instagram.com', 'instagr.am'], true)) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }
        $segment = explode('/', $path)[0];
        if (in_array(strtolower($segment), ['p', 'reel', 'reels', 'stories', 'tv', 'explore', 'accounts', 'direct'], true)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $segment)) {
            return null;
        }

        return 'https://www.instagram.com/' . $segment . '/';
    }

    private static function normalizeFacebook(string $input): ?string
    {
        $url = self::ensureAbsoluteUrl($input);
        if ($url === null) {
            return null;
        }
        $host = self::normalizeHost($url);
        if (!in_array($host, ['facebook.com', 'fb.com', 'm.facebook.com'], true)) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '' || in_array(strtolower($path), ['login', 'watch', 'gaming'], true)) {
            return null;
        }
        if (str_starts_with(strtolower($path), 'sharer')) {
            return null;
        }

        return self::stripQueryFragment($url);
    }

    private static function normalizeYouTube(string $input): ?string
    {
        $url = self::ensureAbsoluteUrl($input);
        if ($url === null) {
            return null;
        }
        $host = self::normalizeHost($url);
        if (!in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }
        if ($host === 'youtu.be') {
            return self::stripQueryFragment($url);
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'watch') || str_starts_with($lower, 'shorts/')) {
            return null;
        }

        return self::stripQueryFragment($url);
    }

    private static function normalizeX(string $input): ?string
    {
        $url = self::ensureAbsoluteUrl($input);
        if ($url === null) {
            return null;
        }
        $host = self::normalizeHost($url);
        if (!in_array($host, ['x.com', 'twitter.com', 'mobile.twitter.com'], true)) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }
        $segment = explode('/', $path)[0];
        if (in_array(strtolower($segment), ['home', 'search', 'i', 'intent', 'share'], true)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $segment)) {
            return null;
        }

        return 'https://x.com/' . $segment;
    }

    private static function normalizeWhatsApp(string $input): ?string
    {
        if (preg_match('#^https?://#i', $input) || str_starts_with(strtolower($input), 'wa.me')) {
            $candidate = str_starts_with(strtolower($input), 'wa.me') ? 'https://' . ltrim($input, '/') : $input;
            $url = SeoUrl::validatePublicUrl($candidate);
            if ($url === null) {
                return null;
            }
            $host = self::normalizeHost($url);
            if ($host === 'wa.me') {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if (preg_match('#^/(\d{8,15})/?$#', $path, $m)) {
                    return 'https://wa.me/' . $m[1];
                }

                return null;
            }
            if ($host === 'api.whatsapp.com') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $qs);
                $phone = preg_replace('/\D+/', '', (string) ($qs['phone'] ?? '')) ?? '';
                if ($phone !== '' && strlen($phone) >= 8 && strlen($phone) <= 15) {
                    return 'https://wa.me/' . $phone;
                }

                return null;
            }

            return null;
        }

        if (!str_starts_with($input, '+')) {
            return null;
        }
        if (preg_match('/[^0-9\s+\-().]/', $input)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return 'https://wa.me/' . $digits;
    }

    private static function ensureAbsoluteUrl(string $input): ?string
    {
        $trim = trim($input);
        if ($trim === '') {
            return null;
        }

        return SeoUrl::validatePublicUrl($trim);
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
        $normalized = strtolower($parts['scheme']) . '://' . $parts['host'] . $path;
        if (str_ends_with($normalized, '/') && $path !== '/' && $path !== '') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }
}
