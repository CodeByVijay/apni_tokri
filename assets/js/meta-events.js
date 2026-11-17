/*
 * Veggie Tokri — Meta Pixel event dispatcher (Phase 2).
 *
 * Loads fbevents.js ONLY when:
 *   1. server config says the pixel is configured (enabled + valid ID + env), and
 *   2. ATConsent.marketingAllowed() is true right now.
 * Any event call while those conditions fail is a silent no-op — nothing is
 * queued to Meta, no network request is made.
 * Purchase events carry a deterministic eventID (server-generated) and are
 * additionally guarded by localStorage so revisiting order.php cannot refire.
 */
(function () {
    'use strict';

    var cfg = window.__AT_META__ || {};
    var inited = false;
    var loading = false;
    var revoked = false;
    var pageViewFired = false;
    var queue = [];

    function marketingAllowed() {
        return !!(window.ATConsent && window.ATConsent.marketingAllowed());
    }

    function ready() {
        return !revoked && !!cfg.configured && !!cfg.pixelId && marketingAllowed();
    }

    function loadFbevents() {
        if (inited || loading) return;
        loading = true;
        // Standard Meta snippet — stub fbq then async-load fbevents.js.
        var n = window.fbq = window.fbq || function () {
            n.queue = n.queue || [];
            n.queue.push(arguments);
        };
        n._queue = n._queue || [];
        n.queue = n.queue || [];
        n.loaded = true;
        n.version = '2.0';
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://connect.facebook.net/en_US/fbevents.js';
        var f = document.getElementsByTagName('script')[0];
        f.parentNode.insertBefore(s, f);
    }

    function pixelInit() {
        loadFbevents();
        if (!inited) {
            inited = true;
            window.fbq('init', String(cfg.pixelId));
            if (!pageViewFired) {
                pageViewFired = true;
                window.fbq('track', 'PageView');
            }
        }
    }

    function fire(name, params, options) {
        if (!ready()) return;
        pixelInit();
        if (options && options.eventID) {
            window.fbq('track', name, params || {}, { eventID: String(options.eventID) });
        } else {
            window.fbq('track', name, params || {});
        }
    }

    function purchaseGuard(eventId) {
        var key = 'at_meta_evt_' + eventId;
        try {
            if (window.localStorage && localStorage.getItem(key)) {
                return false;
            }
            localStorage.setItem(key, String(Date.now()));
            return true;
        } catch (e) {
            return true; // Storage unavailable — Meta still dedups by eventID.
        }
    }

    function dispatch(event) {
        if (!event || !event.name) return;
        if (event.name === 'Purchase' && event.options && event.options.eventID
            && !purchaseGuard(String(event.options.eventID))) {
            return;
        }
        fire(event.name, event.params, event.options);
    }

    function flush() {
        var events = Array.isArray(cfg.events) ? cfg.events.slice() : [];
        cfg.events = [];
        events.concat(queue).forEach(dispatch);
        queue = [];
    }

    function init() {
        if (!ready()) return;
        pixelInit(); // performs init + PageView (each at most once)
        flush();
    }

    // Public API.
    window.ATMeta = {
        track: function (name, params, options) { dispatch({ name: name, params: params, options: options }); },
        ready: ready
    };

    document.addEventListener('at:consent:change', function (e) {
        var granted = !!(e && e.detail && e.detail.marketing);
        if (granted) {
            revoked = false;
            init(); // first opt-in mid-visit → init + PageView + pending events
        } else {
            revoked = true;
            queue = [];
            cfg.events = [];
            // Meta consent API: stop the pixel from recording further events.
            if (inited && typeof window.fbq === 'function') {
                try { window.fbq('consent', 'revoke'); } catch (err) {}
            }
        }
    });

    // Cart add-to-cart success dispatches this DOM event (assets/js/cart.js).
    document.addEventListener('at:meta:event', function (e) {
        var d = e && e.detail;
        if (d && d.name) dispatch(d);
    });

    // Consent was already granted on a previous visit → init immediately.
    if (ready()) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }
})();
