<?php

declare(strict_types=1);

/**
 * Server-side admin list parameters (GET pagination/search/sort).
 * Compatible with DataTables server-side response shape when using dataTablesJson().
 */
final class AdminServerList
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    public static function pageFromRequest(?int $raw): int
    {
        return max(1, $raw ?? 1);
    }

    public static function perPageFromRequest(?int $raw): int
    {
        return self::perPageFromRequestWithOptions($raw, self::PER_PAGE_OPTIONS, self::DEFAULT_PER_PAGE);
    }

    /**
     * @param list<int> $allowed
     */
    public static function perPageFromRequestWithOptions(?int $raw, array $allowed, int $default): int
    {
        $v = $raw ?? $default;

        return in_array($v, $allowed, true) ? $v : $default;
    }

    public static function searchFromRequest(?string $raw): string
    {
        return trim((string) ($raw ?? ''));
    }

    /**
     * Whitelist sort column key; rejects unknown keys (SQL injection safe).
     *
     * @param array<string, string> $allowedMap logical key => SQL expression (no user input)
     */
    public static function resolveSort(string $requested, array $allowedMap, string $defaultKey): string
    {
        return isset($allowedMap[$requested]) ? $requested : $defaultKey;
    }

    public static function sortDirection(string $dir): string
    {
        return strtolower(trim($dir)) === 'asc' ? 'ASC' : 'DESC';
    }

    /** @param array<string, string> $allowedMap */
    public static function orderBySql(string $sortKey, string $dir, array $allowedMap, string $defaultKey): string
    {
        $key = self::resolveSort($sortKey, $allowedMap, $defaultKey);
        $expr = $allowedMap[$key];

        return $expr . ' ' . self::sortDirection($dir);
    }

    public static function escapeLike(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    public static function dataTablesJson(int $draw, int $recordsTotal, int $recordsFiltered, array $data): string
    {
        return json_encode([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
    }

    /** Sort link for column headers (preserves GET filters). */
    public static function sortHeaderLink(
        string $columnKey,
        string $label,
        string $currentSort,
        string $currentDir,
        string $baseUrl,
        array $queryBase
    ): string {
        $dir = ($currentSort === $columnKey && strtolower($currentDir) !== 'asc') ? 'asc' : 'desc';
        $arrow = $currentSort === $columnKey ? (strtolower($currentDir) === 'asc' ? ' ▲' : ' ▼') : '';
        $qs = http_build_query(array_merge($queryBase, ['sort' => $columnKey, 'dir' => $dir, 'page' => 1]));

        return '<a class="underline-offset-2 hover:underline at-focusable" href="'
            . Security::escape($baseUrl . ($qs !== '' ? '?' . $qs : '')) . '">'
            . Security::escape($label . $arrow) . '</a>';
    }
}
