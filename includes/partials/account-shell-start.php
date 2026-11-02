<?php

declare(strict_types=1);

/**
 * Customer account shell (nav + content column). Close with account-shell-end.php.
 *
 * @var string      $accountActive  overview|profile|addresses|orders|subscriptions|support|reviews|refunds
 * @var string      $accountTitle
 * @var string|null $accountSub
 */
$accountSub = $accountSub ?? null;
$_acBase = url_base();
$_acItems = [
    'overview' => ['/my-account.php', 'Overview', 'user'],
    'profile' => ['/my-profile.php', 'Profile', 'user'],
    'addresses' => ['/my-addresses.php', 'Addresses', 'pin'],
    'orders' => ['/my-orders.php', 'Orders', 'receipt'],
    'refunds' => ['/my-refunds.php', 'Refunds', 'receipt'],
    'subscriptions' => ['/my-subscriptions.php', 'Subscriptions', 'repeat'],
    'reviews' => ['/submit-review.php', 'Reviews', 'star'],
    'support' => ['/my-support.php', 'Support', 'chat'],
];
?>
<div class="at-pagehead">
    <div class="at-container pb-6">
        <nav class="at-breadcrumb" aria-label="Breadcrumb"><a href="<?= Security::escape($_acBase) ?>/index.php">Home</a><span class="sep">/</span><span>My account</span><?php if ($accountActive !== 'overview'): ?><span class="sep">/</span><span aria-current="page"><?= Security::escape($_acItems[$accountActive][1] ?? '') ?></span><?php endif; ?></nav>
        <h1 class="at-h1"><?= Security::escape($accountTitle) ?></h1>
        <?php if ($accountSub): ?><p class="mt-1 at-muted"><?= Security::escape($accountSub) ?></p><?php endif; ?>
    </div>
</div>
<div class="at-container py-6 md:py-8 at-account">
    <nav class="at-account-nav at-no-scrollbar" aria-label="Account">
        <?php foreach ($_acItems as $key => $it): ?>
            <a href="<?= Security::escape($_acBase . $it[0]) ?>" class="at-focusable <?= $accountActive === $key ? 'is-active' : '' ?>" <?= $accountActive === $key ? 'aria-current="page"' : '' ?>><?= at_icon($it[2]) ?><?= Security::escape($it[1]) ?></a>
        <?php endforeach; ?>
        <form method="post" action="<?= Security::escape($_acBase) ?>/logout.php" class="at-account-nav__logout">
            <?= Csrf::field() ?>
            <button type="submit" class="at-focusable"><?= at_icon('logout') ?>Logout</button>
        </form>
    </nav>
    <div class="min-w-0 grid gap-5">
