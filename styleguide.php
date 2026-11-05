<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

AppEnvironment::denyProductionDevelopmentSurface();

Security::sendSecurityHeaders(Security::isHttps());

layout_public_start([
    'pageTitle' => 'Design system — Apni Tokri',
    'activeNav' => '',
    'showBottomNav' => false,
]);
?>
<div class="max-w-8xl mx-auto px-4 py-8 space-y-10">
    <header>
        <h1 class="text-2xl font-bold text-brand-green">Apni Tokri design system</h1>
        <p class="mt-1 text-neutral-600">Stage 1 reference — buttons, forms, cards, badges, alerts, modal. Colors: green <code class="text-sm bg-white px-1 rounded">#245C3A</code>, yellow <code class="text-sm bg-white px-1 rounded">#F5A623</code>, cream <code class="text-sm bg-white px-1 rounded">#FFF8E9</code>.</p>
    </header>

    <section>
        <h2 class="font-bold text-lg mb-3">Buttons</h2>
        <div class="flex flex-wrap gap-3">
            <button type="button" class="at-btn at-btn--primary at-focusable">Primary</button>
            <button type="button" class="at-btn at-btn--secondary at-focusable">Secondary</button>
            <button type="button" class="at-btn at-btn--accent at-focusable">Accent</button>
            <button type="button" class="at-btn at-btn--ghost at-focusable">Ghost</button>
            <button type="button" class="at-btn at-btn--primary at-focusable" disabled>Disabled</button>
        </div>
    </section>

    <section>
        <h2 class="font-bold text-lg mb-3">Badges</h2>
        <div class="flex flex-wrap gap-2">
            <span class="at-badge at-badge--green">Fresh</span>
            <span class="at-badge at-badge--yellow">Low stock</span>
            <span class="at-badge at-badge--sale">Sale</span>
            <span class="at-badge at-badge--neutral">Packaged</span>
        </div>
    </section>

    <section>
        <h2 class="font-bold text-lg mb-3">Alerts</h2>
        <div class="space-y-3 max-w-xl">
            <div class="at-alert at-alert--info">Info — delivery checker opens in a modal on the storefront.</div>
            <div class="at-alert at-alert--success">Success — order placed (example).</div>
            <div class="at-alert at-alert--warning">Warning — slot almost full (example).</div>
            <div class="at-alert at-alert--error">Error — payment failed; cart preserved (example).</div>
        </div>
    </section>

    <section class="max-w-md">
        <h2 class="font-bold text-lg mb-3">Form fields</h2>
        <label class="at-label" for="sg-name">Name</label>
        <input id="sg-name" type="text" class="at-input at-focusable" placeholder="Customer name">
        <p class="at-field-hint">Helper text under the field.</p>
        <label class="at-label mt-4" for="sg-mobile">Mobile</label>
        <input id="sg-mobile" type="tel" class="at-input at-focusable at-input--error" value="123">
        <p class="at-field-error">Enter a valid 10-digit mobile number.</p>
    </section>

    <section>
        <h2 class="font-bold text-lg mb-3">Card</h2>
        <article class="at-card max-w-sm">
            <div class="at-card__body">
                <span class="at-badge at-badge--green">Product card pattern</span>
                <h3 class="at-card__title mt-2">Sample product name</h3>
                <p class="text-sm text-neutral-600 mt-1">₹48 · 500 g · In stock</p>
                <button type="button" class="at-btn at-btn--primary at-btn--block at-focusable mt-3">Add to cart</button>
            </div>
        </article>
    </section>

    <section>
        <h2 class="font-bold text-lg mb-3">Modal</h2>
        <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-open-modal="#at-styleguide-modal">Open modal</button>
    </section>
</div>

<div id="at-styleguide-modal" class="at-modal-backdrop" aria-hidden="true">
    <div class="at-modal" role="dialog" aria-labelledby="sg-modal-title">
        <div class="at-modal__header">
            <h2 id="sg-modal-title" class="at-modal__title">Example modal</h2>
            <button type="button" class="at-focusable p-2" data-at-modal-close aria-label="Close">✕</button>
        </div>
        <div class="at-modal__body">
            <p class="text-sm">Used for delivery check, variant pickers and confirmations in later stages.</p>
        </div>
        <div class="at-modal__footer">
            <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-modal-close">Cancel</button>
            <button type="button" class="at-btn at-btn--primary at-focusable">Confirm</button>
        </div>
    </div>
</div>
<?php layout_public_end(); ?>
