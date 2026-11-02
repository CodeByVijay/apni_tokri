<?php

declare(strict_types=1);

/**
 * @param array<string, scalar|null> $queryBase GET params to preserve (excluding page)
 */
function admin_report_pagination(int $page, int $total, int $perPage, array $queryBase): void
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($totalPages <= 1) {
        return;
    }
    $prevQs = http_build_query(array_filter(array_merge($queryBase, ['page' => $page - 1])));
    $nextQs = http_build_query(array_filter(array_merge($queryBase, ['page' => min($totalPages, $page + 1)])));
    ?>
    <div class="at-admin-pager">
        <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> · <?= (int) $total ?> records</span>
        <span class="at-admin-pager__nav">
            <?php if ($page > 1): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($prevQs) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($nextQs) ?>">Next</a>
            <?php endif; ?>
        </span>
    </div>
    <?php
}
