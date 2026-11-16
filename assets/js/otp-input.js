/* Six-box OTP input. Combines digits into the hidden #otp field before submit. */
(function (root) {
  'use strict';

  var LEN = 6;

  /** Digits only, max 6, in order. */
  function extractDigits(raw) {
    return String(raw == null ? '' : raw).replace(/\D+/g, '').slice(0, LEN);
  }

  /** Spread pasted/autofilled text over six slots starting at box 0. Returns {values, focusIndex}. */
  function distribute(raw) {
    var d = extractDigits(raw);
    var values = [];
    for (var i = 0; i < LEN; i++) {
      values.push(i < d.length ? d.charAt(i) : '');
    }
    return { values: values, focusIndex: Math.min(d.length, LEN - 1) };
  }

  function init(form) {
    if (!form) {
      return;
    }
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-otp-box]'));
    var hidden = form.querySelector('#otp');
    var hint = document.getElementById('otp-hint');
    if (boxes.length !== LEN || !hidden) {
      return;
    }

    function combined() {
      return boxes.map(function (b) { return b.value; }).join('');
    }
    function sync() {
      hidden.value = combined();
      if (hint && hidden.value.length === LEN) {
        hint.classList.add('hidden');
      }
      return hidden.value.length === LEN;
    }
    function fillFrom(raw) {
      var r = distribute(raw);
      boxes.forEach(function (b, i) { b.value = r.values[i]; });
      boxes[r.focusIndex].focus();
      boxes[r.focusIndex].select();
      sync();
    }

    boxes.forEach(function (box, i) {
      box.addEventListener('focus', function () { box.select(); });
      box.addEventListener('paste', function (e) {
        var text = e.clipboardData ? e.clipboardData.getData('text') : '';
        e.preventDefault();
        if (extractDigits(text) !== '') {
          fillFrom(text);
        }
      });
      box.addEventListener('input', function (e) {
        var raw = box.value;
        var d = raw.replace(/\D+/g, '');
        if (d.length > 1 && e && e.inputType === 'insertText') {
          d = d.slice(-1); // typed over an existing digit: keep the new one
        }
        if (d.length > 1) {
          // Autofill / IME / fallback paste: treat as a whole code.
          fillFrom(d);
          return;
        }
        box.value = d;
        if (d !== '' && i < LEN - 1) {
          boxes[i + 1].focus();
        }
        sync();
      });
      box.addEventListener('keydown', function (e) {
        var k = e.key;
        if (k === 'Backspace') {
          if (box.value === '' && i > 0) {
            e.preventDefault();
            boxes[i - 1].value = '';
            boxes[i - 1].focus();
            sync();
          }
        } else if (k === 'ArrowLeft' && i > 0) {
          e.preventDefault();
          boxes[i - 1].focus();
        } else if (k === 'ArrowRight' && i < LEN - 1) {
          e.preventDefault();
          boxes[i + 1].focus();
        } else if (k === 'Home') {
          e.preventDefault();
          boxes[0].focus();
        } else if (k === 'End') {
          e.preventDefault();
          boxes[LEN - 1].focus();
        } else if (k && k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && /\D/.test(k)) {
          e.preventDefault(); // block non-numeric characters
        }
      });
    });

    form.addEventListener('submit', function (e) {
      if (!sync()) {
        e.preventDefault();
        if (hint) {
          hint.classList.remove('hidden');
        }
        for (var i = 0; i < LEN; i++) {
          if (boxes[i].value === '') {
            boxes[i].focus();
            break;
          }
        }
      }
    });

    boxes[0].focus();
  }

  var api = { extractDigits: extractDigits, distribute: distribute, init: init };
  root.AtOtpInput = api;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
})(typeof window !== 'undefined' ? window : this);
