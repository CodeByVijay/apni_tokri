<?php

declare(strict_types=1);

/**
 * Admin list pagination with optional page-size control.
 *
 * @param array<string, scalar|null> $queryBase GET params to preserve (excluding page, per_page)
 * @param list<int> $perPageOptions
 */
function admin_list_pagination(
    int $page,
    int $total,
    int $perPage,
    array $queryBase,
    string $formAction,
    array $perPageOptions = [10, 25, 50, 100],
    string $recordLabel = 'records',
    bool $showWhenSinglePage = false,
    bool $showPageOfTotal = false
): void {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    if ($total <= 0) {
        if (!$showWhenSinglePage) {
            return;
        }
    } elseif ($totalPages <= 1 && !$showWhenSinglePage) {
        return;
    }
    $base = array_filter($queryBase, static function ($v): bool {
        return $v !== null && $v !== '';
    });
    $rangeStart = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
    $rangeEnd = $total > 0 ? min($total, $page * $perPage) : 0;
    ?>
    <div class="at-admin-pager flex flex-wrap items-center justify-between gap-3 mt-3">
        <span class="text-sm at-muted" role="status">
            Showing <?= (int) $rangeStart ?>–<?= (int) $rangeEnd ?> of <?= (int) $total ?> <?= Security::escape($recordLabel) ?>
            <?php if ($showPageOfTotal): ?>
                <span class="at-admin-pager__page-of"> · Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <?php endif; ?>
        </span>
        <form method="get" action="<?= Security::escape($formAction) ?>" class="flex flex-wrap items-center gap-2 text-sm">
            <?php foreach ($base as $k => $v): ?>
                <input type="hidden" name="<?= Security::escape((string) $k) ?>" value="<?= Security::escape((string) $v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="page" value="1">
            <label class="flex items-center gap-1">
                <span class="at-muted">Show</span>
                <select name="per_page" class="at-select at-select--sm at-focusable" onchange="this.form.submit()">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?= (int) $opt ?>"<?= $perPage === (int) $opt ? ' selected' : '' ?>><?= (int) $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <nav class="at-admin-pager__nav flex flex-wrap items-center gap-1" aria-label="Pagination">
            <?php
            $linkQs = static function (int $p) use ($base, $perPage): string {
                return http_build_query(array_merge($base, ['page' => $p, 'per_page' => $perPage]));
            };
            $prevQs = $linkQs(max(1, $page - 1));
            $nextQs = $linkQs(min($totalPages, $page + 1));
            ?>
            <?php if ($page > 1): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($prevQs) ?>">Previous</a>
            <?php else: ?>
                <span class="at-btn at-btn--sm at-btn--secondary opacity-50 pointer-events-none" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <?php
            $window = 2;
            $fromPage = max(1, $page - $window);
            $toPage = min($totalPages, $page + $window);
            if ($fromPage > 1): ?>
                <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="?<?= Security::escape($linkQs(1)) ?>">1</a>
                <?php if ($fromPage > 2): ?><span class="px-1 at-muted" aria-hidden="true">…</span><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $fromPage; $p <= $toPage; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="at-btn at-btn--sm at-btn--primary pointer-events-none" aria-current="page"><?= (int) $p ?></span>
                <?php else: ?>
                    <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="?<?= Security::escape($linkQs($p)) ?>"><?= (int) $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($toPage < $totalPages): ?>
                <?php if ($toPage < $totalPages - 1): ?><span class="px-1 at-muted" aria-hidden="true">…</span><?php endif; ?>
                <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="?<?= Security::escape($linkQs($totalPages)) ?>"><?= (int) $totalPages ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($nextQs) ?>">Next</a>
            <?php else: ?>
                <span class="at-btn at-btn--sm at-btn--secondary opacity-50 pointer-events-none" aria-disabled="true">Next</span>
            <?php endif; ?>
        </nav>
    </div>
    <?php
}
