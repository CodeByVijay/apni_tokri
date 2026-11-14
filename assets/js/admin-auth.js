(function () {
  'use strict';

  // Generic show/hide for any button with data-pw-toggle="<input id>" (e.g. change-password page).
  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  function setError(input, msg) {
    var el = document.getElementById(input.id + '-error');
    input.classList.toggle('is-invalid', !!msg);
    input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    if (el) {
      el.textContent = msg || '';
      el.hidden = !msg;
    }
  }

  function isEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
  }

  function startLoading(btn, label) {
    if (!btn) return;
    btn.classList.add('is-loading');
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    var lbl = btn.querySelector('.auth-btn__label');
    if (lbl && label) lbl.textContent = label;
  }

  function stopLoading(btn, label) {
    if (!btn) return;
    btn.disabled = false;
    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    var lbl = btn.querySelector('.auth-btn__label');
    if (lbl && label) lbl.textContent = label;
  }

  // ---- Forgot password form ----
  var forgotForm = document.getElementById('auth-forgot-form');
  if (forgotForm) {
    var fEmail = document.getElementById('email');
    var fSubmit = document.getElementById('auth-submit');
    fEmail.addEventListener('input', function () { setError(fEmail, ''); });
    forgotForm.addEventListener('submit', function (ev) {
      if (!fEmail.value.trim()) {
        ev.preventDefault();
        setError(fEmail, 'Enter your email address.');
        fEmail.focus();
        return;
      }
      if (!isEmail(fEmail.value)) {
        ev.preventDefault();
        setError(fEmail, 'Enter a valid email address.');
        fEmail.focus();
        return;
      }
      startLoading(fSubmit, 'Sending…');
    });
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) stopLoading(fSubmit, 'Send Reset Link');
    });
  }

  // ---- Reset password form ----
  var resetForm = document.getElementById('auth-reset-form');
  if (resetForm) {
    var rNew = document.getElementById('new_password');
    var rConfirm = document.getElementById('confirm_password');
    var rSubmit = document.getElementById('auth-submit');
    [rNew, rConfirm].forEach(function (input) {
      input.addEventListener('input', function () { setError(input, ''); });
    });
    resetForm.addEventListener('submit', function (ev) {
      var ok = true;
      var first = null;
      if (rNew.value.length < 10) {
        setError(rNew, 'Password must be at least 10 characters.');
        ok = false; first = first || rNew;
      } else if (!/[A-Za-z]/.test(rNew.value) || !/\d/.test(rNew.value)) {
        setError(rNew, 'Password must include at least one letter and one number.');
        ok = false; first = first || rNew;
      }
      if (rConfirm.value !== rNew.value) {
        setError(rConfirm, 'Passwords do not match.');
        ok = false; first = first || rConfirm;
      }
      if (!ok) {
        ev.preventDefault();
        if (first) first.focus();
        return;
      }
      startLoading(rSubmit, 'Saving…');
    });
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) stopLoading(rSubmit, 'Save New Password');
    });
  }

  var form = document.getElementById('auth-login-form');
  if (!form) return;

  var email = document.getElementById('email');
  var password = document.getElementById('password');
  var toggle = document.getElementById('auth-pw-toggle');
  var remember = document.getElementById('remember');
  var submit = document.getElementById('auth-submit');
  var KEY = 'at_admin_login_email';

  // "Remember me" = remember the email address on this device only (never the password).
  try {
    var saved = window.localStorage.getItem(KEY);
    if (saved && email && !email.value) {
      email.value = saved;
      if (remember) remember.checked = true;
    }
  } catch (e) { /* storage unavailable */ }

  if (toggle && password) {
    toggle.addEventListener('click', function () {
      var show = password.type === 'password';
      password.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  }

  [email, password].forEach(function (input) {
    input.addEventListener('input', function () { setError(input, ''); });
  });

  form.addEventListener('submit', function (ev) {
    var ok = true;
    var first = null;
    var ev1 = email.value.trim();
    if (!ev1) {
      setError(email, 'Enter your email address.');
      ok = false; first = first || email;
    } else if (!/^[^\s@]+@[^\s@]+$/.test(ev1)) {
      setError(email, 'Enter a valid email address.');
      ok = false; first = first || email;
    }
    if (!password.value) {
      setError(password, 'Enter your password.');
      ok = false; first = first || password;
    }
    if (!ok) {
      ev.preventDefault();
      if (first) first.focus();
      return;
    }
    try {
      if (remember && remember.checked) window.localStorage.setItem(KEY, ev1);
      else window.localStorage.removeItem(KEY);
    } catch (e) { /* ignore */ }

    submit.classList.add('is-loading');
    submit.setAttribute('aria-busy', 'true');
    submit.disabled = true;
    var label = submit.querySelector('.auth-btn__label');
    if (label) label.textContent = 'Signing in…';
  });

  // Restore button if user navigates back (bfcache).
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      submit.disabled = false;
      submit.classList.remove('is-loading');
      submit.removeAttribute('aria-busy');
      var label = submit.querySelector('.auth-btn__label');
      if (label) label.textContent = 'Sign in';
    }
  });
})();
