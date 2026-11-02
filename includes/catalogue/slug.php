<?php

declare(strict_types=1);

final class CatalogueSlug
{
    public static function fromName(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'item';
    }

    public static function uniqueProductSlug(string $base, ?int $excludeProductId = null): string
    {
        return self::uniqueInTable('products', 'slug', $base, 'id', $excludeProductId);
    }

    public static function uniqueCategorySlug(string $base, ?int $excludeCategoryId = null): string
    {
        return self::uniqueInTable('categories', 'slug', $base, 'id', $excludeCategoryId);
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
        $slug = self::fromName($base);
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
