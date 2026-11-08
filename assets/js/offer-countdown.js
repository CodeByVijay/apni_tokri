(function () {
  'use strict';

  function pad2(n) {
    n = Math.max(0, Math.floor(n));
    return n < 10 ? '0' + n : String(n);
  }

  function formatRemaining(ms) {
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
    return (
      pad2(days) +
      ' Days ' +
      pad2(hours) +
      ' Hours ' +
      pad2(mins) +
      ' Minutes ' +
      pad2(secs) +
      ' Seconds'
    );
  }

  function tick() {
    var now = Date.now();
    document.querySelectorAll('[data-at-offer-countdown]').forEach(function (el) {
      var endSec = parseInt(el.getAttribute('data-countdown-end'), 10);
      var value = el.querySelector('[data-at-offer-countdown-value]');
      if (!endSec || !value) {
        return;
      }
      var text = formatRemaining(endSec * 1000 - now);
      if (!text) {
        el.classList.add('hidden');
        el.setAttribute('hidden', 'hidden');
        return;
      }
      el.classList.remove('hidden');
      el.removeAttribute('hidden');
      value.textContent = text;
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
