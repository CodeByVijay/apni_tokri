/**
 * Subscription Menu Planner wizard (Admin -> Subscriptions -> Menu Planner).
 * Depends on MenuPlannerOps (menu-planner-ops.js). Config is read from #menu-planner-root[data-config].
 */
(function () {
    'use strict';

    var root = document.getElementById('menu-planner-root');
    if (!root || !window.MenuPlannerOps) { return; }
    var Ops = window.MenuPlannerOps;
    var cfg = {};
    try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }

    var STEPS = ['Choose Your Plan', 'Build Daily Menus', 'Review Your Menu', 'Save Plan'];
    var STATUS_LABEL = { not_configured: 'Not configured', partial: 'Partially configured', ready: 'Ready' };

    var init = cfg.initial || null;
    var state = Ops.newState(init ? init.duration_days : 7);
    var plan = {
        id: init ? init.id : null,
        name: init ? init.name : '',
        preset: init ? presetFor(init.duration_days) : '7',
        subscription_plan_id: init && init.subscription_plan_id ? String(init.subscription_plan_id) : '',
        repeat_mode: init ? init.repeat_mode : 'none',
        repeat_every: init && init.repeat_every ? String(init.repeat_every) : '',
        is_active: init ? !!init.is_active : false,
        allow_incomplete: false,
        refresh_prices: false
    };
    if (init) {
        state.duration = init.duration_days;
        state.days = {};
        for (var d = 1; d <= init.duration_days; d++) {
            var src = (init.days && init.days[d]) || { customized: false, items: [] };
            state.days[d] = { customized: !!src.customized, items: Ops.cloneItems(src.items) };
        }
    }
    if (!init) {
        var defPlan = planForCount(7);
        if (defPlan) { plan.subscription_plan_id = String(defPlan.id); }
        plan.name = '7-Day Vegetable Menu';
    }
    var step = cfg.step || 1;
    var expanded = {};
    var errors = cfg.errors || [];
    var searchTimers = {};

    function presetFor(n) { return (n === 7 || n === 15 || n === 30) ? String(n) : 'custom'; }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(n) { return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
    function qtyFmt(n) { return String(Math.round(Number(n) * 1000) / 1000); }
    function $(sel, ctx) { return (ctx || root).querySelector(sel); }
    function $all(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
    function planForCount(n) {
        var list = cfg.plans || [];
        var byKey = null, byCount = null;
        list.forEach(function (p) {
            if (p.template_key === 'veg_' + n) { byKey = p; }
            if (!byCount && Number(p.delivery_count) === n) { byCount = p; }
        });
        return byKey || byCount;
    }
    function selectedPlan() {
        var id = Number(plan.subscription_plan_id);
        var found = null;
        (cfg.plans || []).forEach(function (p) { if (Number(p.id) === id) { found = p; } });
        return found;
    }
    function announce(msg) { var el = $('#mp-announce'); if (el) { el.textContent = msg; } }

    /* ---------- dialogs ---------- */
    function confirmDialog(title, bodyHtml, okLabel, danger) {
        return new Promise(function (resolve) {
            var wrap = document.createElement('div');
            wrap.className = 'mp-modal';
            wrap.innerHTML = '<div class="mp-modal__box" role="dialog" aria-modal="true" aria-labelledby="mp-dlg-title">' +
                '<h3 id="mp-dlg-title" class="mp-modal__title">' + esc(title) + '</h3>' +
                '<div class="mp-modal__body">' + bodyHtml + '</div>' +
                '<div class="mp-modal__actions"><button type="button" class="at-btn at-btn--secondary at-focusable" data-r="0">Cancel</button>' +
                '<button type="button" class="at-btn ' + (danger ? 'at-btn--danger' : 'at-btn--primary') + ' at-focusable" data-r="1">' + esc(okLabel || 'Confirm') + '</button></div></div>';
            document.body.appendChild(wrap);
            var prev = document.activeElement;
            function close(v) { document.removeEventListener('keydown', onKey); wrap.remove(); if (prev && prev.focus) { prev.focus(); } resolve(v); }
            function onKey(e) { if (e.key === 'Escape') { close(false); } }
            document.addEventListener('keydown', onKey);
            wrap.addEventListener('click', function (e) {
                var r = e.target.getAttribute && e.target.getAttribute('data-r');
                if (r === '1') { close(true); } else if (r === '0' || e.target === wrap) { close(false); }
            });
            wrap.querySelector('[data-r="1"]').focus();
        });
    }

    function pickDaysDialog(from) {
        return new Promise(function (resolve) {
            var boxes = '';
            for (var d = 1; d <= state.duration; d++) {
                if (d === from) { continue; }
                var has = state.days[d].items.length > 0;
                boxes += '<label class="mp-pick"><input type="checkbox" value="' + d + '"> Day ' + d + (has ? ' <span class="mp-pick__has" title="Already has vegetables">●</span>' : '') + '</label>';
            }
            var wrap = document.createElement('div');
            wrap.className = 'mp-modal';
            wrap.innerHTML = '<div class="mp-modal__box" role="dialog" aria-modal="true" aria-labelledby="mp-pick-title">' +
                '<h3 id="mp-pick-title" class="mp-modal__title">Copy Day ' + from + ' to selected days</h3>' +
                '<div class="mp-modal__body"><p class="at-muted text-sm mb-2">Select the days that should get the same vegetables. ● marks days that already have vegetables.</p>' +
                '<div class="mp-pick-actions"><button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-all="1">Select all</button> <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-all="0">Clear</button></div>' +
                '<div class="mp-pick-grid">' + boxes + '</div><p class="mp-pick-err text-sm" style="color:#b91c1c" role="alert"></p></div>' +
                '<div class="mp-modal__actions"><button type="button" class="at-btn at-btn--secondary at-focusable" data-r="0">Cancel</button>' +
                '<button type="button" class="at-btn at-btn--primary at-focusable" data-r="1">Copy</button></div></div>';
            document.body.appendChild(wrap);
            var prev = document.activeElement;
            function close(v) { document.removeEventListener('keydown', onKey); wrap.remove(); if (prev && prev.focus) { prev.focus(); } resolve(v); }
            function onKey(e) { if (e.key === 'Escape') { close(null); } }
            document.addEventListener('keydown', onKey);
            wrap.addEventListener('click', function (e) {
                var t = e.target;
                if (t.getAttribute && t.getAttribute('data-all') !== null) {
                    var on = t.getAttribute('data-all') === '1';
                    $all('input[type=checkbox]', wrap).forEach(function (c) { c.checked = on; });
                } else if (t.getAttribute && t.getAttribute('data-r') === '1') {
                    var sel = $all('input[type=checkbox]:checked', wrap).map(function (c) { return Number(c.value); });
                    if (!sel.length) { wrap.querySelector('.mp-pick-err').textContent = 'Select at least one day.'; return; }
                    close(sel);
                } else if ((t.getAttribute && t.getAttribute('data-r') === '0') || t === wrap) { close(null); }
            });
        });
    }

    /* ---------- rendering ---------- */
    function render() {
        var html = '<div id="mp-announce" class="sr-only" aria-live="polite"></div>' + progressHtml();
        if (errors.length) {
            html += '<div class="at-alert at-alert--error mb-4" role="alert"><div><strong>Please fix the following:</strong><ul class="mp-errors">' +
                errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div></div>';
        }
        html += '<div id="mp-step">' + (step === 1 ? step1Html() : step === 2 ? step2Html() : step === 3 ? step3Html() : step4Html()) + '</div>';
        root.innerHTML = html;
        if (step === 2) { $all('.mp-day', root).forEach(function (el) { refreshDayBody(Number(el.getAttribute('data-day'))); }); }
        errors = [];
        if (step === 1) { checkPlanMismatch(); }
    }

    function progressHtml() {
        var st = Ops.stats(state);
        var out = '<nav aria-label="Progress" class="mp-progress"><ol class="mp-steps">';
        STEPS.forEach(function (label, i) {
            var n = i + 1;
            var cls = n < step ? 'is-done' : (n === step ? 'is-current' : '');
            out += '<li class="mp-step ' + cls + '"' + (n === step ? ' aria-current="step"' : '') + '><span class="mp-step__num">' + (n < step ? '✓' : n) + '</span><span class="mp-step__label">' + esc(label) + '</span></li>';
        });
        out += '</ol><p class="mp-progress__text text-sm at-muted">Step ' + step + ' of 4' +
            (step >= 2 ? ' · <strong id="mp-conf-text">' + st.configured + ' of ' + state.duration + ' days configured</strong>' : '') + '</p>';
        if (step >= 2) {
            out += '<div class="mp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' + state.duration + '" aria-valuenow="' + st.configured + '"><span id="mp-bar-fill" style="width:' + (state.duration ? Math.round(st.configured * 100 / state.duration) : 0) + '%"></span></div>';
        }
        return out + '</nav>';
    }

    function refreshProgress() {
        var st = Ops.stats(state);
        var t = $('#mp-conf-text'); if (t) { t.textContent = st.configured + ' of ' + state.duration + ' days configured'; }
        var f = $('#mp-bar-fill'); if (f) { f.style.width = (state.duration ? Math.round(st.configured * 100 / state.duration) : 0) + '%'; f.parentNode.setAttribute('aria-valuenow', st.configured); }
    }

    function step1Html() {
        var cards = [['7', '7-Day Plan', 'One week of vegetable baskets'], ['15', '15-Day Plan', 'Two weeks of fresh deliveries'], ['30', '30-Day Plan', 'A full month of baskets'], ['custom', 'Custom Duration', 'Any duration from 1 to ' + Ops.MAX_DAYS + ' days']];
        var h = '<section class="at-card"><div class="at-card__body">' +
            '<h3 class="mp-h">1. Choose Your Plan</h3><p class="at-muted text-sm mb-3">Pick how long this menu runs. You can still edit every day afterwards.</p>' +
            '<div class="mp-cards" role="radiogroup" aria-label="Plan duration">';
        cards.forEach(function (c) {
            var on = plan.preset === c[0];
            h += '<button type="button" role="radio" aria-checked="' + on + '" class="mp-card at-focusable' + (on ? ' is-selected' : '') + '" data-preset="' + c[0] + '"><span class="mp-card__title">' + esc(c[1]) + '</span><span class="mp-card__sub">' + esc(c[2]) + '</span></button>';
        });
        h += '</div>';
        h += '<div class="mp-grid mt-4">' +
            '<div><label class="at-label" for="mp-name">Plan name <span aria-hidden="true">*</span></label><input id="mp-name" class="at-input at-focusable w-full" maxlength="191" value="' + esc(plan.name) + '" placeholder="e.g. Monsoon Vegetable Menu" aria-describedby="mp-name-err"><p id="mp-name-err" class="mp-err" role="alert"></p></div>';
        if (plan.preset === 'custom') {
            h += '<div><label class="at-label" for="mp-duration">Number of days <span aria-hidden="true">*</span></label><input id="mp-duration" type="number" min="1" max="' + Ops.MAX_DAYS + '" step="1" class="at-input at-focusable w-full" value="' + esc(state.duration) + '" aria-describedby="mp-dur-err"><p id="mp-dur-err" class="mp-err" role="alert"></p></div>';
        } else {
            h += '<div><span class="at-label">Duration</span><p class="mp-static">' + state.duration + ' days</p></div>';
        }
        h += '<div><label class="at-label" for="mp-subplan">Use this menu for subscription plan</label><select id="mp-subplan" class="at-input at-focusable w-full"><option value="">Not linked yet (save as a draft)</option>';
        (cfg.plans || []).forEach(function (p) {
            h += '<option value="' + p.id + '"' + (String(p.id) === plan.subscription_plan_id ? ' selected' : '') + '>' + esc(p.name) + '</option>';
        });
        h += '</select><p id="mp-sub-warn" class="mp-warn" role="status"></p></div></div>';

        h += '<fieldset class="mp-fieldset mt-4"><legend class="at-label">Menu schedule</legend>' +
            radio('mp-rep', 'none', 'Different menu every day', 'Recommended. Each day gets its own vegetables.') +
            radio('mp-rep', 'every_n', 'Repeat a menu after N days', 'Build the first N days, then repeat them for the rest of the plan.') +
            '<div class="mp-sub" ' + (plan.repeat_mode === 'every_n' ? '' : 'hidden') + '><label class="at-label" for="mp-every">Repeat after N days</label><input id="mp-every" type="number" min="1" step="1" class="at-input at-focusable" style="max-width:8rem" value="' + esc(plan.repeat_every) + '"><p id="mp-every-err" class="mp-err" role="alert"></p></div>' +
            radio('mp-rep', 'weekly', 'Repeat the same weekly menu', 'Build 7 days, then repeat them every week.') +
            '</fieldset>';
        h += '<p class="at-muted text-sm mt-3">Repeating is optional. Repeated days become normal editable days, and nothing is overwritten without asking you first.</p>';
        h += '</div><div class="at-card__footer mp-nav"><a class="at-btn at-btn--secondary at-focusable" href="' + esc(cfg.listUrl) + '">Cancel</a><button type="button" class="at-btn at-btn--primary at-focusable" id="mp-next">Next: Build Daily Menus →</button></div></section>';

        return h;
    }
    function radio(name, val, title, desc) {
        return '<label class="mp-radio"><input type="radio" name="' + name + '" value="' + val + '"' + (plan.repeat_mode === val ? ' checked' : '') + '><span><strong>' + esc(title) + '</strong><br><span class="at-muted text-sm">' + esc(desc) + '</span></span></label>';
    }

    function step2Html() {
        var h = '<section class="at-card"><div class="at-card__body"><h3 class="mp-h">2. Build Daily Menus</h3>' +
            '<p class="at-muted text-sm mb-3">Open a day to add vegetables. Prices come from the live catalogue.</p>';
        if (plan.repeat_mode !== 'none') {
            var n = plan.repeat_mode === 'weekly' ? 7 : Number(plan.repeat_every);
            h += '<div class="at-alert at-alert--info mb-3" role="note"><div><strong>' + (plan.repeat_mode === 'weekly' ? 'Weekly repeat' : 'Repeat after ' + n + ' days') + '.</strong> Build Day 1' + (n > 1 ? '–' + n : '') + ', then fill the repeated days. ' +
                '<button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" id="mp-apply-repeat">Fill repeated days</button></div></div>';
        }
        h += '<div class="mp-toolbar"><button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" id="mp-expand-all">Expand all</button> <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" id="mp-collapse-all">Collapse all</button></div>';
        h += '<div id="mp-days" class="mp-days">';
        for (var d = 1; d <= state.duration; d++) { h += dayShell(d); }
        h += '</div></div><div class="at-card__footer mp-nav"><button type="button" class="at-btn at-btn--secondary at-focusable" id="mp-back">← Back</button><button type="button" class="at-btn at-btn--primary at-focusable" id="mp-next">Next: Review Your Menu →</button></div></section>';

        return h;
    }

    function dayShell(d) {
        var open = !!expanded[d];
        return '<article class="mp-day" data-day="' + d + '"><header class="mp-day__head" id="mp-dh-' + d + '"></header><div class="mp-day__body" id="mp-db-' + d + '"' + (open ? '' : ' hidden') + '></div></article>';
    }

    function headerHtml(d) {
        var items = state.days[d].items;
        var status = Ops.dayStatus(items);
        var open = !!expanded[d];
        return '<button type="button" class="mp-day__toggle at-focusable" data-act="toggle" aria-expanded="' + open + '" aria-controls="mp-db-' + d + '"><span class="mp-chev" aria-hidden="true">' + (open ? '▾' : '▸') + '</span><strong>Day ' + d + '</strong></button>' +
            '<span class="mp-badge mp-badge--' + status + '">' + STATUS_LABEL[status] + '</span>' +
            '<span class="mp-day__meta">' + items.length + ' vegetable' + (items.length === 1 ? '' : 's') + (items.length ? ' · ' + money(Ops.dayValue(items)) : '') + '</span>' +
            '<span class="mp-day__actions">' +
            '<button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="toggle">' + (open ? 'Collapse' : 'Edit') + '</button>' +
            (d > 1 ? '<button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="prev" title="Copy vegetables from Day ' + (d - 1) + '">Copy previous day</button>' : '') +
            '<button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="dup" title="Duplicate into Day ' + (d + 1) + '"' + (d >= state.duration ? ' disabled' : '') + '>Duplicate</button>' +
            '<button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="copyto"' + (items.length ? '' : ' disabled') + '>Copy to days…</button>' +
            '<button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="clear"' + (items.length ? '' : ' disabled') + '>Clear day</button></span>';
    }

    function refreshDayHeader(d) {
        var el = $('#mp-dh-' + d); if (el) { el.innerHTML = headerHtml(d); }
        refreshProgress();
    }

    function refreshDayBody(d) {
        var head = $('#mp-dh-' + d); if (head) { head.innerHTML = headerHtml(d); }
        var body = $('#mp-db-' + d);
        if (!body) { return; }
        if (!expanded[d]) { body.hidden = true; body.innerHTML = ''; return; }
        body.hidden = false;
        var items = state.days[d].items;
        var h = '';
        if (!items.length) {
            h += '<p class="mp-empty">No vegetables yet. Search below to add your first one.</p>';
        } else {
            h += '<div class="mp-table-wrap"><table class="mp-table"><thead><tr><th></th><th>Vegetable</th><th>Stock</th><th>Price</th><th>Quantity</th><th>Unit</th><th>Max qty</th><th><span class="sr-only">Remove</span></th></tr></thead><tbody>';
            items.forEach(function (it, idx) {
                var errs = Ops.validateLine(it);
                h += '<tr data-idx="' + idx + '" class="' + (errs.length ? 'has-error' : '') + '">' +
                    '<td>' + imgHtml(it) + '</td>' +
                    '<td><strong>' + esc(it.product_name) + '</strong><br><span class="at-muted text-sm">' + esc(it.variant_name) + '</span>' +
                    (it.available === false ? '<br><span class="mp-flag">Inactive / unavailable</span>' : '') +
                    (it.available !== false && it.in_stock === false ? '<br><span class="mp-flag mp-flag--soft">Out of stock now</span>' : '') +
                    (it.current_price !== undefined && Math.abs(Number(it.current_price) - Number(it.price)) > 0.004 ? '<br><span class="mp-flag mp-flag--soft">Catalogue price now ' + money(it.current_price) + '</span>' : '') + '</td>' +
                    '<td>' + (it.stock_qty === undefined ? '' : esc(it.stock_qty)) + '</td>' +
                    '<td>' + money(it.price) + '</td>' +
                    '<td><label class="sr-only" for="mp-q-' + d + '-' + idx + '">Quantity</label><input id="mp-q-' + d + '-' + idx + '" data-f="qty" type="number" min="0" step="any" class="at-input at-focusable mp-num" value="' + esc(qtyFmt(it.qty)) + '"></td>' +
                    '<td>' + esc(it.unit_label || '') + '</td>' +
                    '<td><label class="sr-only" for="mp-m-' + d + '-' + idx + '">Max quantity</label><input id="mp-m-' + d + '-' + idx + '" data-f="max" type="number" min="0" step="any" class="at-input at-focusable mp-num" value="' + (it.max_qty === null || it.max_qty === undefined ? '' : esc(qtyFmt(it.max_qty))) + '" placeholder="optional"></td>' +
                    '<td><button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-act="remove" aria-label="Remove ' + esc(it.product_name) + '">✕</button></td></tr>' +
                    (errs.length ? '<tr class="mp-line-err"><td></td><td colspan="7" role="alert">' + errs.map(esc).join(' ') + '</td></tr>' : '');
            });
            h += '</tbody></table></div><p class="at-muted text-sm mt-1">Quantity is the number of packs of the chosen variant. Stock is re-checked when each customer basket is generated.</p>';
        }
        h += '<div class="mp-search"><label class="at-label" for="mp-s-' + d + '">Add vegetable</label>' +
            '<input id="mp-s-' + d + '" type="search" autocomplete="off" class="at-input at-focusable w-full" placeholder="Search by product name, SKU or variant" data-act="search">' +
            '<div class="mp-results" id="mp-r-' + d + '" aria-live="polite"></div></div>';
        body.innerHTML = h;
    }

    function imgHtml(it) {
        return it.image_url ? '<img class="mp-thumb" src="' + esc(it.image_url) + '" alt="" loading="lazy">' : '<span class="mp-thumb mp-thumb--ph" aria-hidden="true">🥬</span>';
    }

    function step3Html() {
        var st = Ops.stats(state);
        var p = selectedPlan();
        var h = '<section class="at-card"><div class="at-card__body"><h3 class="mp-h">3. Review Your Menu</h3>' +
            '<dl class="mp-facts"><div><dt>Plan name</dt><dd>' + esc(plan.name) + '</dd></div><div><dt>Duration</dt><dd>' + state.duration + ' days</dd></div>' +
            '<div><dt>Repeat setting</dt><dd>' + repeatText() + '</dd></div><div><dt>Subscription plan</dt><dd>' + (p ? esc(p.name) : 'Not linked yet') + '</dd></div>' +
            '<div><dt>Configured days</dt><dd>' + st.configured + ' of ' + state.duration + '</dd></div><div><dt>Empty days</dt><dd>' + st.incomplete.length + '</dd></div>' +
            '<div><dt>Total vegetable entries</dt><dd>' + st.entries + '</dd></div></dl>';
        if (st.incomplete.length) {
            h += '<div class="at-alert at-alert--warning mb-3" role="alert"><div><strong>This plan is incomplete.</strong> ' + st.incomplete.length + ' day(s) have no vegetables: ' + esc(daysText(st.incomplete)) + '. Customers get no suggested basket on those days.</div></div>';
        }
        var warns = [];
        for (var d = 1; d <= state.duration; d++) {
            state.days[d].items.forEach(function (it) {
                var e = Ops.validateLine(it); if (e.length) { warns.push('Day ' + d + ': ' + it.product_name + ' (' + it.variant_name + ') – ' + e.join(' ')); }
            });
        }
        if (warns.length) {
            h += '<div class="at-alert at-alert--warning mb-3" role="alert"><div><strong>Needs attention</strong><ul class="mp-errors">' + warns.slice(0, 12).map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + (warns.length > 12 ? '<li>…and ' + (warns.length - 12) + ' more</li>' : '') + '</ul></div></div>';
        }
        h += '<div class="mp-review">';
        for (var i = 1; i <= state.duration; i++) {
            var items = state.days[i].items;
            var status = Ops.dayStatus(items);
            h += '<div class="mp-rday"><button type="button" class="mp-rday__head at-focusable" data-edit-day="' + i + '"><strong>Day ' + i + '</strong><span class="mp-badge mp-badge--' + status + '">' + STATUS_LABEL[status] + '</span><span class="at-muted text-sm">' + (items.length ? items.length + ' vegetables · suggested basket ' + money(Ops.dayValue(items)) : 'No vegetables') + '</span><span class="mp-rday__edit">Edit day</span></button>';
            if (items.length) {
                h += '<ul class="mp-rlist">' + items.map(function (it) { return '<li>' + esc(it.product_name) + ' <span class="at-muted">(' + esc(it.variant_name) + ')</span> × ' + esc(qtyFmt(it.qty)) + ' · ' + money(it.price) + (it.max_qty !== null && it.max_qty !== undefined ? ' · max ' + esc(qtyFmt(it.max_qty)) : '') + '</li>'; }).join('') + '</ul>';
            }
            h += '</div>';
        }
        h += '</div></div><div class="at-card__footer mp-nav"><button type="button" class="at-btn at-btn--secondary at-focusable" id="mp-back">← Back to Edit Menus</button><button type="button" class="at-btn at-btn--primary at-focusable" id="mp-next">Save Menu Plan →</button></div></section>';

        return h;
    }

    function repeatText() {
        if (plan.repeat_mode === 'every_n') { return 'Repeat after ' + esc(plan.repeat_every) + ' days'; }
        if (plan.repeat_mode === 'weekly') { return 'Same weekly menu'; }
        return 'Different menu every day';
    }
    function daysText(list) {
        return list.length > 10 ? 'Day ' + list.slice(0, 10).join(', ') + ' and ' + (list.length - 10) + ' more' : 'Day ' + list.join(', ');
    }

    function step4Html() {
        var st = Ops.stats(state);
        var p = selectedPlan();
        var h = '<section class="at-card"><form id="mp-form" method="post" action="' + esc(cfg.saveUrl) + '"><div class="at-card__body"><h3 class="mp-h">4. Save Plan</h3>' +
            '<input type="hidden" name="' + esc(cfg.csrfName) + '" value="' + esc(cfg.csrf) + '"><input type="hidden" name="submit_token" value="' + esc(cfg.submitToken || '') + '"><input type="hidden" name="payload" id="mp-payload">' +
            '<p class="mb-3"><strong>' + esc(plan.name) + '</strong> · ' + state.duration + ' days · ' + st.configured + ' of ' + state.duration + ' days configured · ' + st.entries + ' vegetable entries.</p>';
        h += '<label class="mp-radio"><input type="checkbox" id="mp-live"' + (plan.is_active ? ' checked' : '') + (p ? '' : ' disabled') + '><span><strong>Make this menu live for customers</strong><br><span class="at-muted text-sm">' +
            (p ? 'Used for new ' + esc(p.name) + ' subscriptions started after saving. Existing customer subscriptions and their saved baskets stay exactly as they are.' : 'Go back to step 1 and choose a subscription plan to enable this. Otherwise the menu is saved as a draft.') + '</span></span></label>';
        if (st.incomplete.length) {
            h += '<label class="mp-radio mp-radio--warn"><input type="checkbox" id="mp-allow"' + (plan.allow_incomplete ? ' checked' : '') + '><span><strong>I understand this plan is incomplete</strong><br><span class="at-muted text-sm">' + st.incomplete.length + ' day(s) have no vegetables (' + esc(daysText(st.incomplete)) + '). Needed only to make an incomplete plan live.</span></span></label>';
        }
        if (plan.id) {
            h += '<label class="mp-radio"><input type="checkbox" id="mp-refresh"' + (plan.refresh_prices ? ' checked' : '') + '><span><strong>Refresh saved prices to today\'s catalogue prices</strong><br><span class="at-muted text-sm">Off by default: existing lines keep the price saved earlier. New lines always use the current price.</span></span></label>';
        }
        h += '<p class="at-muted text-sm mt-2">Menu prices are a planning reference. Subscription payment and checkout totals are calculated separately and are not changed by this planner.</p>' +
            '</div><div class="at-card__footer mp-nav"><button type="button" class="at-btn at-btn--secondary at-focusable" id="mp-back">← Back to Review</button><button type="submit" class="at-btn at-btn--primary at-focusable" id="mp-save">Save Menu Plan</button></div></form></section>';

        return h;
    }

    /* ---------- actions ---------- */
    function collectStep1() {
        var nm = $('#mp-name'); if (nm) { plan.name = nm.value.trim(); }
        var sp = $('#mp-subplan'); if (sp) { plan.subscription_plan_id = sp.value; }
        var rep = $('input[name="mp-rep"]:checked'); if (rep) { plan.repeat_mode = rep.value; }
        var ev = $('#mp-every'); if (ev) { plan.repeat_every = ev.value.trim(); }
    }

    function validateStep1() {
        collectStep1();
        var ok = true;
        function err(id, msg) { var e = $('#' + id); if (e) { e.textContent = msg; } ok = false; }
        if (!plan.name) { err('mp-name-err', 'Enter a plan name.'); }
        if (plan.preset === 'custom') {
            var dur = $('#mp-duration');
            var v = Ops.validateDuration(dur ? dur.value : state.duration);
            if (!v.ok) { err('mp-dur-err', v.message); } else if (v.value !== state.duration) { return applyDuration(v.value).then(function (done) { return done && validateStep1Rest(); }); }
        }
        if (!ok) { return Promise.resolve(false); }
        return Promise.resolve(validateStep1Rest());
    }
    function validateStep1Rest() {
        if (plan.repeat_mode === 'every_n') {
            var e = Number(plan.repeat_every);
            if (!(e >= 1) || e !== Math.floor(e) || e >= state.duration) {
                var el = $('#mp-every-err'); if (el) { el.textContent = 'Enter a whole number from 1 to ' + Math.max(1, state.duration - 1) + '.'; }
                return false;
            }
        }
        return true;
    }

    function applyDuration(n) {
        var r = Ops.resize(state, n, false);
        if (r.error) { return Promise.resolve(false); }
        if (r.needsConfirm) {
            return confirmDialog('Shorten the plan?', '<p>Days ' + esc(daysText(r.dropped).replace(/^Day /, '')) + ' have vegetables and would be removed from this plan.</p>', 'Remove those days', true).then(function (ok) {
                if (!ok) { return false; }
                state = Ops.resize(state, n, true).state; return true;
            });
        }
        state = r.state;
        return Promise.resolve(true);
    }

    function setPreset(preset) {
        collectStep1();
        plan.preset = preset;
        var go = Promise.resolve(true);
        if (preset !== 'custom') {
            var n = Number(preset);
            go = applyDuration(n).then(function (ok) {
                if (ok) {
                    var p = planForCount(n); if (p) { plan.subscription_plan_id = String(p.id); }
                    if (!plan.name) { plan.name = n + '-Day Vegetable Menu'; }
                }
                return ok;
            });
        }
        go.then(function (ok) { if (!ok) { plan.preset = presetFor(state.duration); } render(); });
    }

    function checkPlanMismatch() {
        var w = $('#mp-sub-warn'); if (!w) { return; }
        var sp = $('#mp-subplan');
        plan.subscription_plan_id = sp ? sp.value : plan.subscription_plan_id;
        var p = selectedPlan();
        w.textContent = (p && Number(p.delivery_count) !== state.duration)
            ? 'Heads up: ' + p.name + ' has ' + p.delivery_count + ' deliveries, but this menu is ' + state.duration + ' days. Days beyond the plan length are never used.' : '';
    }

    function goStep(n) { step = n; render(); window.scrollTo({ top: root.getBoundingClientRect().top + window.pageYOffset - 70, behavior: 'smooth' }); announce('Step ' + n + ': ' + STEPS[n - 1]); }

    root.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('button, [data-edit-day]') : null;
        if (!t) { return; }
        if (t.hasAttribute('data-preset')) { setPreset(t.getAttribute('data-preset')); return; }
        if (t.id === 'mp-next') {
            if (step === 1) { validateStep1().then(function (ok) { if (ok) { goStep(2); } }); }
            else if (step === 2) { goStep(3); }
            else if (step === 3) { goStep(4); }
            return;
        }
        if (t.id === 'mp-back') { goStep(step - 1); return; }
        if (t.id === 'mp-expand-all') { for (var d = 1; d <= state.duration; d++) { expanded[d] = true; refreshDayBody(d); } return; }
        if (t.id === 'mp-collapse-all') { expanded = {}; for (var c = 1; c <= state.duration; c++) { refreshDayBody(c); } return; }
        if (t.id === 'mp-apply-repeat') { return doRepeat(); }
        if (t.hasAttribute('data-edit-day')) { var ed = Number(t.getAttribute('data-edit-day')); expanded[ed] = true; step = 2; render(); var el = $('#mp-dh-' + ed); if (el) { el.scrollIntoView({ block: 'center' }); } return; }
        if (t.hasAttribute('data-add')) { return addFromResult(t); }
        var dayEl = t.closest('.mp-day'); if (!dayEl) { return; }
        var day = Number(dayEl.getAttribute('data-day'));
        var act = t.getAttribute('data-act');
        if (act === 'toggle') { expanded[day] = !expanded[day]; refreshDayBody(day); return; }
        if (act === 'prev') { return withOverwrite(day, [day], 'Copy Day ' + (day - 1) + ' into Day ' + day + '?', function () { Ops.copyPrevious(state, day); finishDay(day, 'Copied Day ' + (day - 1) + ' into Day ' + day + '.'); }); }
        if (act === 'dup') { return withOverwrite(day, [day + 1], 'Duplicate Day ' + day + ' into Day ' + (day + 1) + '?', function () { Ops.duplicateToNext(state, day); finishDay(day + 1, 'Duplicated Day ' + day + ' into Day ' + (day + 1) + '.'); }); }
        if (act === 'copyto') { return doCopyTo(day); }
        if (act === 'clear') {
            return confirmDialog('Clear Day ' + day + '?', '<p>All ' + state.days[day].items.length + ' vegetables will be removed from this day.</p>', 'Clear day', true).then(function (ok) { if (ok) { Ops.clearDay(state, day); finishDay(day, 'Day ' + day + ' cleared.'); } });
        }
        if (act === 'remove') {
            var idx = Number(t.closest('tr').getAttribute('data-idx')); var it = state.days[day].items[idx];
            if (it) { Ops.removeLine(state, day, it.variant_id); refreshDayBody(day); refreshProgress(); announce('Removed ' + it.product_name); }
        }
    });

    function finishDay(day, msg) { refreshDayBody(day); var prevDay = day - 1; if (prevDay >= 1) { refreshDayHeader(prevDay); } if (day < state.duration) { refreshDayHeader(day + 1); } refreshProgress(); announce(msg); }

    function withOverwrite(day, targets, title, run) {
        var conflict = targets.filter(function (d) { return state.days[d] && state.days[d].items.length > 0; });
        if (!conflict.length) { run(); return Promise.resolve(); }
        return confirmDialog(title, '<p>Day ' + conflict.join(', ') + ' already has vegetables. They will be replaced.</p>', 'Replace', true).then(function (ok) { if (ok) { run(); } });
    }

    function doCopyTo(day) {
        pickDaysDialog(day).then(function (sel) {
            if (!sel) { return; }
            var r = Ops.copyToDays(state, day, sel, false);
            if (r.needsConfirm) {
                return confirmDialog('Replace existing menus?', '<p>Day ' + r.conflicts.join(', ') + ' already have vegetables. Copying will replace them.</p>', 'Replace and copy', true).then(function (ok) {
                    if (ok) { Ops.copyToDays(state, day, sel, true); render(); announce('Copied to ' + sel.length + ' days.'); }
                });
            }
            render(); announce('Copied to ' + sel.length + ' days.');
        });
    }

    function doRepeat() {
        var r = Ops.applyRepeat(state, plan.repeat_mode, plan.repeat_every, false, false);
        if (r.ok === false) { errors = [r.message]; render(); return; }
        function summary(plan2) { return plan2.skipped.length ? '<p>' + plan2.skipped.length + ' manually edited day(s) were left untouched (' + esc(daysText(plan2.skipped)) + ').</p>' : ''; }
        if (r.needsConfirm) {
            confirmDialog('Replace configured days?', '<p>Day ' + r.conflicts.slice(0, 15).join(', ') + (r.conflicts.length > 15 ? ' and more' : '') + ' already have different vegetables. Filling repeated days will replace them.</p>' + summary(r), 'Replace and fill', true).then(function (ok) {
                if (!ok) { return; }
                Ops.applyRepeat(state, plan.repeat_mode, plan.repeat_every, true, false); render(); announce('Repeated days filled.');
            });
            return;
        }
        var skipped = r.skipped.length;
        function done() { render(); announce('Repeated days filled.'); }
        if (skipped) {
            confirmDialog('Some days were kept', '<p>' + skipped + ' manually edited day(s) were not changed: ' + esc(daysText(r.skipped)) + '.</p><p>Do you also want to overwrite those with the repeated menu?</p>', 'Overwrite them too', true).then(function (ok) {
                if (ok) { var r2 = Ops.applyRepeat(state, plan.repeat_mode, plan.repeat_every, true, true); }
                done();
            });
        } else { done(); }
    }

    /* inputs & search (delegated) */
    root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.name === 'mp-rep') { collectStep1(); var sub = $('.mp-sub'); if (sub) { sub.hidden = plan.repeat_mode !== 'every_n'; } return; }
        if (t.id === 'mp-subplan') { checkPlanMismatch(); return; }
        if (t.id === 'mp-live') { plan.is_active = t.checked; return; }
        if (t.id === 'mp-allow') { plan.allow_incomplete = t.checked; return; }
        if (t.id === 'mp-refresh') { plan.refresh_prices = t.checked; return; }
        var f = t.getAttribute && t.getAttribute('data-f');
        if (f) {
            var dayEl = t.closest('.mp-day'); var day = Number(dayEl.getAttribute('data-day'));
            var idx = Number(t.closest('tr').getAttribute('data-idx'));
            var it = state.days[day].items[idx]; if (!it) { return; }
            if (f === 'qty') { it.qty = t.value === '' ? 0 : Number(t.value); } else { it.max_qty = t.value === '' ? null : Number(t.value); }
            state.days[day].customized = true;
            refreshDayBody(day); refreshProgress();
        }
    });
    root.addEventListener('input', function (e) {
        var t = e.target;
        if (t.id === 'mp-duration') {
            var v = Ops.validateDuration(t.value); var el = $('#mp-dur-err'); if (el) { el.textContent = v.ok ? '' : v.message; }
        }
        if (t.getAttribute && t.getAttribute('data-act') === 'search') {
            var day = Number(t.closest('.mp-day').getAttribute('data-day'));
            clearTimeout(searchTimers[day]);
            searchTimers[day] = setTimeout(function () { runSearch(day, t.value); }, 250);
        }
    });

    function runSearch(day, q) {
        var box = $('#mp-r-' + day); if (!box) { return; }
        q = q.trim();
        if (q.length < 1) { box.innerHTML = ''; return; }
        box.innerHTML = '<p class="at-muted text-sm">Searching…</p>';
        fetch(cfg.searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var items = (j && j.data && j.data.items) || [];
                if (!items.length) { box.innerHTML = '<p class="at-muted text-sm">No vegetables match “' + esc(q) + '”.</p>'; return; }
                var have = {}; state.days[day].items.forEach(function (it) { have[it.variant_id] = true; });
                box.innerHTML = '<ul class="mp-result-list">' + items.map(function (it) {
                    var dis = !it.available || have[it.variant_id];
                    return '<li class="mp-result"><button type="button" class="mp-result__btn at-focusable" data-add="1" data-day="' + day + '" data-payload="' + esc(JSON.stringify(it)) + '"' + (dis ? ' disabled' : '') + '>' +
                        imgHtml(it) + '<span class="mp-result__main"><strong>' + esc(it.product_name) + '</strong> <span class="at-muted">' + esc(it.variant_name) + '</span><br><span class="text-sm at-muted">SKU ' + esc(it.sku || '–') + ' · Stock ' + esc(it.stock_qty) + ' · ' + money(it.price) + '</span>' +
                        (!it.available ? '<br><span class="mp-flag">' + esc(it.unavailable_reason) + '</span>' : (have[it.variant_id] ? '<br><span class="mp-flag mp-flag--soft">Already in this day</span>' : (!it.in_stock ? '<br><span class="mp-flag mp-flag--soft">Out of stock now</span>' : ''))) + '</span><span class="mp-result__add">Add</span></button></li>';
                }).join('') + '</ul>';
            })
            .catch(function () { box.innerHTML = '<p class="mp-err">Search failed. Check your connection and try again.</p>'; });
    }

    function addFromResult(btn) {
        var day = Number(btn.getAttribute('data-day'));
        var it; try { it = JSON.parse(btn.getAttribute('data-payload')); } catch (e) { return; }
        var r = Ops.addLine(state, day, { variant_id: it.variant_id, product_name: it.product_name, variant_name: it.variant_name, unit_label: it.unit_label, price: it.price, current_price: it.price, stock_qty: it.stock_qty, available: it.available, in_stock: it.in_stock, image_url: it.image_url, qty: 1, max_qty: null });
        if (!r.ok) { errors = [r.message]; render(); return; }
        refreshDayBody(day); refreshProgress();
        announce('Added ' + it.product_name + ' to Day ' + day);
        var s = $('#mp-s-' + day); if (s) { s.focus(); }
    }

    root.addEventListener('submit', function (e) {
        if (e.target.id !== 'mp-form') { return; }
        e.preventDefault();
        var form = e.target;
        var days = {};
        for (var d = 1; d <= state.duration; d++) {
            days[d] = { customized: state.days[d].customized, items: state.days[d].items.map(function (it) { return { variant_id: it.variant_id, qty: it.qty, max_qty: it.max_qty }; }) };
        }
        var live = $('#mp-live'); var allow = $('#mp-allow'); var refresh = $('#mp-refresh');
        document.getElementById('mp-payload').value = JSON.stringify({
            id: plan.id, name: plan.name, duration_days: state.duration, subscription_plan_id: plan.subscription_plan_id,
            repeat_mode: plan.repeat_mode, repeat_every: plan.repeat_every,
            is_active: live ? live.checked : false, allow_incomplete: allow ? allow.checked : false, refresh_prices: refresh ? refresh.checked : false, days: days
        });
        var b = $('#mp-save');
        if (b) { b.disabled = true; b.textContent = 'Saving…'; }

        function nativeSubmit() {
            HTMLFormElement.prototype.submit.call(form);
        }

        if (!live || !live.checked || !plan.subscription_plan_id) {
            nativeSubmit();
            return;
        }
        var liveMap = cfg.liveMenuBySubscriptionId || {};
        var other = liveMap[String(plan.subscription_plan_id)];
        if (!other || (plan.id && Number(other.id) === Number(plan.id))) {
            nativeSubmit();
            return;
        }
        var body = '<p>Making <strong>' + esc(plan.name) + '</strong> live will deactivate the current live menu <strong>' + esc(other.name) + '</strong> for this subscription plan.</p>' +
            '<p class="at-muted text-sm">Existing customer baskets and subscriptions are not changed. Only new subscriptions started after this save will use the new menu.</p>';
        confirmDialog('Replace the live menu?', body, 'Make live and save', true).then(function (ok) {
            if (ok) {
                nativeSubmit();
            } else if (b) {
                b.disabled = false;
                b.textContent = 'Save Menu Plan';
            }
        });
    });

    render();
}());
