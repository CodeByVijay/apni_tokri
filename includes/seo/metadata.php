<?php

declare(strict_types=1);

/**
 * Immutable SEO head metadata for a single public HTML response.
 */
final class SeoMetadata
{
    /** @var string */
    public $documentTitle;

    /** @var string|null */
    public $metaDescription;

    /** @var string|null */
    public $canonicalUrl;

    /** @var string|null */
    public $robots;

    /** @var string|null */
    public $ogTitle;

    /** @var string|null */
    public $ogDescription;

    /** @var string|null */
    public $ogImage;

    /** @var string */
    public $ogType;

    /** @var string|null */
    public $twitterCard;

    public function __construct(
        string $documentTitle,
        ?string $metaDescription = null,
        ?string $canonicalUrl = null,
        ?string $robots = null,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?string $ogImage = null,
        string $ogType = 'website',
        ?string $twitterCard = null
    ) {
        $this->documentTitle = $documentTitle;
        $this->metaDescription = $metaDescription;
        $this->canonicalUrl = $canonicalUrl;
        $this->robots = $robots;
        $this->ogTitle = $ogTitle;
        $this->ogDescription = $ogDescription;
        $this->ogImage = $ogImage;
        $this->ogType = $ogType;
        $this->twitterCard = $twitterCard;
    }

    public static function fromLegacy(string $pageTitle, ?string $metaDescription = null, ?string $robots = null): self
    {
        $og = class_exists('BrandingSettings', false) ? BrandingSettings::defaultOgImageUrl() : null;

        return new self(
            $pageTitle,
            self::cleanDescription($metaDescription),
            null,
            $robots ?? 'index, follow',
            null,
            null,
            $og
        );
    }

    public static function noindex(string $documentTitle, ?string $metaDescription = null): self
    {
        return new self(
            $documentTitle,
            self::cleanDescription($metaDescription),
            null,
            'noindex, nofollow'
        );
    }

    public function isIndexableForSitemap(): bool
    {
        $robots = strtolower(trim((string) ($this->robots ?? 'index, follow')));
        if ($robots === '') {
            return true;
        }

        return !str_contains($robots, 'noindex');
    }

    /** @return list<string> HTML fragments (no duplicate tag types). */
    public function headTagLines(): array
    {
        $lines = [];
        $desc = self::cleanDescription($this->metaDescription);
        if ($desc !== null && $desc !== '') {
            $lines[] = '<meta name="description" content="' . Security::escape($desc) . '">';
        }
        if ($this->robots !== null && $this->robots !== '') {
            $lines[] = '<meta name="robots" content="' . Security::escape($this->robots) . '">';
        }
        if ($this->canonicalUrl !== null && $this->canonicalUrl !== '') {
            $lines[] = '<link rel="canonical" href="' . Security::escape($this->canonicalUrl) . '">';
        }
        $ogTitle = trim((string) ($this->ogTitle ?? ''));
        if ($ogTitle === '') {
            $ogTitle = $this->documentTitle;
        }
        $lines[] = '<meta property="og:title" content="' . Security::escape($ogTitle) . '">';
        $ogDesc = self::cleanDescription($this->ogDescription ?? $this->metaDescription);
        if ($ogDesc !== null && $ogDesc !== '') {
            $lines[] = '<meta property="og:description" content="' . Security::escape($ogDesc) . '">';
        }
        if ($this->ogType !== '') {
            $lines[] = '<meta property="og:type" content="' . Security::escape($this->ogType) . '">';
        }
        if ($this->canonicalUrl !== null && $this->canonicalUrl !== '') {
            $lines[] = '<meta property="og:url" content="' . Security::escape($this->canonicalUrl) . '">';
        }
        if ($this->ogImage !== null && $this->ogImage !== '') {
            $lines[] = '<meta property="og:image" content="' . Security::escape($this->ogImage) . '">';
        }
        $card = $this->twitterCard ?? ($this->ogImage !== null && $this->ogImage !== '' ? 'summary_large_image' : 'summary');
        $lines[] = '<meta name="twitter:card" content="' . Security::escape($card) . '">';
        if ($ogTitle !== '') {
            $lines[] = '<meta name="twitter:title" content="' . Security::escape($ogTitle) . '">';
        }
        if ($ogDesc !== null && $ogDesc !== '') {
            $lines[] = '<meta name="twitter:description" content="' . Security::escape($ogDesc) . '">';
        }
        if ($this->ogImage !== null && $this->ogImage !== '') {
            $lines[] = '<meta name="twitter:image" content="' . Security::escape($this->ogImage) . '">';
        }

        return $lines;
    }

    public function renderHeadTags(): string
    {
        return implode("\n    ", $this->headTagLines());
    }

    public static function cleanDescription(?string $text, int $maxLen = 160): ?string
    {
        $text = trim(strip_tags((string) ($text ?? '')));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLen) {
            $text = mb_substr($text, 0, $maxLen - 1) . '…';
        }

        return $text;
    }
}
