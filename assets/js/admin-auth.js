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

  function setError(input, msg) {
    var el = document.getElementById(input.id + '-error');
    input.classList.toggle('is-invalid', !!msg);
    input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    if (el) {
      el.textContent = msg || '';
      el.hidden = !msg;
    }
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
