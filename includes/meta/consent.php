<?php

declare(strict_types=1);

/**
 * First-party visitor consent for optional cookies/trackers.
 *
 * State is stored in a JS-readable first-party cookie (base64url-encoded JSON,
 * see assets/js/meta-consent.js). PHP reads the same cookie server-side so a
 * forged/malformed/expired value always resolves to "denied" — consent can only
 * ever be missing, never fabricated into a grant.
 */
final class MetaConsent
{
    public const COOKIE_NAME = 'at_consent';
    /** Bump to re-prompt visitors when the consent policy text changes. */
    public const POLICY_VERSION = 1;
    /** Seconds a consent decision remains valid (180 days). */
    public const TTL_SECONDS = 15552000;

    /**
     * Validated consent state for the current request.
     *
     * @return array{set: bool, analytics: bool, marketing: bool, recorded_at: int, expired: bool}
     */
    public static function state(): array
    {
        $default = [
            'set' => false,
            'analytics' => false,
            'marketing' => false,
            'recorded_at' => 0,
            'expired' => false,
        ];

        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || $raw === '' || strlen($raw) > 2048) {
            return $default;
        }
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) {
            return $default;
        }
        try {
            $data = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $default;
        }
        if (!is_array($data)
            || ($data['v'] ?? null) !== self::POLICY_VERSION
            || !is_int($data['t'] ?? null)
            || !is_bool($data['a'] ?? null)
            || !is_bool($data['m'] ?? null)) {
            return $default;
        }

        $now = time();
        $age = $now - (int) $data['t'];
        if ($age < 0 || $age > self::TTL_SECONDS) {
            $default['expired'] = true;

            return $default;
        }

        return [
            'set' => true,
            'analytics' => $data['a'],
            'marketing' => $data['m'],
            'recorded_at' => (int) $data['t'],
            'expired' => false,
        ];
    }

    /** Marketing consent — required before the Meta Pixel may load. */
    public static function marketingAllowed(): bool
    {
        return self::state()['marketing'];
    }

    /** Analytics consent — reserved for future first-party analytics. */
    public static function analyticsAllowed(): bool
    {
        return self::state()['analytics'];
    }

    /**
     * Serializable state for `window.__AT_META__` so meta-consent.js can render
     * the banner/modal without re-parsing the cookie.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        $state = self::state();

        return [
            'cookie' => self::COOKIE_NAME,
            'version' => self::POLICY_VERSION,
            'ttl' => self::TTL_SECONDS,
            'set' => $state['set'],
            'analytics' => $state['analytics'],
            'marketing' => $state['marketing'],
        ];
    }
}
