<?php

declare(strict_types=1);

/**
 * Central slug generation and admin save resolution for catalogue & CMS entities.
 */
final class CatalogueSlug
{
    /** @var bool|null */
    private static $intlTransliteratorOk;

    /**
     * SEO slug from a display name/title (transliteration + hyphen rules).
     */
    public static function fromName(string $name): string
    {
        $text = trim($name);
        if ($text === '') {
            return '';
        }
        $text = self::transliterate($text);
        $slug = mb_strtolower($text, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    /** @param string $default used when name yields no slug characters */
    public static function fromNameOrDefault(string $name, string $default = 'item'): string
    {
        $slug = self::fromName($name);

        return $slug !== '' ? $slug : $default;
    }

    /** Normalize admin-entered slug (lowercase, hyphen rules; no transliteration). */
    public static function normalizeInput(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $slug = mb_strtolower($raw, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    public static function slugTaken(string $entity, string $slug, ?int $excludeId = null): bool
    {
        if ($slug === '') {
            return false;
        }
        switch ($entity) {
            case 'category':
                return CategoryRepository::slugTaken($slug, $excludeId);
            case 'product':
                return ProductRepository::slugTaken($slug, $excludeId);
            case 'cms_page':
                return CmsPageRepository::slugTaken($slug, $excludeId);
            case 'delivery_slot':
                return DeliverySlotRepository::slugInUse($slug, $excludeId);
            default:
                return false;
        }
    }

    /**
     * Resolve slug for admin save from posted fields.
     *
     * @param array<string, mixed> $input expects slug, optional slug_manual
     * @param array<string, mixed>|null $existingRow
     * @return array{ok: true, slug: string}|array{ok: false, errors: array<string, string>}
     */
    public static function resolveForAdminSave(
        string $entity,
        string $sourceLabel,
        array $input,
        ?array $existingRow,
        ?int $excludeId
    ): array {
        $sourceLabel = trim($sourceLabel);
        $slugRaw = trim((string) ($input['slug'] ?? ''));
        $slugManual = !empty($input['slug_manual']);

        if ($slugRaw === '') {
            $slug = self::fromName($sourceLabel);
        } elseif ($slugManual) {
            $slug = self::normalizeInput($slugRaw);
        } else {
            $slug = self::normalizeInput($slugRaw);
            if ($slug === '' && $sourceLabel !== '') {
                $slug = self::fromName($sourceLabel);
            }
        }

        $errors = [];
        if ($slug === '') {
            $errors['slug'] = 'URL slug is required. Enter a slug or ' . ($entity === 'cms_page' ? 'title' : 'name') . ' to generate one.';
        } elseif (!Validation::slug($slug)) {
            $errors['slug'] = 'Use lowercase letters, numbers and hyphens only.';
        } elseif (self::slugTaken($entity, $slug, $excludeId)) {
            $errors['slug'] = 'This slug is already in use. Choose another slug.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'slug' => $slug];
    }

    /** @deprecated Prefer resolveForAdminSave + explicit duplicate errors */
    public static function uniqueProductSlug(string $base, ?int $excludeProductId = null): string
    {
        return self::uniqueInTable('products', 'slug', $base, 'id', $excludeProductId);
    }

    /** @deprecated Prefer resolveForAdminSave + explicit duplicate errors */
    public static function uniqueCategorySlug(string $base, ?int $excludeCategoryId = null): string
    {
        return self::uniqueInTable('categories', 'slug', $base, 'id', $excludeCategoryId);
    }

    private static function transliterate(string $text): string
    {
        if (self::intlTransliteratorAvailable()) {
            $tr = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $out = $tr->transliterate($text);
                if (is_string($out) && trim($out) !== '') {
                    return $out;
                }
            }
        }
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($out) && trim($out) !== '') {
                return $out;
            }
        }

        return $text;
    }

    private static function intlTransliteratorAvailable(): bool
    {
        if (self::$intlTransliteratorOk !== null) {
            return self::$intlTransliteratorOk;
        }
        self::$intlTransliteratorOk = class_exists('Transliterator', false)
            && Transliterator::create('Any-Latin; Latin-ASCII') !== null;

        return self::$intlTransliteratorOk;
    }

    private static function uniqueInTable(
        string $table,
        string $slugColumn,
        string $base,
        string $idColumn,
        ?int $excludeId
    ): string {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $slug = self::fromNameOrDefault($base);
        $candidate = $slug;
        $n = 0;
        while (true) {
            $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $slugColumn . '` = ?';
            $params = [$candidate];
            if ($excludeId !== null) {
                $sql .= ' AND `' . $idColumn . '` != ?';
                $params[] = $excludeId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $n++;
            $candidate = $slug . '-' . $n;
        }
    }
}
