<?php

declare(strict_types=1);

/** @var string|null $pageActionsHtml */
$pageActionsHtml = $pageActionsHtml ?? '';
/** @var list<array{label: string, href?: string}>|null $breadcrumbs */
$breadcrumbs = $breadcrumbs ?? [];
?>
<div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <?php if ($breadcrumbs !== []): ?>
            <nav class="text-xs at-muted mb-1" aria-label="Breadcrumb">
                <?php foreach ($breadcrumbs as $i => $crumb): ?>
                    <?php if ($i > 0): ?><span class="mx-1">/</span><?php endif; ?>
                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= Security::escape($crumb['href']) ?>" class="hover:underline"><?= Security::escape($crumb['label']) ?></a>
                    <?php else: ?>
                        <span><?= Security::escape($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if (!empty($pageHeading)): ?>
            <h2 class="text-xl font-extrabold"><?= Security::escape($pageHeading) ?></h2>
        <?php endif; ?>
        <?php if (!empty($pageDescription)): ?>
            <p class="text-sm at-muted mt-1"><?= Security::escape($pageDescription) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($pageActionsHtml !== ''): ?>
        <div class="flex flex-wrap gap-2"><?= $pageActionsHtml ?></div>
    <?php endif; ?>
</div>
