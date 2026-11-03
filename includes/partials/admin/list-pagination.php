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
    array $perPageOptions = [10, 25, 50, 100]
): void {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $base = array_filter($queryBase, static function ($v): bool {
        return $v !== null && $v !== '';
    });
    ?>
    <div class="at-admin-pager flex flex-wrap items-center justify-between gap-3 mt-3">
        <span class="text-sm at-muted">
            Page <?= (int) $page ?> of <?= (int) $totalPages ?> · <?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?>
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
        <span class="at-admin-pager__nav">
            <?php
            $prevQs = http_build_query(array_merge($base, ['page' => max(1, $page - 1), 'per_page' => $perPage]));
            $nextQs = http_build_query(array_merge($base, ['page' => min($totalPages, $page + 1), 'per_page' => $perPage]));
            ?>
            <?php if ($page > 1): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($prevQs) ?>">Previous</a>
            <?php else: ?>
                <span class="at-btn at-btn--sm at-btn--secondary opacity-50 pointer-events-none" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($nextQs) ?>">Next</a>
            <?php else: ?>
                <span class="at-btn at-btn--sm at-btn--secondary opacity-50 pointer-events-none" aria-disabled="true">Next</span>
            <?php endif; ?>
        </span>
    </div>
    <?php
}
