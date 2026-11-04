#!/usr/bin/env node
/* Menu Planner draft-operation tests (pure logic, no DB). Run: node scripts/test-menu-planner-ops.js */
'use strict';
var Ops = require('../assets/js/menu-planner-ops.js');
var pass = 0, fail = 0;
function ok(c, m) { console.log((c ? 'OK' : 'FAIL') + ': ' + m); if (c) { pass++; } else { fail++; } }
function line(id, qty, extra) { return Object.assign({ variant_id: id, qty: qty || 1, max_qty: null, price: 10 * id, available: true, product_name: 'P' + id, variant_name: '1 kg' }, extra || {}); }
function ids(s, d) { return s.days[d].items.map(function (i) { return i.variant_id; }).join(','); }

// Duration validation
ok(Ops.validateDuration('0').ok === false, 'Duration 0 rejected');
ok(Ops.validateDuration('abc').ok === false, 'Duration "abc" rejected');
ok(Ops.validateDuration('-3').ok === false, 'Duration -3 rejected');
ok(Ops.validateDuration('7.5').ok === false, 'Duration 7.5 rejected');
ok(Ops.validateDuration('366').ok === false, 'Duration 366 rejected (max 365)');
ok(Ops.validateDuration('365').ok === true && Ops.validateDuration('1').ok === true, 'Duration 1 and 365 accepted');
ok(Object.keys(Ops.newState(45).days).length === 45, 'Custom 45-day state has 45 day cards');

// Add multiple, status, incomplete
var s = Ops.newState(7);
ok(Ops.addLine(s, 1, line(1)).ok && Ops.addLine(s, 1, line(2)).ok && Ops.addLine(s, 1, line(3)).ok, 'Add multiple vegetables to a day');
ok(Ops.addLine(s, 1, line(2)).ok === false, 'Duplicate variant in one day rejected');
ok(Ops.dayStatus(s.days[1].items) === 'ready' && Ops.dayStatus(s.days[2].items) === 'not_configured', 'Day status: ready / not configured');
Ops.addLine(s, 2, line(1));
ok(Ops.dayStatus(s.days[2].items) === 'partial', 'Day status: partially configured');
var st = Ops.stats(s);
ok(st.configured === 2 && st.incomplete.length === 5 && st.incomplete[0] === 3, 'Detect incomplete plan (2 of 7 configured, empty days listed)');
ok(Ops.validateLine({ qty: 0 }).length === 1 && Ops.validateLine({ qty: 2, max_qty: 1 }).length === 1 && Ops.validateLine({ qty: 2, max_qty: 2 }).length === 0, 'Line validation: qty>0, max>=qty');

// Edit one day does not touch others
Ops.copyDay(s, 1, 3);
Ops.removeLine(s, 3, 1);
ok(ids(s, 3) === '2,3' && ids(s, 1) === '1,2,3', 'Editing a copied day does not change the source day');

// Copy previous / duplicate / copy to / clear
var c = Ops.newState(5);
Ops.setItems(c, 1, [line(1), line(2)]);
ok(Ops.copyPrevious(c, 2).ok && ids(c, 2) === '1,2', 'Copy previous day');
ok(Ops.copyPrevious(c, 1).ok === false, 'Day 1 has no previous day');
Ops.setItems(c, 3, []);
ok(Ops.duplicateToNext(c, 2).ok && ids(c, 3) === '1,2', 'Duplicate a day into the next day');
ok(Ops.duplicateToNext(c, 5).ok === false, 'Cannot duplicate beyond the last day');
c.days[2].items[0].qty = 9;
ok(c.days[3].items[0].qty === 1, 'Duplicated lines are independent copies');
var r1 = Ops.copyToDays(c, 1, [4, 5], false);
ok(r1.ok && ids(c, 4) === '1,2' && ids(c, 5) === '1,2', 'Copy to selected days');
Ops.setItems(c, 4, [line(9)]);
var r2 = Ops.copyToDays(c, 1, [4, 5], false);
ok(r2.ok === false && r2.needsConfirm && r2.conflicts.join() === '4,5' && ids(c, 4) === '9', 'Copy to selected days asks before overwriting configured days (no change)');
var r3 = Ops.copyToDays(c, 1, [4], true);
ok(r3.ok && ids(c, 4) === '1,2', 'Copy to selected days overwrites after confirmation');
Ops.clearDay(c, 4);
ok(c.days[4].items.length === 0 && Ops.dayStatus(c.days[4].items) === 'not_configured', 'Clear day');

// Repeat after N days
var rp = Ops.newState(10);
Ops.setItems(rp, 1, [line(1)]); Ops.setItems(rp, 2, [line(2)]); Ops.setItems(rp, 3, [line(3)]);
rp.days[1].customized = rp.days[2].customized = rp.days[3].customized = false;
var res = Ops.applyRepeat(rp, 'every_n', 3, false, false);
ok(res.ok !== false && !res.needsConfirm && ids(rp, 4) === '1' && ids(rp, 5) === '2' && ids(rp, 6) === '3' && ids(rp, 10) === '1' && ids(rp, 9) === '3', 'Repeat after 3 days fills days 4-10 from the pattern');
ok(Ops.applyRepeat(Ops.newState(5), 'every_n', 5, false, false).ok === false, 'Repeat interval >= duration rejected');
ok(Ops.applyRepeat(Ops.newState(5), 'every_n', 0, false, false).ok === false, 'Repeat interval 0 rejected');

// Independent edit of a repeated day, and re-applying does not silently overwrite it
Ops.setItems(rp, 5, [line(7)]);
ok(ids(rp, 8) === '2' && ids(rp, 5) === '7', 'Edited repeated day is independent from other repeated days');
Ops.setItems(rp, 1, [line(11)]); rp.days[1].customized = false;
var again = Ops.applyRepeat(rp, 'every_n', 3, false, false);
ok(again.needsConfirm && again.conflicts.indexOf(4) !== -1 && ids(rp, 4) === '1', 'Re-applying repeat asks before replacing configured days (nothing changed yet)');
ok(again.skipped.indexOf(5) !== -1, 'Manually customized day is reported as protected');
var again2 = Ops.applyRepeat(rp, 'every_n', 3, true, false);
ok(ids(rp, 4) === '11' && ids(rp, 5) === '7', 'After confirmation, repeated days update but the manually customized day stays untouched');
var forced = Ops.applyRepeat(rp, 'every_n', 3, true, true);
ok(ids(rp, 5) === '2', 'Customized day is overwritten only with explicit "overwrite them too"');

// Weekly repeat
var wk = Ops.newState(30);
for (var d = 1; d <= 7; d++) { Ops.setItems(wk, d, [line(d)]); wk.days[d].customized = false; }
Ops.applyRepeat(wk, 'weekly', null, false, false);
ok(ids(wk, 8) === '1' && ids(wk, 14) === '7' && ids(wk, 15) === '1' && ids(wk, 30) === '2', 'Repeat the same weekly menu for a 30-day plan');
var short = Ops.newState(5);
ok(Ops.applyRepeat(short, 'weekly', null, false, false).ok !== false, 'Weekly repeat on a plan of 7 days or fewer is a harmless no-op');

// Resize
var rz = Ops.newState(10);
Ops.setItems(rz, 9, [line(1)]);
var shrink = Ops.resize(rz, 5, false);
ok(shrink.needsConfirm && shrink.dropped.join() === '9' && rz.duration === 10, 'Shrinking over configured days needs confirmation');
ok(Ops.resize(rz, 5, true).state.duration === 5, 'Shrink applies after confirmation');
ok(Ops.resize(rz, 15, false).state.duration === 15 && Ops.resize(rz, 15, false).state.days[9].items.length === 1, 'Growing keeps existing days');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
