(function () {
  'use strict';

  function pad2(n) {
    n = Math.max(0, Math.floor(n));
    return n < 10 ? '0' + n : String(n);
  }

  function remainingParts(ms) {
    if (ms <= 0) {
      return null;
    }
    var totalSec = Math.floor(ms / 1000);
    var days = Math.floor(totalSec / 86400);
    totalSec %= 86400;
    var hours = Math.floor(totalSec / 3600);
    totalSec %= 3600;
    var mins = Math.floor(totalSec / 60);
    var secs = totalSec % 60;
    return {
      days: days,
      hours: hours,
      mins: mins,
      secs: secs,
      text:
        pad2(days) +
        ' Days ' +
        pad2(hours) +
        ' Hours ' +
        pad2(mins) +
        ' Minutes ' +
        pad2(secs) +
        ' Seconds',
    };
  }

  function applyParts(el, parts) {
    var value = el.querySelector('[data-at-offer-countdown-value]');
    if (value) {
      value.textContent = parts.text;
    }
    var days = el.querySelector('[data-at-offer-countdown-days]');
    var hours = el.querySelector('[data-at-offer-countdown-hours]');
    var mins = el.querySelector('[data-at-offer-countdown-mins]');
    var secs = el.querySelector('[data-at-offer-countdown-secs]');
    if (days && hours && mins && secs) {
      days.textContent = pad2(parts.days);
      hours.textContent = pad2(parts.hours);
      mins.textContent = pad2(parts.mins);
      secs.textContent = pad2(parts.secs);
    } else if (value) {
      value.textContent = parts.text;
    }
  }

  function tick() {
    var now = Date.now();
    document.querySelectorAll('[data-at-offer-countdown]').forEach(function (el) {
      var endSec = parseInt(el.getAttribute('data-countdown-end'), 10);
      if (!endSec) {
        return;
      }
      var parts = remainingParts(endSec * 1000 - now);
      if (!parts) {
        el.classList.add('hidden');
        el.setAttribute('hidden', 'hidden');
        var timerWrap = el.closest('.at-offer-deal__timer');
        if (timerWrap) {
          timerWrap.classList.add('hidden');
        }
        return;
      }
      el.classList.remove('hidden');
      el.removeAttribute('hidden');
      var timerWrapShow = el.closest('.at-offer-deal__timer');
      if (timerWrapShow) {
        timerWrapShow.classList.remove('hidden');
      }
      applyParts(el, parts);
    });
  }

  window.ATOfferCountdownTick = tick;

  function init() {
    tick();
    setInterval(tick, 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
