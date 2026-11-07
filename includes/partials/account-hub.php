<?php

declare(strict_types=1);

/**
 * Mobile Account Hub (shown below 768px on /my-account; desktop keeps the sidebar).
 *
 * Expects from my-account.php: $base, $accountActive, $profileName, $profileMobile, $profileEmail,
 * $profileInitial, $orderTotal, $subscriptionTotal, $addresses, $recentOrders.
 */
$_hubItems = [
    'orders' => ['/my-orders.php', 'Orders', 'box'],
    'subscriptions' => ['/my-subscriptions.php', 'Subscriptions', 'repeat'],
    'addresses' => ['/my-addresses.php', 'Addresses', 'pin'],
    'invoices' => ['/my-invoices.php', 'Invoices', 'receipt'],
    'refunds' => ['/my-refunds.php', 'Refunds', 'wallet'],
    'reviews' => ['/submit-review.php', 'Reviews', 'star'],
    'profile' => ['/my-profile.php', 'Profile', 'user'],
    'support' => ['/my-support.php', 'Support', 'chat'],
];
$_hubStats = [
    ['orders', 'Orders', (int) $orderTotal, '/my-orders.php'],
    ['subscriptions', 'Subscriptions', (int) $subscriptionTotal, '/my-subscriptions.php'],
    ['addresses', 'Addresses', count($addresses), '/my-addresses.php'],
];
$_hubName = $profileName !== '' ? $profileName : 'Customer';
?>
<div class="at-hub">
    <a href="<?= Security::escape($base) ?>/my-profile.php" class="at-hub-profile at-focusable" aria-label="<?= Security::escape('View profile for ' . $_hubName) ?>">
        <span class="at-hub-profile__avatar" aria-hidden="true"><?= Security::escape($profileInitial) ?></span>
        <span class="at-hub-profile__text">
            <span class="at-hub-profile__name"><?= Security::escape($_hubName) ?></span>
            <?php if ($profileMobile !== ''): ?><span class="at-hub-profile__line"><?= Security::escape($profileMobile) ?></span><?php endif; ?>
            <?php if ($profileEmail !== ''): ?><span class="at-hub-profile__line"><?= Security::escape($profileEmail) ?></span><?php endif; ?>
        </span>
        <?= at_icon('chevron-right', 'at-hub-profile__chevron') ?>
    </a>

    <div class="at-hub-stats">
        <?php foreach ($_hubStats as $stat): ?>
            <a href="<?= Security::escape($base . $stat[3]) ?>" class="at-hub-stat at-focusable">
                <strong><?= (int) $stat[2] ?></strong>
                <span><?= Security::escape($stat[1]) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <nav class="at-hub-grid" aria-label="Account sections">
        <?php foreach ($_hubItems as $key => $item): $isCurrent = ($accountActive ?? '') === $key; ?>
            <a href="<?= Security::escape($base . $item[0]) ?>"
                class="at-hub-grid__item at-focusable<?= $isCurrent ? ' is-active' : '' ?>"
                <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                <span class="at-hub-grid__icon"><?= at_icon($item[2], 'at-hub-grid__svg') ?></span>
                <span class="at-hub-grid__label"><?= Security::escape($item[1]) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="at-hub-recent" aria-labelledby="at-hub-recent-title">
        <div class="at-hub-recent__head">
            <h2 class="at-h3" id="at-hub-recent-title">Recent orders</h2>
            <a href="<?= Security::escape($base) ?>/my-orders.php" class="at-link-arrow at-focusable">View All</a>
        </div>
        <?php if ($recentOrders === []): ?>
            <div class="at-card"><div class="at-card__body text-sm at-muted">
                You haven’t placed an order yet.
                <a href="<?= Security::escape($base) ?>/index.php" class="at-btn at-btn--primary at-btn--sm at-focusable mt-3 block text-center">Start shopping</a>
            </div></div>
        <?php else: ?>
            <ul class="at-hub-recent__list">
                <?php foreach ($recentOrders as $ro):
                    $roId = (string) ($ro['id'] ?? $ro['order_number'] ?? '');
                    $roSt = (string) ($ro['status'] ?? '');
                    $roTotal = catalogue_format_price((float) ($ro['totals']['total'] ?? $ro['grand_total'] ?? 0));
                    $roTs = strtotime((string) ($ro['created_at'] ?? ''));
                    $roDate = $roTs !== false ? date('j M Y', $roTs) : '';
                    $roThumb = '';
                    foreach ($ro['items'] ?? [] as $roIt) {
                        if (!empty($roIt['image'])) {
                            $roThumb = (string) $roIt['image'];
                            break;
                        }
                    }
                    $roUrl = $base . '/order-details.php?id=' . rawurlencode($roId);
                    ?>
                    <li class="at-card at-hub-order">
                        <span class="at-hub-order__thumb" aria-hidden="true">
                            <?php if ($roThumb !== ''): ?><img src="<?= Security::escape($roThumb) ?>" alt="" loading="lazy" class="w-full h-full object-cover"><?php else: ?><?= at_icon('bag', 'at-hub-order__thumb-icon') ?><?php endif; ?>
                        </span>
                        <span class="at-hub-order__main">
                            <a href="<?= Security::escape($roUrl) ?>" class="at-hub-order__number at-hub-order__link at-focusable font-mono font-bold"
                                aria-label="<?= Security::escape('Order ' . $roId . ', ' . ucwords(str_replace('_', ' ', $roSt)) . ', total ' . $roTotal . '. View details') ?>"><?= Security::escape($roId) ?></a>
                            <span class="at-hub-order__status"><?= customer_status_badge($roSt, ucwords(str_replace('_', ' ', $roSt)), 'order') ?></span>
                            <?php if ($roDate !== ''): ?><span class="at-hub-order__date at-muted"><?= Security::escape($roDate) ?></span><?php endif; ?>
                        </span>
                        <span class="at-hub-order__aside">
                            <strong><?= Security::escape($roTotal) ?></strong>
                            <?= at_icon('chevron-right', 'at-hub-order__chevron') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
