<?php

declare(strict_types=1);

/** @var string $sectionTitle */
/** @var string|null $sectionSubtitle */
/** @var string|null $sectionHref */
/** @var string|null $sectionLinkLabel */
/** @var list<array<string, mixed>> $products */
?>
<section class="at-section">
    <div class="at-container">
        <div class="at-section-head">
            <div>
                <h2 class="at-h2"><?= Security::escape($sectionTitle) ?></h2>
                <?php if (!empty($sectionSubtitle)): ?>
                    <p><?= Security::escape($sectionSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($sectionHref) && !empty($sectionLinkLabel)): ?>
                <a href="<?= Security::escape($sectionHref) ?>" class="at-link-arrow at-focusable"><?= Security::escape($sectionLinkLabel) ?></a>
            <?php endif; ?>
        </div>
        <?php if ($products === []): ?>
            <?php
            $emptyTitle = 'No products in this section';
            $emptyMessage = 'Admin will configure homepage sections in a later stage.';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
            <div class="at-rail at-rail--5">
                <?php foreach ($products as $product): ?>
                    <?php require APNI_TOKRI_ROOT . '/includes/partials/product-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
