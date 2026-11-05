/* OTP resend cooldown timer (server unix timestamp in data-resend-until). */
(function (root) {
  'use strict';

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatLabel(sec) {
    return 'Resend OTP in ' + pad(Math.floor(sec / 60)) + ':' + pad(sec % 60);
  }

  function initResend(rootEl) {
    if (!rootEl) {
      return;
    }
    var btn = rootEl.querySelector('[data-otp-resend]');
    if (!btn) {
      return;
    }
    var until = parseInt(rootEl.getAttribute('data-resend-until') || '0', 10);
    var url = rootEl.getAttribute('data-resend-url') || '';
    var csrf = rootEl.getAttribute('data-csrf-token') || '';
    var status = rootEl.querySelector('[data-otp-resend-status]');
    var timerId = null;

    function tick() {
      var rem = until > 0 ? Math.max(0, until - Math.floor(Date.now() / 1000)) : 0;
      if (rem > 0) {
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        btn.textContent = formatLabel(rem);
      } else {
        btn.disabled = false;
        btn.removeAttribute('aria-disabled');
        btn.textContent = 'Resend OTP';
        if (timerId !== null) {
          clearInterval(timerId);
          timerId = null;
        }
      }
    }

    function setUntil(unix) {
      until = unix;
      rootEl.setAttribute('data-resend-until', String(unix));
      tick();
      if (until > Math.floor(Date.now() / 1000) && timerId === null) {
        timerId = setInterval(tick, 1000);
      }
    }

    tick();
    if (until > Math.floor(Date.now() / 1000)) {
      timerId = setInterval(tick, 1000);
    }

    btn.addEventListener('click', function () {
      if (btn.disabled || url === '' || csrf === '') {
        return;
      }
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      if (status) {
        status.textContent = 'Sending…';
        status.classList.remove('hidden');
      }
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: '_csrf_token=' + encodeURIComponent(csrf),
      })
        .then(function (r) {
          return r.json().then(function (body) {
            return { ok: r.ok, status: r.status, body: body };
          });
        })
        .then(function (res) {
          var body = res.body || {};
          if (body.success) {
            var cd = (body.data && body.data.cooldown_seconds) || 120;
            setUntil(Math.floor(Date.now() / 1000) + cd);
            if (status) {
              status.textContent = body.message || 'OTP sent.';
              status.classList.remove('at-field-error');
              status.classList.add('text-sm', 'at-muted');
            }
          } else {
            var rem = body.data && body.data.remaining_seconds;
            if (typeof rem === 'number' && rem > 0) {
              setUntil(Math.floor(Date.now() / 1000) + rem);
            } else {
              tick();
            }
            if (status) {
              status.textContent =
                (body.error && body.error.message) || 'Could not resend OTP.';
              status.classList.add('at-field-error');
            }
          }
        })
        .catch(function () {
          tick();
          if (status) {
            status.textContent = 'Could not resend OTP. Try again.';
            status.classList.add('at-field-error');
          }
        })
        .finally(function () {
          btn.removeAttribute('aria-busy');
        });
    });
  }

  root.AtOtpResend = { init: initResend };
})(typeof window !== 'undefined' ? window : this);
