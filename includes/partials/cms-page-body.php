<?php

declare(strict_types=1);

/** @var array<string, mixed> $cmsPage */
$cmsTitle = (string) ($cmsPage['title'] ?? 'Page');
$cmsBody = (string) ($cmsPage['body_html'] ?? '');
?>
<div class="at-pagehead">
    <div class="at-container pb-8">
        <nav class="at-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= Security::escape(url_base()) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span aria-current="page"><?= Security::escape($cmsTitle) ?></span>
        </nav>
        <h1 class="at-h1"><?= Security::escape($cmsTitle) ?></h1>
    </div>
</div>
<div class="at-container py-8 md:py-12">
    <div class="cms-body">
        <?= $cmsBody ?>
    </div>
</div>
