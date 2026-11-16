/* Admin coupon form: searchable pickers with removable chips, code generator, mode toggles. */
(function () {
    'use strict';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function parseJson(str, fallback) {
        try { return JSON.parse(str || ''); } catch (e) { return fallback; }
    }

    function initPicker(root) {
        var inputName = root.getAttribute('data-input-name');
        var source = root.getAttribute('data-source') || 'static';
        var url = root.getAttribute('data-url') || '';
        var type = root.getAttribute('data-type') || '';
        var emptyText = root.getAttribute('data-empty') || 'No matches found.';
        var hint = root.getAttribute('data-hint') || '';
        var staticItems = source === 'static' ? parseJson(root.getAttribute('data-items'), []) : [];
        var selected = {}; // id -> {id,label,sub}
        var order = [];
        parseJson(root.getAttribute('data-selected'), []).forEach(function (it) {
            selected[it.id] = it; order.push(it.id);
        });

        root.innerHTML = '';
        var chips = el('div', 'at-picker__chips');
        chips.setAttribute('aria-live', 'polite');
        var inputs = el('div');
        inputs.hidden = true;
        var search = el('input', 'at-input at-focusable w-full');
        search.type = 'search';
        search.autocomplete = 'off';
        search.placeholder = root.getAttribute('data-placeholder') || 'Search…';
        search.setAttribute('aria-label', search.placeholder);
        var clear = el('button', 'at-btn at-btn--ghost at-btn--sm at-focusable', 'Clear all');
        clear.type = 'button';
        var head = el('div', 'at-picker__head');
        head.appendChild(search);
        head.appendChild(clear);
        var status = el('p', 'text-xs at-muted mt-1', hint);
        var results = el('ul', 'at-picker__results');
        results.hidden = true;
        results.setAttribute('role', 'listbox');
        root.appendChild(chips);
        root.appendChild(head);
        root.appendChild(results);
        root.appendChild(status);
        root.appendChild(inputs);

        function render() {
            chips.innerHTML = '';
            inputs.innerHTML = '';
            if (!order.length) {
                chips.appendChild(el('span', 'text-sm at-muted', root.getAttribute('data-none') || 'Nothing selected.'));
            }
            order.forEach(function (id) {
                var it = selected[id];
                var chip = el('span', 'at-picker__chip');
                chip.appendChild(el('span', '', it.label));
                var x = el('button', 'at-picker__chip-x at-focusable', '×');
                x.type = 'button';
                x.setAttribute('aria-label', 'Remove ' + it.label);
                x.addEventListener('click', function () { toggle(it); });
                chip.appendChild(x);
                chips.appendChild(chip);
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = inputName; h.value = String(id);
                inputs.appendChild(h);
            });
            clear.disabled = order.length === 0;
            root.dispatchEvent(new CustomEvent('picker:change', { bubbles: true, detail: { count: order.length } }));
        }

        function toggle(it) {
            if (selected[it.id]) {
                delete selected[it.id];
                order = order.filter(function (v) { return v !== it.id; });
            } else {
                selected[it.id] = it; order.push(it.id);
            }
            render();
            showResults(lastItems);
        }

        var lastItems = [];
        function showResults(items) {
            lastItems = items;
            results.innerHTML = '';
            if (!items.length) {
                var li0 = el('li', 'at-picker__empty', emptyText);
                results.appendChild(li0);
            }
            items.forEach(function (it) {
                var on = !!selected[it.id];
                var li = el('li', 'at-picker__item' + (on ? ' is-selected' : ''));
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', on ? 'true' : 'false');
                li.tabIndex = 0;
                li.appendChild(el('span', 'at-picker__check', on ? '✓' : ''));
                var body = el('span', 'at-picker__body');
                body.appendChild(el('strong', '', it.label));
                if (it.sub) { body.appendChild(el('small', 'at-muted', it.sub)); }
                li.appendChild(body);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () { toggle(it); });
                li.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(it); }
                });
                results.appendChild(li);
            });
            results.hidden = false;
        }

        function filterStatic(q) {
            q = q.toLowerCase();
            return staticItems.filter(function (it) {
                return !q || (it.label + ' ' + (it.sub || '')).toLowerCase().indexOf(q) !== -1;
            }).slice(0, 50);
        }

        var timer = null, seq = 0;
        function runSearch() {
            var q = search.value.trim();
            if (source === 'static') { showResults(filterStatic(q)); return; }
            if (type === 'customers' && q.length < 2) {
                results.hidden = true; status.textContent = 'Type at least 2 characters (name, mobile or email).'; return;
            }
            var mine = ++seq;
            status.textContent = 'Searching…';
            fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q), {
                credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (mine !== seq) { return; }
                if (!j.success) { status.textContent = j.message || 'Search failed.'; return; }
                status.textContent = hint;
                var items = (j.data.items || []).map(function (r) {
                    return type === 'products'
                        ? { id: r.id, label: r.name, sub: [r.sku ? 'SKU ' + r.sku : '', r.category, r.variants].filter(Boolean).join(' · ') }
                        : { id: r.id, label: r.name || r.mobile || r.email || ('Customer #' + r.id), sub: [r.mobile, r.email].filter(Boolean).join(' · ') };
                });
                showResults(items);
            }).catch(function () { if (mine === seq) { status.textContent = 'Search failed. Check your connection.'; } });
        }

        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(runSearch, source === 'static' ? 0 : 250);
        });
        search.addEventListener('focus', runSearch);
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { results.hidden = true; }
            if (e.key === 'Enter') { e.preventDefault(); }
        });
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) { results.hidden = true; }
        });
        clear.addEventListener('click', function () {
            selected = {}; order = []; render(); if (!results.hidden) { showResults(lastItems); }
        });
        render();
    }

    function initForm() {
        document.querySelectorAll('[data-at-picker]').forEach(initPicker);

        // Mode panels
        function bindMode(radioName, panelAttr) {
            var radios = document.querySelectorAll('input[name="' + radioName + '"]');
            function apply() {
                var v = '';
                radios.forEach(function (r) { if (r.checked) { v = r.value; } });
                document.querySelectorAll('[' + panelAttr + ']').forEach(function (p) {
                    var show = p.getAttribute(panelAttr).split(' ').indexOf(v) !== -1;
                    p.hidden = !show;
                });
            }
            radios.forEach(function (r) { r.addEventListener('change', apply); });
            apply();
        }
        bindMode('eligibility_mode', 'data-show-for-mode');
        bindMode('customer_mode', 'data-show-for-customers');

        // Percent vs fixed
        var type = document.getElementById('coupon-discount-type');
        var maxWrap = document.getElementById('coupon-max-discount-wrap');
        function typeApply() { if (type && maxWrap) { maxWrap.hidden = type.value !== 'percent'; } }
        if (type) { type.addEventListener('change', typeApply); typeApply(); }

        // Code generator + normalize
        var code = document.getElementById('coupon-code');
        var gen = document.getElementById('coupon-generate');
        if (code) {
            code.addEventListener('blur', function () { code.value = code.value.trim().toUpperCase(); });
        }
        if (gen && code) {
            gen.addEventListener('click', function () {
                gen.disabled = true;
                var url = gen.getAttribute('data-url');
                fetch(url + '?type=code', {
                    credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success && j.data && j.data.code) { code.value = j.data.code; code.focus(); }
                    else { alert(j.message || 'Could not generate a code.'); }
                }).catch(function () { alert('Could not generate a code.'); })
                  .then(function () { gen.disabled = false; });
            });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initForm); } else { initForm(); }
})();
