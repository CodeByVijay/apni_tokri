<?php

declare(strict_types=1);

/** Guards against publishing installer placeholder CMS copy by mistake. */
final class CmsInstallSafety
{
    private const PLACEHOLDER_MARKER = 'Content placeholder. Replace with';

    /** @var list<string> */
    private const SEED_SLUGS = [
        'about-us',
        'contact-us',
        'privacy-policy',
        'terms',
        'refund-policy',
        'shipping-policy',
        'subscription-terms',
        'disclaimer',
    ];

    /**
     * @param array<string, mixed> $pageData title, slug, body_html
     * @return string|null Error message when publish must be blocked
     */
    public static function publishBlockedReason(array $pageData): ?string
    {
        $slug = trim((string) ($pageData['slug'] ?? ''));
        $body = (string) ($pageData['body_html'] ?? '');
        if ($slug === '' || !in_array($slug, self::SEED_SLUGS, true)) {
            return null;
        }
        if (str_contains($body, self::PLACEHOLDER_MARKER)) {
            return 'This page still contains installer placeholder text. Replace the content before publishing.';
        }

        return null;
    }
}
