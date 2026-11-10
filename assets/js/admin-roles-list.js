/**
 * Roles list — custom role delete confirmation modal.
 */
(function ($) {
  'use strict';

  $(function () {
    var $modal = $('#at-admin-role-delete-modal');
    if (!$modal.length) {
      return;
    }
    var $form = $('#at-admin-role-delete-form');
    var $name = $('#at-admin-role-delete-name');
    var $slug = $('#at-admin-role-delete-slug');
    var $id = $('#at-admin-role-delete-id');
    var $submit = $('#at-admin-role-delete-submit');
    var lastFocus = null;
    var deleting = false;

    function openModal($btn) {
      if (deleting) {
        return;
      }
      var id = String($btn.attr('data-role-id') || '');
      if (!id) {
        return;
      }
      $id.val(id);
      $name.text(String($btn.attr('data-role-name') || '—'));
      $slug.text(String($btn.attr('data-role-slug') || '—'));
      $modal.addClass('is-open').attr('aria-hidden', 'false');
      $('body').addClass('at-modal-open');
      lastFocus = document.activeElement;
      $submit.prop('disabled', false).text('Delete role');
      $submit.focus();
    }

    function closeModal() {
      if (deleting) {
        return;
      }
      $modal.removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('at-modal-open');
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
    }

    $(document).on('click', '[data-at-admin-role-delete-trigger]', function (e) {
      e.preventDefault();
      openModal($(this));
    });

    $modal.on('click', '[data-at-admin-role-delete-close]', function (e) {
      e.preventDefault();
      closeModal();
    });

    $modal.on('click', function (e) {
      if (e.target === $modal[0]) {
        closeModal();
      }
    });

    $form.on('submit', function () {
      deleting = true;
      $submit.prop('disabled', true).text('Deleting…');
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $modal.hasClass('is-open') && !deleting) {
        closeModal();
      }
    });
  });
}(jQuery));
