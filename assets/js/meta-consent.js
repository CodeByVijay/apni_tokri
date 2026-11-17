/*
 * Veggie Tokri — first-party cookie consent manager.
 *
 * Reads/writes the `at_consent` cookie (base64url JSON: {v, t, a, m}).
 * PHP (MetaConsent) validates the same value server-side — only a structurally
 * valid, current-version, unexpired record counts as consent.
 * Exposes window.ATConsent and fires `at:consent:change` (CustomEvent,
 * detail = {analytics, marketing}) whenever a decision is saved.
 */
(function () {
    'use strict';

    var cfg = (window.__AT_META__ && window.__AT_META__.consent) || {};
    var COOKIE = cfg.cookie || 'at_consent';
    var VERSION = cfg.version || 1;
    var TTL = cfg.ttl || 15552000; // 180 days

    function b64urlEncode(str) {
        return btoa(unescape(encodeURIComponent(str)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function b64urlDecode(str) {
        try {
            return decodeURIComponent(escape(atob(str.replace(/-/g, '+').replace(/_/g, '/'))));
        } catch (e) {
            return null;
        }
    }

    function readCookie() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
        if (!match) return null;
        var json = b64urlDecode(match[1]);
        if (!json) return null;
        try {
            var d = JSON.parse(json);
            if (d && d.v === VERSION && typeof d.t === 'number'
                && typeof d.a === 'boolean' && typeof d.m === 'boolean'
                && (Date.now() / 1000 - d.t) >= 0 && (Date.now() / 1000 - d.t) <= TTL) {
                return { analytics: d.a, marketing: d.m };
            }
        } catch (e) {}
        return null;
    }

    function writeCookie(state) {
        var payload = { v: VERSION, t: Math.floor(Date.now() / 1000), a: !!state.analytics, m: !!state.marketing };
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = COOKIE + '=' + b64urlEncode(JSON.stringify(payload))
            + '; Max-Age=' + TTL + '; Path=/; SameSite=Lax' + secure;
    }

    var current = readCookie();
    var bannerEl = document.getElementById('at-consent');
    var modalEl = document.getElementById('at-consent-modal');
    var lastFocus = null;

    function syncSwitches() {
        var a = document.getElementById('at-consent-analytics');
        var m = document.getElementById('at-consent-marketing');
        if (a) a.checked = !!(current && current.analytics);
        if (m) m.checked = !!(current && current.marketing);
    }

    function show(el) {
        if (!el) return;
        el.hidden = false;
        requestAnimationFrame(function () { el.classList.add('is-visible'); });
    }

    function hide(el) {
        if (!el) return;
        el.classList.remove('is-visible');
        el.hidden = true;
    }

    function openModal() {
        if (!modalEl) return;
        syncSwitches();
        lastFocus = document.activeElement;
        if (bannerEl) hide(bannerEl);
        show(modalEl);
        var first = modalEl.querySelector('#at-consent-analytics');
        if (first) first.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        if (!modalEl) return;
        hide(modalEl);
        document.body.style.overflow = '';
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
            lastFocus = null;
        }
        // Re-show the banner if the visitor closed the dialog without deciding.
        if (!current && bannerEl) show(bannerEl);
    }

    function save(state) {
        current = { analytics: !!state.analytics, marketing: !!state.marketing };
        writeCookie(current);
        hide(bannerEl);
        if (modalEl && !modalEl.hidden) closeModal();
        document.dispatchEvent(new CustomEvent('at:consent:change', { detail: current }));
    }

    function choicesFromModal() {
        var a = document.getElementById('at-consent-analytics');
        var m = document.getElementById('at-consent-marketing');
        return { analytics: !!(a && a.checked), marketing: !!(m && m.checked) };
    }

    function onClick(e) {
        var el = e.target.closest ? e.target.closest('[data-consent-accept],[data-consent-reject],[data-consent-save],[data-consent-manage],[data-consent-close]') : null;
        if (!el) return;
        if (el.hasAttribute('data-consent-accept')) {
            save({ analytics: true, marketing: true });
        } else if (el.hasAttribute('data-consent-reject')) {
            save({ analytics: false, marketing: false });
        } else if (el.hasAttribute('data-consent-save')) {
            save(choicesFromModal());
        } else if (el.hasAttribute('data-consent-manage')) {
            openModal();
        } else if (el.hasAttribute('data-consent-close')) {
            closeModal();
        }
    }

    function onKeydown(e) {
        if (e.key === 'Escape' && modalEl && !modalEl.hidden) {
            closeModal();
            return;
        }
        // Minimal focus trap inside the modal.
        if (e.key === 'Tab' && modalEl && !modalEl.hidden) {
            var focusables = modalEl.querySelectorAll('button, input, a[href], [tabindex]:not([tabindex="-1"])');
            if (!focusables.length) return;
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    document.addEventListener('click', onClick);
    document.addEventListener('keydown', onKeydown);

    // Public API — footer "Cookie Preferences" link calls ATConsent.open().
    window.ATConsent = {
        open: openModal,
        state: function () { return current ? { analytics: current.analytics, marketing: current.marketing } : null; },
        marketingAllowed: function () { return !!(current && current.marketing); },
        analyticsAllowed: function () { return !!(current && current.analytics); }
    };

    // First visit (or expired/invalid record): show the banner.
    if (!current && bannerEl) {
        show(bannerEl);
    }
})();
