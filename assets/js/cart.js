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
    var n = Number(amount) || 0;
    return '₹' + Math.round(n).toLocaleString('en-IN');
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

  function showCouponFeedback(message, isError) {
    var $fb = $('[data-at-coupon-feedback]');
    if (!$fb.length) return;
    if (!message) {
      $fb.addClass('hidden').removeClass('text-red-600 text-[var(--at-success)]').text('');
      return;
    }
    $fb.removeClass('hidden').toggleClass('text-red-600', !!isError).toggleClass('text-[var(--at-success)]', !isError).text(message);
  }

  function setCouponUiApplied(applied, code) {
    var $remove = $('[data-at-coupon-remove]');
    var $input = $('#coupon_code');
    if (applied && code) {
      $remove.removeClass('hidden');
      $input.val(code);
    } else {
      $remove.addClass('hidden');
      if (!applied) {
        $input.val('');
      }
    }
  }

  function refreshSummary(totals) {
    if (!totals) return;
    $('[data-cart-subtotal]').text(formatMoney(totals.subtotal));
    $('[data-cart-delivery]').text(totals.delivery_fee === 0 ? 'Free' : formatMoney(totals.delivery_fee));
    $('[data-cart-total]').text(formatMoney(totals.total));

    var offerDisc = parseFloat(totals.offer_discount) || 0;
    var $offerRow = $('[data-cart-offer-row]');
    if ($offerRow.length) {
      if (offerDisc > 0) {
        $offerRow.removeClass('hidden');
        if (totals.offer_label) {
          $offerRow.find('[data-cart-offer-label]').text(totals.offer_label);
        }
        $('[data-cart-offer-amount]').text('−' + formatMoney(offerDisc));
      } else {
        $offerRow.addClass('hidden');
      }
    }

    var couponDisc = parseFloat(totals.coupon_discount) || 0;
    var couponCode = totals.coupon_code || '';
    var $couponRow = $('[data-cart-coupon-row]');
    if ($couponRow.length) {
      if (couponDisc > 0 && couponCode) {
        $couponRow.removeClass('hidden');
        $('[data-cart-coupon-code]').text(couponCode);
        $('[data-cart-coupon-amount]').text('−' + formatMoney(couponDisc));
        setCouponUiApplied(true, couponCode);
      } else {
        $couponRow.addClass('hidden');
        setCouponUiApplied(false, '');
      }
    }

    var $free = $('[data-cart-free-delivery]');
    if ($free.length) {
      if (totals.amount_to_free_delivery > 0) {
        $free.removeClass('hidden').text('Add ' + formatMoney(totals.amount_to_free_delivery) + ' more for free delivery.');
      } else {
        $free.addClass('hidden');
      }
    }
  }

  function parseAjaxError(xhr, fallback) {
    var msg = fallback || 'Something went wrong. Please try again.';
    try {
      var j = JSON.parse(xhr.responseText);
      if (j.error && j.error.message) msg = j.error.message;
    } catch (e) {}
    return msg;
  }

  var couponBusy = false;

  function postCoupon(payload, done) {
    if (couponBusy) return;
    couponBusy = true;
    var $btn = $('[data-at-coupon-apply]');
    var prevLabel = $btn.text();
    $btn.prop('disabled', true).attr('aria-busy', 'true').text('Applying…');
    showCouponFeedback('', false);

    $.ajax({
      url: baseUrl() + '/ajax/cart/coupon.php',
      method: 'POST',
      dataType: 'json',
      data: $.extend({ _csrf_token: csrfToken() }, payload)
    }).done(function (res) {
      if (res.success) {
        var totals = res.data && res.data.totals ? res.data.totals : null;
        refreshSummary(totals);
        showCouponFeedback(res.message || 'Coupon applied.', false);
        toast(res.message || 'Coupon applied.', 'success');
        if (typeof done === 'function') done(null, res);
      } else {
        var errMsg = res.error && res.error.message ? res.error.message : 'Coupon could not be applied.';
        showCouponFeedback(errMsg, true);
        toast(errMsg, 'error');
        if (typeof done === 'function') done(new Error(errMsg));
      }
    }).fail(function (xhr) {
      var errMsg = parseAjaxError(xhr, 'Could not apply coupon. Check your connection and try again.');
      showCouponFeedback(errMsg, true);
      toast(errMsg, 'error');
      if (typeof done === 'function') done(new Error(errMsg));
    }).always(function () {
      couponBusy = false;
      $btn.prop('disabled', false).removeAttr('aria-busy').text(prevLabel);
    });
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
      }, 'json').fail(function (xhr) {
        toast(parseAjaxError(xhr, 'Update failed'), 'error');
      });
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
      postCoupon({ coupon_code: $('#coupon_code').val() });
    });

    $('[data-at-coupon-remove]').on('click', function () {
      postCoupon({ remove: '1' }, function (err) {
        if (!err) {
          showCouponFeedback('Coupon removed.', false);
        }
      });
    });
  });
})(jQuery);
