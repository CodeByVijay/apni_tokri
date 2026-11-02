<?php

declare(strict_types=1);

/** @var int $skeletonCount */
$skeletonCount = $skeletonCount ?? 8;
?>
<div class="at-grid-products at-grid-products--5" aria-busy="true" aria-label="Loading products">
    <?php for ($i = 0; $i < $skeletonCount; $i++): ?>
        <div class="at-product-card" style="pointer-events:none">
            <div class="at-product-card__media at-skeleton" style="border-radius:0"></div>
            <div class="at-product-card__body">
                <div class="at-skeleton" style="height:1rem;width:80%"></div>
                <div class="at-skeleton" style="height:.75rem;width:45%;margin-top:.5rem"></div>
                <div class="at-product-card__foot">
                    <div class="at-skeleton" style="height:1.4rem;width:3.5rem"></div>
                    <div class="at-skeleton" style="height:2.375rem;width:4.5rem"></div>
                </div>
            </div>
        </div>
    <?php endfor; ?>
</div>
