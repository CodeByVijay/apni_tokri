/**
 * Push templates admin — form modal, delete confirmation, placeholder hints.
 */
(function ($) {
  'use strict';

  function readPlaceholderMap() {
    var $json = $('#at-push-template-placeholders-json');
    if (!$json.length) {
      return {};
    }
    try {
      return JSON.parse($json.text() || '{}');
    } catch (e) {
      return {};
    }
  }

  function placeholderHint(eventType, map) {
    var list = map[eventType];
    if (!list || !list.length) {
      return 'Select an event type to see allowed placeholders.';
    }
    return list.map(function (p) {
      return '{' + p + '}';
    }).join(', ');
  }

  function syncPlaceholderHint($root, map) {
    var $select = $root.find('.js-push-template-event');
    var eventType = $select.length ? String($select.val() || '') : '';
    if (!eventType) {
      var $hintEl = $root.find('.js-push-template-placeholders');
      eventType = String($hintEl.attr('data-event') || '');
    }
    var text = placeholderHint(eventType, map);
    $root.find('.js-push-template-placeholders').text('Allowed placeholders: ' + text);
  }

  function initFormModal(map) {
    var $backdrop = $('#at-push-template-form-modal');
    if (!$backdrop.length) {
      return;
    }
    if ($backdrop.hasClass('is-open')) {
      $('body').addClass('at-modal-open');
      var $first = $backdrop.find('input, select, textarea, button').filter(':visible').first();
      if ($first.length) {
        $first.focus();
      }
    }

    $backdrop.on('change', '.js-push-template-event', function () {
      syncPlaceholderHint($backdrop, map);
    });
    syncPlaceholderHint($backdrop, map);

    $backdrop.on('click', function (e) {
      if (e.target === $backdrop[0]) {
        var $cancel = $backdrop.find('a.at-btn--ghost[href]').first();
        if ($cancel.length) {
          window.location.href = $cancel.attr('href');
        }
      }
    });
  }

  function initDeleteModal() {
    var $modal = $('#at-push-template-delete-modal');
    if (!$modal.length) {
      return;
    }
    var $form = $('#at-push-template-delete-form');
    var $key = $('#at-push-template-delete-key');
    var $title = $('#at-push-template-delete-title-text');
    var $id = $('#at-push-template-delete-id');
    var $submit = $('#at-push-template-delete-submit');
    var lastFocus = null;
    var deleting = false;

    function openDeleteModal($btn) {
      if (deleting) {
        return;
      }
      var id = String($btn.attr('data-template-id') || '');
      var key = String($btn.attr('data-template-key') || '');
      var title = String($btn.attr('data-template-title') || '');
      if (!id) {
        return;
      }
      $id.val(id);
      $key.text(key || '—');
      $title.text(title || '—');
      $modal.addClass('is-open').attr('aria-hidden', 'false');
      $('body').addClass('at-modal-open');
      lastFocus = document.activeElement;
      $submit.prop('disabled', false);
      $submit.focus();
    }

    function closeDeleteModal() {
      if (deleting) {
        return;
      }
      $modal.removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('at-modal-open');
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
    }

    $(document).on('click', '[data-at-push-template-delete-trigger]', function (e) {
      e.preventDefault();
      openDeleteModal($(this));
    });

    $modal.on('click', '[data-at-push-template-delete-close]', function (e) {
      e.preventDefault();
      closeDeleteModal();
    });

    $modal.on('click', function (e) {
      if (e.target === $modal[0]) {
        closeDeleteModal();
      }
    });

    $form.on('submit', function () {
      deleting = true;
      $submit.prop('disabled', true).text('Deleting…');
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $modal.hasClass('is-open') && !deleting) {
        closeDeleteModal();
      }
    });
  }

  $(function () {
    var map = readPlaceholderMap();
    initFormModal(map);
    initDeleteModal();
  });
}(jQuery));
