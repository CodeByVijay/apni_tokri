<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $products */
?>
<div class="at-grid-products at-grid-products--5">
    <?php foreach ($products as $product): ?>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/product-card.php'; ?>
    <?php endforeach; ?>
</div>
