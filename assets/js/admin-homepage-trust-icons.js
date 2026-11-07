(function () {
  'use strict';

  function setSelected(field, id, label, svgHtml) {
    var input = field.querySelector('[data-trust-icon-input]');
    var preview = field.querySelector('.at-trust-icon-field__preview');
    var labelEl = field.querySelector('[data-trust-icon-label]');
    var idEl = field.querySelector('[data-trust-icon-id]');
    if (input) {
      input.value = id;
    }
    if (preview && svgHtml) {
      preview.innerHTML = svgHtml;
    }
    if (labelEl) {
      labelEl.textContent = label;
    }
    if (idEl) {
      idEl.textContent = id;
    }
    field.querySelectorAll('[data-trust-icon-option]').forEach(function (btn) {
      var on = btn.getAttribute('data-icon-id') === id;
      btn.classList.toggle('is-selected', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function initField(field) {
    var toggle = field.querySelector('[data-trust-icon-toggle]');
    var picker = field.querySelector('[data-trust-icon-picker]');
    var search = field.querySelector('[data-trust-icon-search]');
    if (!picker) {
      return;
    }

    function openPicker() {
      picker.hidden = false;
      picker.classList.remove('hidden');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'true');
      }
      if (search) {
        search.focus();
      }
    }

    function closePicker() {
      picker.hidden = true;
      picker.classList.add('hidden');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    }

    if (toggle) {
      toggle.addEventListener('click', function () {
        if (picker.hidden) {
          openPicker();
        } else {
          closePicker();
        }
      });
    }

    field.querySelectorAll('[data-trust-icon-option]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-icon-id') || '';
        var label = btn.getAttribute('data-icon-label') || id;
        var iconNode = btn.querySelector('.at-trust-icon-field__option-icon');
        var svgHtml = iconNode ? iconNode.innerHTML : '';
        setSelected(field, id, label, svgHtml);
        closePicker();
      });
    });

    if (search) {
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        field.querySelectorAll('[data-trust-icon-option]').forEach(function (btn) {
          var id = (btn.getAttribute('data-icon-id') || '').toLowerCase();
          var label = (btn.getAttribute('data-icon-label') || '').toLowerCase();
          var show = q === '' || id.indexOf(q) !== -1 || label.indexOf(q) !== -1;
          btn.classList.toggle('hidden', !show);
        });
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-trust-icon-field]').forEach(initField);
  });
})();
