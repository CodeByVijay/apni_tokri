(function ($) {
  'use strict';

  function statusLabel(s) {
    if (!s) return '—';
    var t = String(s).toLowerCase();
    if (t === 'published') return 'Published';
    if (t === 'draft') return 'Draft';
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function initProductsBulkList() {
    var $form = $('#at-products-bulk-form');
    if (!$form.length) return;

    var $selectAll = $('#at-products-select-all');
    var $rowChecks = $('.js-product-bulk-select');
    var $apply = $('#at-products-bulk-apply');
    var $exportBtn = $('#at-products-bulk-export');
    var $count = $('#at-products-bulk-count');
    var exportBase = $form.data('exportUrl') || '';
    var $target = $('#bulk_status');
    var $modal = $('#at-products-bulk-confirm');
    var $summary = $('#at-products-bulk-confirm-summary');
    var $list = $('#at-products-bulk-confirm-list');
    var $confirmSubmit = $('#at-products-bulk-confirm-submit');

    function selectedChecks() {
      return $rowChecks.filter(':checked');
    }

    function syncUi() {
      var $sel = selectedChecks();
      var n = $sel.length;
      $count.text(n === 1 ? '1 product selected' : n + ' products selected');
      $apply.prop('disabled', n === 0);
      if ($exportBtn.length) {
        $exportBtn.prop('disabled', n === 0);
      }
      if (!$selectAll.length) return;
      var total = $rowChecks.length;
      $selectAll.prop('checked', total > 0 && n === total);
      $selectAll.prop('indeterminate', n > 0 && n < total);
    }

    function openModal() {
      var $sel = selectedChecks();
      if (!$sel.length) return;
      var target = ($target.val() || 'draft').toLowerCase();
      var targetLabel = statusLabel(target);
      var items = [];
      $sel.each(function () {
        var $cb = $(this);
        items.push({
          name: $cb.data('product-name') || ('ID ' + $cb.val()),
          status: String($cb.data('product-status') || '').toLowerCase()
        });
      });
      $summary.text(
        'You are about to update ' + items.length + ' product(s) to ' + targetLabel + '.'
      );
      var html = '';
      items.forEach(function (it) {
        html += '<li><strong>' + $('<div>').text(it.name).html() + '</strong>: ' +
          statusLabel(it.status) + ' → ' + targetLabel + '</li>';
      });
      $list.html(html);
      $modal.addClass('is-open').attr('aria-hidden', 'false');
      $('body').addClass('at-modal-open');
      $confirmSubmit.focus();
    }

    function closeModal() {
      $modal.removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('at-modal-open');
    }

    $rowChecks.on('change', syncUi);
    $selectAll.on('change', function () {
      var on = $(this).prop('checked');
      $rowChecks.prop('checked', on);
      syncUi();
    });

    $apply.on('click', function () {
      if (selectedChecks().length) openModal();
    });

    $exportBtn.on('click', function () {
      if (!exportBase) return;
      var ids = [];
      selectedChecks().each(function () {
        ids.push(String($(this).val()));
      });
      if (!ids.length) return;
      var url = exportBase + '?scope=selected&product_ids=' + encodeURIComponent(ids.join(','));
      window.location.href = url;
    });

    $modal.on('click', '[data-at-bulk-confirm-close]', closeModal);
    $modal.on('click', function (e) {
      if (e.target === $modal[0]) closeModal();
    });

    $confirmSubmit.on('click', function () {
      closeModal();
      $form.trigger('submit');
    });

    $('#at-products-filter-form').on('submit', function () {
      $rowChecks.prop('checked', false);
      $selectAll.prop('checked', false).prop('indeterminate', false);
    });

    $('.at-admin-pager').find('a[href*="page="], select[name="per_page"]').on('click change', function () {
      $rowChecks.prop('checked', false);
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $modal.hasClass('is-open')) {
        closeModal();
      }
    });

    syncUi();
  }

  function initProductDeleteModal() {
    var $modal = $('#at-product-delete-modal');
    if (!$modal.length) {
      return;
    }
    var $message = $('#at-product-delete-modal-message');
    var $submit = $('#at-product-delete-modal-submit');
    var $dialog = $modal.find('.at-modal');
    var pendingForm = null;
    var deleting = false;
    var lastFocus = null;

    function openModal($form) {
      if (!$form || !$form.length) {
        return;
      }
      pendingForm = $form;
      var name = String($form.attr('data-product-name') || '').trim() || 'this product';
      $message.html(
        'Are you sure you want to delete &ldquo;' + $('<div>').text(name).html()
          + '&rdquo;? This action cannot be undone.'
      );
      $modal.addClass('is-open').attr('aria-hidden', 'false');
      $('body').addClass('at-modal-open');
      deleting = false;
      $submit.prop('disabled', false).text('Delete product');
      lastFocus = document.activeElement;
      $submit.focus();
    }

    function closeModal() {
      if (deleting) {
        return;
      }
      $modal.removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('at-modal-open');
      pendingForm = null;
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
    }

    $(document).on('click', '[data-at-product-delete-trigger]', function (e) {
      e.preventDefault();
      if (deleting) {
        return;
      }
      openModal($(this).closest('[data-at-product-delete-form]'));
    });

    $dialog.on('click', '[data-at-product-delete-close]', function (e) {
      e.preventDefault();
      closeModal();
    });
    $modal.on('click', function (e) {
      if (e.target === $modal[0]) {
        closeModal();
      }
    });

    $submit.on('click', function () {
      if (!pendingForm || !pendingForm.length || deleting) {
        return;
      }
      deleting = true;
      $submit.prop('disabled', true).text('Deleting...');
      pendingForm.trigger('submit');
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $modal.hasClass('is-open') && !deleting) {
        closeModal();
      }
    });
  }

  $(function () {
    initProductsBulkList();
    initProductDeleteModal();
  });
}(jQuery));
