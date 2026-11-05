<?php

declare(strict_types=1);

/**
 * Customer account shell (nav + content column). Close with account-shell-end.php.
 *
 * @var string      $accountActive  overview|profile|addresses|orders|invoices|subscriptions|support|reviews|refunds
 * @var string      $accountTitle
 * @var string|null $accountSub
 */
$accountSub = $accountSub ?? null;
$_acBase = url_base();
$_acLoggedIn = CustomerService::isLoggedIn();
$_acItems = [
    'overview' => ['/my-account.php', 'Overview', 'user'],
    'profile' => ['/my-profile.php', 'Profile', 'user'],
    'addresses' => ['/my-addresses.php', 'Addresses', 'pin'],
    'orders' => ['/my-orders.php', 'Orders', 'receipt'],
    'invoices' => ['/my-invoices.php', 'Invoices', 'receipt'],
    'refunds' => ['/my-refunds.php', 'Refunds', 'receipt'],
    'subscriptions' => ['/my-subscriptions.php', 'Subscriptions', 'repeat'],
    'reviews' => ['/submit-review.php', 'Reviews', 'star'],
    'support' => ['/my-support.php', 'Support', 'chat'],
];
?>
<div class="at-pagehead">
    <div class="at-container pb-6">
        <nav class="at-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= Security::escape($_acBase) ?>/index.php">Home</a>
            <?php if ($_acLoggedIn): ?>
                <span class="sep">/</span><span>My account</span>
                <?php if ($accountActive !== 'overview'): ?>
                    <span class="sep">/</span><span aria-current="page"><?= Security::escape($_acItems[$accountActive][1] ?? $accountTitle ?? '') ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="sep">/</span><span aria-current="page"><?= Security::escape($accountTitle ?? 'Support') ?></span>
            <?php endif; ?>
        </nav>
        <h1 class="at-h1"><?= Security::escape($accountTitle) ?></h1>
        <?php if ($accountSub): ?><p class="mt-1 at-muted"><?= Security::escape($accountSub) ?></p><?php endif; ?>
    </div>
</div>
<div class="at-container py-6 md:py-8 at-account">
    <?php if ($_acLoggedIn): ?>
        <nav class="at-account-nav at-no-scrollbar" aria-label="Account">
            <?php foreach ($_acItems as $key => $it): ?>
                <a href="<?= Security::escape($_acBase . $it[0]) ?>" class="at-focusable <?= $accountActive === $key ? 'is-active' : '' ?>" <?= $accountActive === $key ? 'aria-current="page"' : '' ?>><?= at_icon($it[2]) ?><?= Security::escape($it[1]) ?></a>
            <?php endforeach; ?>
            <form method="post" action="<?= Security::escape($_acBase) ?>/logout" class="at-account-nav__logout">
                <?= Csrf::field() ?>
                <button type="submit" class="at-focusable"><?= at_icon('logout') ?>Logout</button>
            </form>
        </nav>
    <?php else: ?>
        <aside class="at-card at-account-guest" aria-label="Sign in">
            <div class="at-card__body grid gap-3 text-sm">
                <p class="font-bold">Customer account</p>
                <p class="at-muted">Sign in to view orders, invoices, subscriptions, and your support ticket history.</p>
                <a href="<?= Security::escape($_acBase) ?>/login.php" class="at-btn at-btn--primary at-btn--block at-focusable">Login / Register</a>
                <a href="<?= Security::escape($_acBase) ?>/faqs.php" class="at-link-arrow !text-sm">Browse FAQs</a>
            </div>
        </aside>
    <?php endif; ?>
    <div class="min-w-0 grid gap-5 at-account-main">
