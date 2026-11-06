<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    $_SESSION['checkout_return'] = url_base() . '/subscriptions.php';
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$base = url_base();
$plans = SubscriptionCheckoutService::activeStorefrontPlanCards();

layout_public_start([
    'pageTitle' => 'Subscription plans — Veggie Tokri',
    'activeNav' => 'home',
]);
?>
<div class="at-container py-8 md:py-12">
    <div class="at-section-head">
        <div>
            <p class="at-eyebrow">Prepaid plans</p>
            <h1 class="at-h1 mt-1">Choose a subscription</h1>
            <p class="at-muted mt-2 max-w-2xl">Prices and delivery rules are loaded from our server. Pay 100% upfront; your plan is activated only after verified payment.</p>
        </div>
    </div>

    <?php if ($plans === []): ?>
        <div class="at-alert at-alert--info mt-6">Subscription plans will be available soon.</div>
    <?php else: ?>
        <div class="at-plans mt-8">
            <?php foreach ($plans as $i => $plan): ?>
                <article class="at-plan <?= $i === 1 ? 'at-plan--featured' : '' ?>">
                    <h2 class="at-h3"><?= Security::escape($plan['name']) ?></h2>
                    <?php if ($plan['description'] !== ''): ?>
                        <p class="text-sm at-muted mt-2"><?= Security::escape($plan['description']) ?></p>
                    <?php endif; ?>
                    <p class="at-plan__price mt-4"><?= Security::escape(catalogue_format_price((float) $plan['price'])) ?></p>
                    <?php if ($plan['compare_at_price'] !== null && $plan['compare_at_price'] > $plan['price']): ?>
                        <p class="text-sm line-through at-muted"><?= Security::escape(catalogue_format_price((float) $plan['compare_at_price'])) ?></p>
                    <?php endif; ?>
                    <ul class="flex-1 mt-4 text-sm">
                        <li><?= at_icon('check') ?><span><?= (int) $plan['calendar_duration_days'] ?>-day calendar window</span></li>
                        <li><?= at_icon('check') ?><span><?= (int) $plan['delivery_count'] ?> scheduled deliveries · <?= Security::escape($plan['frequency_label']) ?></span></li>
                        <li><?= at_icon('check') ?><span>Daily basket allowance ≈ <?= Security::escape(catalogue_format_price((float) $plan['daily_allowance_inr'])) ?></span></li>
                        <li><?= at_icon('check') ?><span>Unused allowance: <?= Security::escape($plan['unused_allowance_policy'] === 'carry_forward' ? 'carries forward' : 'expires daily') ?></span></li>
                    </ul>
                    <a href="<?= Security::escape($base) ?>/subscribe.php?plan=<?= (int) $plan['id'] ?>" class="at-btn at-btn--primary at-btn--block mt-5">View &amp; subscribe</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $cmsSubTermsSuffix = 'before paying.';
    $cmsSubTermsClass = 'mt-6 text-sm at-muted';
    require APNI_TOKRI_ROOT . '/includes/partials/cms-subscription-terms-link.php';
    ?>
</div>
<?php layout_public_end(); ?>
