(function ($) {
  'use strict';

  function csrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
  }

  function baseUrl() {
    var path = window.location.pathname;
    var idx = path.indexOf('/ajax/');
    if (idx !== -1) {
      return path.substring(0, idx);
    }
    var last = path.lastIndexOf('/');
    return last > 0 ? path.substring(0, last) : '';
  }

  function formatMoney(amount) {
    return '₹' + Math.round(amount).toLocaleString('en-IN');
  }

  function updateHeaderCount(count) {
    var $badge = $('[data-at-cart-count]');
    if (!$badge.length) return;
    if (count > 0) {
      $badge.text(count).removeClass('hidden');
    } else {
      $badge.addClass('hidden');
    }
  }

  window.ApniTokriCart = {
    add: function (productSlug, variantId, qty, done) {
      $.ajax({
        url: baseUrl() + '/ajax/cart/add.php',
        method: 'POST',
        dataType: 'json',
        data: {
          _csrf_token: csrfToken(),
          product_slug: productSlug,
          variant_id: variantId,
          qty: qty || 1
        }
      }).done(function (res) {
        if (res.success && res.data && res.data.count !== undefined) {
          updateHeaderCount(res.data.count);
        }
        if (typeof done === 'function') done(null, res);
      }).fail(function (xhr) {
        var msg = 'Could not add to cart.';
        try {
          var j = JSON.parse(xhr.responseText);
          if (j.error && j.error.message) msg = j.error.message;
        } catch (e) {}
        if (typeof done === 'function') done(new Error(msg));
      });
    }
  };

  function toast(msg, type) {
    if (window.ApniTokri && window.ApniTokri.toast) {
      window.ApniTokri.toast(msg, type);
    }
  }

  function refreshSummary(totals) {
    if (!totals) return;
    $('[data-cart-subtotal]').text(formatMoney(totals.subtotal));
    $('[data-cart-delivery]').text(totals.delivery_fee === 0 ? 'Free' : formatMoney(totals.delivery_fee));
    $('[data-cart-total]').text(formatMoney(totals.total));
    var $free = $('[data-cart-free-delivery]');
    if (totals.amount_to_free_delivery > 0) {
      $free.removeClass('hidden').text('Add ' + formatMoney(totals.amount_to_free_delivery) + ' more for free delivery.');
    } else {
      $free.addClass('hidden');
    }
  }

  $(function () {
    $('.js-cart-qty').on('change', function () {
      var $row = $(this).closest('[data-line-key]');
      $.post(baseUrl() + '/ajax/cart/update.php', {
        _csrf_token: csrfToken(),
        line_key: $row.data('line-key'),
        qty: $(this).val()
      }, function (res) {
        if (res.success) {
          location.reload();
        } else {
          toast(res.error && res.error.message ? res.error.message : 'Update failed', 'error');
        }
      }, 'json');
    });

    $('.js-cart-remove').on('click', function () {
      var key = $(this).closest('[data-line-key]').data('line-key');
      $.post(baseUrl() + '/ajax/cart/remove.php', {
        _csrf_token: csrfToken(),
        line_key: key
      }, function () {
        location.reload();
      }, 'json');
    });

    $('#at-coupon-form').on('submit', function (e) {
      e.preventDefault();
      $.post(baseUrl() + '/ajax/cart/coupon.php', {
        _csrf_token: csrfToken(),
        coupon_code: $('#coupon_code').val()
      }, function (res) {
        if (res.success) {
          location.reload();
        } else {
          toast(res.error && res.error.message ? res.error.message : 'Coupon failed', 'error');
        }
      }, 'json');
    });
  });
})(jQuery);
