<?php

declare(strict_types=1);

/**
 * First-party cookie consent — banner + preferences dialog.
 * Rendered once by includes/layout/footer-public.php. Behaviour lives in
 * assets/js/meta-consent.js; styles in assets/css/consent.css.
 * Hidden by default — JS reveals the banner only when no valid consent exists.
 */
$consentPrivacyUrl = url_base() . '/page.php?slug=privacy-policy';
?>
<div id="at-consent" class="at-consent" hidden>
    <div class="at-consent__card" role="region" aria-label="Cookie consent">
        <div class="at-consent__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5.5c0 4.4 3 8 7 9.5 4-1.5 7-5.1 7-9.5V6l-7-3z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>
        </div>
        <div class="at-consent__body">
            <h2 class="at-consent__title" id="at-consent-title">Your privacy matters</h2>
            <p class="at-consent__text">We use cookies to keep your basket and sign-in working. With your permission we also use analytics and marketing cookies — including the Meta Pixel — to improve the shop and measure ads. Change your choice anytime in Cookie Preferences. <a class="at-consent__link at-focusable" href="<?= Security::escape($consentPrivacyUrl) ?>">Privacy Policy</a></p>
        </div>
        <div class="at-consent__actions">
            <button type="button" class="at-consent__btn at-consent__btn--primary at-focusable" data-consent-accept>Accept all</button>
            <button type="button" class="at-consent__btn at-consent__btn--secondary at-focusable" data-consent-reject>Reject optional</button>
            <button type="button" class="at-consent__btn at-consent__btn--link at-focusable" data-consent-manage>Manage preferences</button>
        </div>
    </div>
</div>

<div id="at-consent-modal" class="at-consent-modal" hidden>
    <div class="at-consent-modal__backdrop" data-consent-close></div>
    <div class="at-consent-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="at-consent-modal-title">
        <div class="at-consent-modal__head">
            <h2 class="at-consent-modal__title" id="at-consent-modal-title">Cookie preferences</h2>
            <button type="button" class="at-consent-modal__close at-focusable" data-consent-close aria-label="Close cookie preferences">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <p class="at-consent-modal__intro">Choose which optional cookies we may use. Essential cookies keep the shop working and cannot be turned off. See our <a class="at-consent__link at-focusable" href="<?= Security::escape($consentPrivacyUrl) ?>">Privacy Policy</a>.</p>

        <div class="at-consent-cat">
            <div class="at-consent-cat__info">
                <span class="at-consent-cat__name">Essential cookies</span>
                <span class="at-consent-cat__desc">Basket, sign-in, checkout and security. Always on — the shop does not work without them.</span>
            </div>
            <span class="at-consent-cat__locked">Always on</span>
        </div>

        <div class="at-consent-cat">
            <div class="at-consent-cat__info">
                <label class="at-consent-cat__name" for="at-consent-analytics">Analytics cookies</label>
                <span class="at-consent-cat__desc">Anonymous usage statistics that help us understand which pages and products are useful.</span>
            </div>
            <label class="at-switch">
                <input type="checkbox" id="at-consent-analytics" data-consent-pref="analytics">
                <span class="at-switch__track" aria-hidden="true"></span>
                <span class="sr-only">Allow analytics cookies</span>
            </label>
        </div>

        <div class="at-consent-cat">
            <div class="at-consent-cat__info">
                <label class="at-consent-cat__name" for="at-consent-marketing">Marketing cookies</label>
                <span class="at-consent-cat__desc">Advertising measurement, including the Meta Pixel, so we can see which ads bring shoppers here.</span>
            </div>
            <label class="at-switch">
                <input type="checkbox" id="at-consent-marketing" data-consent-pref="marketing">
                <span class="at-switch__track" aria-hidden="true"></span>
                <span class="sr-only">Allow marketing cookies</span>
            </label>
        </div>

        <div class="at-consent-modal__actions">
            <button type="button" class="at-consent__btn at-consent__btn--secondary at-focusable" data-consent-reject>Reject optional</button>
            <button type="button" class="at-consent__btn at-consent__btn--primary at-focusable" data-consent-save>Save my preferences</button>
            <button type="button" class="at-consent__btn at-consent__btn--primary at-focusable" data-consent-accept>Accept all</button>
        </div>
    </div>
</div>
