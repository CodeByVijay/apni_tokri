<?php

declare(strict_types=1);

/**
 * Server-side pagination for customer account list pages.
 */
final class AccountListPagination
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 50;

    /**
     * @param array<string, mixed>|null $query
     * @return array{page: int, per_page: int, offset: int, unlimited: bool}
     */
    public static function fromRequest(?array $query = null): array
    {
        $q = $query ?? $_GET;
        $page = isset($q['page']) ? (int) $q['page'] : 1;
        $perPage = isset($q['per_page']) ? (int) $q['per_page'] : self::DEFAULT_PER_PAGE;

        return self::normalize($page, $perPage);
    }

    /**
     * @return array{page: int, per_page: int, offset: int, unlimited: bool}
     */
    public static function normalize(int $page, int $perPage, bool $allowUnlimited = false): array
    {
        if ($allowUnlimited && $perPage === 0) {
            return [
                'page' => 1,
                'per_page' => 0,
                'offset' => 0,
                'unlimited' => true,
            ];
        }

        $perPage = $perPage > self::MAX_PER_PAGE ? self::MAX_PER_PAGE : $perPage;
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $page = $page < 1 ? 1 : $page;
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'unlimited' => false,
        ];
    }

    public static function totalPages(int $total, int $perPage): int
    {
        if ($perPage <= 0 || $total <= 0) {
            return 1;
        }

        return max(1, (int) ceil($total / $perPage));
    }

    public static function clampPage(int $page, int $totalPages): int
    {
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        return min(max(1, $page), $totalPages);
    }

    /**
     * @return array{rows: list<mixed>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function emptyResult(int $perPage): array
    {
        $per = $perPage > 0 ? $perPage : self::DEFAULT_PER_PAGE;

        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $per,
            'total_pages' => 1,
        ];
    }
}
