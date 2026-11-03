<?php

declare(strict_types=1);

/**
 * Customer account list pager (Previous / Next, page x of y).
 *
 * @param array<string, scalar|null> $queryBase GET params to preserve (excluding page)
 */
function customer_account_pagination(int $page, int $total, int $perPage, array $queryBase = []): void
{
    if ($perPage <= 0 || $total <= 0) {
        return;
    }
    $totalPages = AccountListPagination::totalPages($total, $perPage);
    if ($totalPages <= 1) {
        return;
    }

    $base = $queryBase;
    if ($perPage !== AccountListPagination::DEFAULT_PER_PAGE) {
        $base['per_page'] = $perPage;
    } else {
        unset($base['per_page']);
    }

    $prevHref = '';
    $nextHref = '';
    if ($page > 1) {
        $prevQs = http_build_query(array_filter(array_merge($base, ['page' => $page - 1])));
        $prevHref = '?' . $prevQs;
    }
    if ($page < $totalPages) {
        $nextQs = http_build_query(array_filter(array_merge($base, ['page' => $page + 1])));
        $nextHref = '?' . $nextQs;
    }
    ?>
    <nav class="at-account-pager mt-4" aria-label="Pagination">
        <p class="at-account-pager__meta">
            Page <?= (int) $page ?> of <?= (int) $totalPages ?>
            <span class="at-muted">· <?= (int) $total ?> total</span>
        </p>
        <div class="at-account-pager__nav">
            <?php if ($prevHref !== ''): ?>
                <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape($prevHref) ?>">Previous</a>
            <?php else: ?>
                <span class="at-btn at-btn--secondary at-btn--sm opacity-50 pointer-events-none" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <?php if ($nextHref !== ''): ?>
                <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape($nextHref) ?>">Next</a>
            <?php else: ?>
                <span class="at-btn at-btn--secondary at-btn--sm opacity-50 pointer-events-none" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>
    <?php
}
