/**
 * Subscription Menu Planner - pure draft operations (no DOM, no network).
 * State shape: { duration: number, days: { [n]: { customized: boolean, items: Line[] } } }
 * Line: { variant_id, qty, max_qty|null, price, unit_label, product_name, variant_name, stock_qty, available, image_url }
 * Usable in the browser (window.MenuPlannerOps) and in Node (module.exports) for tests.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MenuPlannerOps = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX_DAYS = 365;
    var READY_MIN_ITEMS = 3;

    function cloneItems(items) {
        return (items || []).map(function (it) { return Object.assign({}, it); });
    }

    function emptyDay() {
        return { customized: false, items: [] };
    }

    function newState(duration) {
        var s = { duration: 0, days: {} };
        return resize(s, duration).state;
    }

    function dayStatus(items) {
        var n = (items || []).length;
        if (n === 0) { return 'not_configured'; }
        var avail = 0;
        items.forEach(function (it) { if (it.available !== false) { avail++; } });
        return (n >= READY_MIN_ITEMS && avail === n) ? 'ready' : 'partial';
    }

    function dayValue(items) {
        var t = 0;
        (items || []).forEach(function (it) { t += Number(it.qty) * Number(it.price); });
        return Math.round(t * 100) / 100;
    }

    function stats(state) {
        var configured = 0, entries = 0, incomplete = [], ready = 0;
        for (var d = 1; d <= state.duration; d++) {
            var items = (state.days[d] || emptyDay()).items;
            if (items.length) { configured++; entries += items.length; } else { incomplete.push(d); }
            if (dayStatus(items) === 'ready') { ready++; }
        }
        return { configured: configured, entries: entries, incomplete: incomplete, ready: ready, duration: state.duration };
    }

    function validateDuration(raw) {
        var s = String(raw === null || raw === undefined ? '' : raw).trim();
        if (!/^\d+$/.test(s)) { return { ok: false, message: 'Enter a whole number of days between 1 and ' + MAX_DAYS + '.' }; }
        var n = parseInt(s, 10);
        if (n < 1 || n > MAX_DAYS) { return { ok: false, message: 'Duration must be between 1 and ' + MAX_DAYS + ' days.' }; }
        return { ok: true, value: n };
    }

    /** Change duration. Shrinking below configured days needs confirm (returns needsConfirm, no change). */
    function resize(state, newDuration, confirmDrop) {
        var v = validateDuration(newDuration);
        if (!v.ok) { return { state: state, error: v.message }; }
        var n = v.value;
        var dropped = [];
        for (var d = n + 1; d <= state.duration; d++) {
            if ((state.days[d] || emptyDay()).items.length) { dropped.push(d); }
        }
        if (dropped.length && !confirmDrop) {
            return { state: state, needsConfirm: true, dropped: dropped };
        }
        var days = {};
        for (var i = 1; i <= n; i++) {
            days[i] = state.days[i] ? { customized: !!state.days[i].customized, items: cloneItems(state.days[i].items) } : emptyDay();
        }
        return { state: { duration: n, days: days }, dropped: dropped };
    }

    function setItems(state, day, items) {
        if (!state.days[day]) { return state; }
        state.days[day].items = cloneItems(items);
        state.days[day].customized = true;
        return state;
    }

    function addLine(state, day, line) {
        var items = state.days[day].items;
        for (var i = 0; i < items.length; i++) {
            if (Number(items[i].variant_id) === Number(line.variant_id)) {
                return { ok: false, message: 'This vegetable variant is already in Day ' + day + '.' };
            }
        }
        items.push(Object.assign({ qty: 1, max_qty: null }, line));
        state.days[day].customized = true;
        return { ok: true };
    }

    function removeLine(state, day, variantId) {
        var d = state.days[day];
        d.items = d.items.filter(function (it) { return Number(it.variant_id) !== Number(variantId); });
        d.customized = true;
        return state;
    }

    function validateLine(line) {
        var errors = [];
        var qty = Number(line.qty);
        if (!(qty > 0)) { errors.push('Quantity must be greater than 0.'); }
        if (line.max_qty !== null && line.max_qty !== '' && line.max_qty !== undefined) {
            var max = Number(line.max_qty);
            if (isNaN(max) || (qty > 0 && max < qty)) { errors.push('Max quantity cannot be less than the suggested quantity.'); }
        }
        if (line.available === false) { errors.push('This variant is inactive or unavailable.'); }
        return errors;
    }

    function clearDay(state, day) {
        state.days[day].items = [];
        state.days[day].customized = true;
        return state;
    }

    function copyDay(state, from, to) {
        if (!state.days[from] || !state.days[to] || from === to) { return state; }
        state.days[to].items = cloneItems(state.days[from].items);
        state.days[to].customized = true;
        return state;
    }

    function copyPrevious(state, day) {
        if (day <= 1) { return { ok: false, message: 'Day 1 has no previous day.' }; }
        copyDay(state, day - 1, day);
        return { ok: true };
    }

    /** Duplicate this day into the next day. */
    function duplicateToNext(state, day) {
        if (day >= state.duration) { return { ok: false, message: 'This is the last day.' }; }
        copyDay(state, day, day + 1);
        return { ok: true };
    }

    /** Copy to several days. If any target already has vegetables and !overwrite, nothing changes. */
    function copyToDays(state, from, targets, overwrite) {
        var list = [];
        (targets || []).forEach(function (t) {
            t = Number(t);
            if (t >= 1 && t <= state.duration && t !== from && list.indexOf(t) === -1) { list.push(t); }
        });
        var conflicts = list.filter(function (t) { return state.days[t].items.length > 0; });
        if (conflicts.length && !overwrite) { return { ok: false, needsConfirm: true, conflicts: conflicts }; }
        list.forEach(function (t) { copyDay(state, from, t); });
        return { ok: true, copied: list };
    }

    function sameItems(a, b) {
        if (a.length !== b.length) { return false; }
        for (var i = 0; i < a.length; i++) {
            if (a[i].variant_id !== b[i].variant_id || Number(a[i].qty) !== Number(b[i].qty)
                || Number(a[i].max_qty || 0) !== Number(b[i].max_qty || 0)) { return false; }
        }
        return true;
    }

    function repeatEvery(mode, every) {
        if (mode === 'weekly') { return 7; }
        if (mode === 'every_n') { return Number(every); }
        return 0;
    }

    /**
     * Plan a repeat: days 1..N are the pattern; day d > N copies day ((d-1) % N) + 1.
     * writes: will be filled; skipped: manually customized (kept); conflicts: configured, not customized, would change.
     */
    function planRepeat(state, mode, every) {
        var n = repeatEvery(mode, every);
        var out = { ok: true, writes: [], skipped: [], conflicts: [], n: n };
        if (mode === 'none') { return out; }
        if (!(n >= 1) || n !== Math.floor(n) || n >= state.duration) {
            if (mode === 'weekly' && state.duration <= 7) { return out; }
            return { ok: false, message: 'Repeat interval must be from 1 to ' + Math.max(1, state.duration - 1) + '.', writes: [], skipped: [], conflicts: [], n: n };
        }
        for (var d = n + 1; d <= state.duration; d++) {
            var src = ((d - 1) % n) + 1;
            var target = state.days[d];
            if (target.customized) { out.skipped.push(d); continue; }
            if (sameItems(target.items, state.days[src].items)) { continue; }
            if (target.items.length) { out.conflicts.push(d); }
            out.writes.push(d);
        }
        return out;
    }

    /** Apply a repeat. Asks for confirmation (changes nothing) when configured, non-customized days would be replaced. */
    function applyRepeat(state, mode, every, confirmOverwrite, includeCustomized) {
        var plan = planRepeat(state, mode, every);
        if (!plan.ok) { return plan; }
        if (includeCustomized) {
            // Recompute treating customized days as overwritable (still needs explicit confirmation).
            var n = plan.n;
            var extra = plan.skipped.filter(function (d) {
                var src = ((d - 1) % n) + 1;
                return !sameItems(state.days[d].items, state.days[src].items);
            });
            plan.skipped = [];
            extra.forEach(function (d) {
                if (state.days[d].items.length) { plan.conflicts.push(d); }
                plan.writes.push(d);
            });
        }
        if (plan.conflicts.length && !confirmOverwrite) {
            plan.needsConfirm = true;
            return plan;
        }
        var nn = plan.n;
        plan.writes.forEach(function (d) {
            var src = ((d - 1) % nn) + 1;
            state.days[d].items = cloneItems(state.days[src].items);
            state.days[d].customized = false;
        });
        return plan;
    }

    return {
        MAX_DAYS: MAX_DAYS,
        READY_MIN_ITEMS: READY_MIN_ITEMS,
        newState: newState,
        resize: resize,
        validateDuration: validateDuration,
        dayStatus: dayStatus,
        dayValue: dayValue,
        stats: stats,
        setItems: setItems,
        addLine: addLine,
        removeLine: removeLine,
        validateLine: validateLine,
        clearDay: clearDay,
        copyDay: copyDay,
        copyPrevious: copyPrevious,
        duplicateToNext: duplicateToNext,
        copyToDays: copyToDays,
        planRepeat: planRepeat,
        applyRepeat: applyRepeat,
        cloneItems: cloneItems
    };
}));
