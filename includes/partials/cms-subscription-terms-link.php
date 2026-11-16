<?php

declare(strict_types=1);

/** @var string $cmsSubTermsSuffix Text after the link (e.g. "before subscribing.") */
/** @var string $cmsSubTermsClass Wrapper paragraph classes */
$cmsSubTermsSuffix = $cmsSubTermsSuffix ?? 'before subscribing.';
$cmsSubTermsClass = $cmsSubTermsClass ?? 'mt-4 text-sm at-muted';
$subTermsUrl = CmsPageService::publicPageUrl('subscription-terms');
?>
<p class="<?= Security::escape($cmsSubTermsClass) ?>">
    <?php if ($subTermsUrl !== null): ?>
        Read the <a class="underline text-[var(--at-green)] font-semibold" href="<?= Security::escape($subTermsUrl) ?>">subscription terms</a> <?= Security::escape($cmsSubTermsSuffix) ?>
    <?php else: ?>
        Subscription terms are not published yet. <?= Security::escape($cmsSubTermsSuffix) ?> Contact the store if you need details.
    <?php endif; ?>
</p>
