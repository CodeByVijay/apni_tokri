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

  /** Extensionless cart AJAX paths (avoid .php 302 redirects that drop POST bodies). */
  function cartAjaxUrl(route) {
    return baseUrl() + '/ajax/cart/' + route;
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
        url: cartAjaxUrl('add'),
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
  window.VeggieTokriCart = window.VeggieTokriCart || window.ApniTokriCart;

  function toast(msg, type) {
    var ui = (window.VeggieTokri && window.VeggieTokri.toast) ? window.VeggieTokri : window.ApniTokri;
    if (ui && ui.toast) {
      ui.toast(msg, type);
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

  function couponAppliedFromTotals(totals) {
    if (!totals) return false;
    var code = String(totals.coupon_code || '').trim();
    var disc = parseFloat(totals.coupon_discount) || 0;
    return code !== '' && disc > 0;
  }

  function hideCouponSummaryRow() {
    var $couponRow = $('[data-cart-coupon-row]');
    if ($couponRow.length) {
      $couponRow.addClass('hidden').attr('hidden', 'hidden');
    }
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

  function refreshDeliveryBanner(totals) {
    var $wrap = $('[data-cart-delivery-banner]');
    var $text = $('[data-cart-delivery-banner-text]');
    var $progress = $('[data-cart-delivery-progress]');
    var $bar = $('[data-cart-delivery-progress-bar]');
    if (!$wrap.length || !totals) return;

    var quoteMsg = String(totals.delivery_quote_message || '').trim();
    var accurate = !!totals.delivery_quote_accurate;
    var serviceable = totals.delivery_serviceable !== false && totals.delivery_serviceable !== 0;
    var toFree = parseFloat(totals.amount_to_free_delivery) || 0;
    var eligible = !!totals.free_delivery_eligible;
    var fee = parseFloat(totals.delivery_fee) || 0;
    var msg = '';
    var tone = 'muted';
    var showProgress = false;
    var progress = 0;

    if (quoteMsg) {
      msg = /select a delivery address/i.test(quoteMsg)
        ? 'Select your delivery address to check delivery charges.'
        : quoteMsg;
    } else if (!accurate) {
      msg = 'Select your delivery address to check delivery charges.';
    } else if (!serviceable) {
      msg = 'Delivery is not available for the selected address.';
    } else if (fee <= 0 && eligible && toFree <= 0) {
      msg = "You've unlocked free delivery";
      tone = 'success';
      showProgress = true;
      progress = 100;
    } else if (eligible && toFree > 0) {
      msg = 'Add ' + formatMoney(toFree) + ' more to unlock free delivery';
      tone = 'progress';
      showProgress = true;
      var threshold = parseFloat(totals.free_delivery_threshold) || 0;
      if (threshold > 0) {
        progress = Math.max(4, Math.min(100, Math.round(((threshold - toFree) / threshold) * 100)));
      }
    } else {
      msg = 'Delivery fee is shown in your order summary once your address is confirmed.';
    }

    if ($text.length) {
      $text.removeClass('text-[var(--at-success)]');
      if (tone === 'success') {
        $text.addClass('text-[var(--at-success)]');
      }
      $text.text(msg);
    }
    if ($progress.length) {
      $progress.toggleClass('hidden', !showProgress);
      $progress.attr('aria-valuenow', String(progress));
    }
    if ($bar.length) {
      $bar.css('width', progress + '%');
    }
  }

  function refreshSummary(totals) {
    if (!totals) return;
    $('[data-cart-subtotal]').text(formatMoney(totals.subtotal));
    var deliveryLabel = '—';
    if (totals.delivery_quote_message) {
      deliveryLabel = '—';
    } else if (totals.delivery_quote_accurate) {
      deliveryLabel = parseFloat(totals.delivery_fee) === 0 ? 'Free' : formatMoney(totals.delivery_fee);
    }
    $('[data-cart-delivery]').text(deliveryLabel);
    $('[data-cart-total]').text(formatMoney(totals.total));
    refreshDeliveryBanner(totals);

    var productSavings = parseFloat(totals.product_savings) || 0;
    var $productSavingsRow = $('[data-cart-product-savings-row]');
    if ($productSavingsRow.length) {
      if (productSavings > 0) {
        $productSavingsRow.removeClass('hidden');
        $('[data-cart-product-savings]').text('−' + formatMoney(productSavings));
      } else {
        $productSavingsRow.addClass('hidden');
        $('[data-cart-product-savings]').text('');
      }
    }

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

    var $couponRow = $('[data-cart-coupon-row]');
    if ($couponRow.length) {
      if (couponAppliedFromTotals(totals)) {
        var couponCode = String(totals.coupon_code).trim();
        var couponDisc = parseFloat(totals.coupon_discount) || 0;
        $couponRow.removeClass('hidden').removeAttr('hidden');
        $('[data-cart-coupon-code]').text(couponCode);
        $('[data-cart-coupon-amount]').text('−' + formatMoney(couponDisc));
        setCouponUiApplied(true, couponCode);
      } else {
        $couponRow.addClass('hidden').attr('hidden', 'hidden');
        $('[data-cart-coupon-code]').text('');
        $('[data-cart-coupon-amount]').text('');
        setCouponUiApplied(false, '');
      }
    }

    var $free = $('[data-cart-free-delivery]');
    if ($free.length) {
      var canShowFreeHint =
        totals.delivery_quote_accurate &&
        totals.delivery_serviceable !== false &&
        totals.free_delivery_eligible &&
        parseFloat(totals.amount_to_free_delivery) > 0;
      if (canShowFreeHint) {
        $free
          .removeClass('hidden')
          .text(
            'Add ' +
              formatMoney(totals.amount_to_free_delivery) +
              ' more (eligible items) for free delivery.'
          );
      } else {
        $free.addClass('hidden');
      }
    }
    refreshQuoteMessage(totals);
    refreshMinimumOrder(totals);
  }

  function refreshMinimumOrder(totals) {
    var $wrap = $('[data-cart-min-order]');
    if (!$wrap.length || !totals) return;
    var enabled = !!totals.min_order_enabled;
    var met = !!totals.min_order_met;
    if (!enabled || met) {
      $wrap.addClass('hidden');
    } else {
      $wrap.removeClass('hidden');
      $wrap.find('[data-cart-min-order-text]').text(String(totals.min_order_message || ''));
      var shortfall = parseFloat(totals.min_order_shortfall) || 0;
      $wrap
        .find('[data-cart-min-order-remaining]')
        .text('Add ' + formatMoney(shortfall) + ' more to proceed to checkout.');
    }
    var block = enabled && !met;
    $('[data-cart-checkout-cta]').each(function () {
      var $a = $(this);
      if (block) {
        $a.attr('href', '#').addClass('pointer-events-none opacity-50').attr('aria-disabled', 'true');
      } else if ($a.attr('data-cart-qty-blocked') !== '1') {
        $a.attr('href', baseUrl() + '/checkout.php').removeClass('pointer-events-none opacity-50').removeAttr('aria-disabled');
      }
    });
  }

  function refreshQuoteMessage(totals) {
    var $msg = $('[data-cart-quote-message]');
    if (!$msg.length || !totals) return;
    var text = String(totals.delivery_quote_message || '').trim();
    if (text) {
      $msg.removeClass('hidden').text(text);
    } else {
      $msg.addClass('hidden').text('');
    }
  }

  function updateCheckoutPayAmounts(totals) {
    if (!totals) return;
    var amt = formatMoney(totals.total);
    $('[data-checkout-pay-amount]').text(amt);
    $('[data-checkout-pay-card-amount]').text(amt);
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
  var qtyBusy = false;

  function applyCartMutationResponse($row, res) {
    if (!res || !res.success || !res.data) return;
    var totals = res.data.totals || null;
    refreshSummary(totals);
    if (typeof res.data.offers_html === 'string') {
      replaceOffers(res.data.offers_html);
    }
    if (res.data.count !== undefined) {
      updateHeaderCount(res.data.count);
    }
    if ($row && $row.length) {
      var qty = parseInt($row.find('.js-cart-qty').val(), 10) || 1;
      var unit = parseFloat($row.data('unit-price')) || 0;
      $row.find('[data-cart-line-total]').text(formatMoney(unit * qty));
    }
  }

  function postCartLine(action, lineKey, extra, $row, prevQty) {
    if (qtyBusy) return;
    qtyBusy = true;
    var $qty = $row.find('.js-cart-qty');
    $qty.prop('disabled', true);
    $row.find('[data-qty-step], .js-cart-remove').prop('disabled', true);

    $.ajax({
      url: cartAjaxUrl(action),
      method: 'POST',
      dataType: 'json',
      data: $.extend({ _csrf_token: csrfToken(), line_key: lineKey }, extra || {})
    }).done(function (res) {
      if (res.success) {
        if (action === 'remove' && (!res.data || res.data.count === 0)) {
          location.reload();
          return;
        }
        applyCartMutationResponse($row, res);
      } else {
        var errMsg = res.error && res.error.message ? res.error.message : 'Update failed';
        if (prevQty !== undefined && $qty.length) {
          $qty.val(prevQty);
        }
        toast(errMsg, 'error');
      }
    }).fail(function (xhr) {
      if (prevQty !== undefined && $qty.length) {
        $qty.val(prevQty);
      }
      toast(parseAjaxError(xhr, 'Update failed'), 'error');
    }).always(function () {
      qtyBusy = false;
      $qty.prop('disabled', false);
      $row.find('[data-qty-step], .js-cart-remove').prop('disabled', false);
    });
  }

  function setOffersBusy(busy) {
    $('[data-at-offers] [data-at-offer-apply], [data-at-offers] [data-at-offer-remove]')
      .prop('disabled', busy)
      .attr('aria-busy', busy ? 'true' : null);
    $('[data-at-offers]').toggleClass('is-busy', busy);
  }

  /** Swap the server-rendered offers block without a reload; keep scroll position. */
  function replaceOffers(html) {
    var y = window.pageYOffset;
    var sheetOpen = $('[data-at-offers-sheet].is-open').length > 0;
    var $wrap = $('[data-at-offers-wrap]');
    if (!$wrap.length) return;
    $('body > [data-at-offers-sheet]').remove();
    $wrap.html(html);
    if (sheetOpen) {
      // Applying from the sheet closes it; the customer sees the result in the summary.
      $('body').removeClass('overflow-hidden');
    }
    window.scrollTo(0, y);
  }

  function postCoupon(payload, done) {
    if (couponBusy) return;
    couponBusy = true;
    var $btn = $('[data-at-coupon-apply]');
    var prevLabel = $btn.text();
    $btn.prop('disabled', true).attr('aria-busy', 'true').text('Applying…');
    setOffersBusy(true);
    showCouponFeedback('', false);

    $.ajax({
      url: cartAjaxUrl('coupon'),
      method: 'POST',
      dataType: 'json',
      data: $.extend({ _csrf_token: csrfToken() }, payload)
    }).done(function (res) {
      if (res.success) {
        var totals = res.data && res.data.totals ? res.data.totals : null;
        refreshSummary(totals);
        if (res.data && typeof res.data.offers_html === 'string') {
          replaceOffers(res.data.offers_html);
        }
        var okMsg = res.message || 'Coupon applied.';
        if (couponAppliedFromTotals(totals)) {
          okMsg = 'Coupon ' + String(totals.coupon_code).trim() + ' applied — you save ' + formatMoney(totals.coupon_discount) + '.';
        }
        showCouponFeedback(okMsg, false);
        toast(okMsg, 'success');
        if (typeof done === 'function') done(null, res);
      } else {
        var errMsg = res.error && res.error.message ? res.error.message : 'Coupon could not be applied.';
        showCouponFeedback(errMsg, true);
        toast(errMsg, 'error');
        hideCouponSummaryRow();
        if (typeof done === 'function') done(new Error(errMsg));
      }
    }).fail(function (xhr) {
      var errMsg = parseAjaxError(xhr, 'Could not apply coupon. Check your connection and try again.');
      showCouponFeedback(errMsg, true);
      toast(errMsg, 'error');
      hideCouponSummaryRow();
      if (typeof done === 'function') done(new Error(errMsg));
    }).always(function () {
      couponBusy = false;
      $btn.prop('disabled', false).removeAttr('aria-busy').text(prevLabel);
      setOffersBusy(false);
    });
  }

  $(function () {
    $('.js-cart-qty').on('focus', function () {
      $(this).data('prev-qty', $(this).val());
    });
    $('.js-cart-qty').on('change', function () {
      var $row = $(this).closest('[data-line-key]');
      var prevQty = $(this).data('prev-qty');
      var nextQty = $(this).val();
      if (prevQty !== undefined && String(nextQty) === String(prevQty)) {
        return;
      }
      var cap = parseInt($row.data('qty-cap'), 10) || parseInt($(this).attr('max'), 10) || 0;
      var floor = parseInt($row.data('qty-min'), 10) || parseInt($(this).attr('min'), 10) || 1;
      var requested = parseInt(nextQty, 10) || floor;
      if (requested < floor) {
        var minMsg = String($row.attr('data-qty-decrease-toast') || '').trim();
        if (minMsg) {
          toast(minMsg, 'error');
        }
        $(this).val(prevQty !== undefined ? prevQty : floor);
        return;
      }
      if (cap > 0 && requested > cap) {
        var limitMsg = String($row.attr('data-qty-limit-toast') || '').trim();
        if (limitMsg) {
          toast(limitMsg, 'error');
        }
        $(this).val(prevQty !== undefined ? prevQty : cap);
        return;
      }
      postCartLine('update', $row.data('line-key'), { qty: nextQty }, $row, prevQty);
      $(this).data('prev-qty', nextQty);
    });

    $('.js-cart-remove').on('click', function () {
      var $row = $(this).closest('[data-line-key]');
      postCartLine('remove', $row.data('line-key'), {}, $row);
    });

    $('#at-coupon-form').on('submit', function (e) {
      e.preventDefault();
      postCoupon({ coupon_code: $('#coupon_code').val() });
    });

    // Offer cards (delegated: the block is re-rendered after apply/remove).
    $(document).on('click', '[data-at-offer-apply]', function () {
      var code = String($(this).data('code') || '');
      if (!code) return;
      $('#coupon_code').val(code);
      postCoupon({ coupon_code: code });
    });
    $(document).on('click', '[data-at-offer-remove]', function () {
      postCoupon({ remove: '1' });
    });

    var lastFocus = null;
    function openSheet() {
      var $s = $('[data-at-offers-sheet]');
      if (!$s.length) return;
      lastFocus = document.activeElement;
      // Fixed-position sheet must not live inside an animated/transformed ancestor.
      if (!$s.parent().is('body')) $s.appendTo('body');
      $s.addClass('is-open').attr('aria-hidden', 'false');
      $('body').addClass('overflow-hidden');
      $s.find('[data-at-offers-close]').trigger('focus');
    }
    function closeSheet() {
      $('[data-at-offers-sheet]').removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('overflow-hidden');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    $(document).on('click', '[data-at-offers-open]', openSheet);
    $(document).on('click', '[data-at-offers-close]', closeSheet);
    $(document).on('click', '[data-at-offers-sheet]', function (e) {
      if (e.target === this) closeSheet();
    });
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $('[data-at-offers-sheet].is-open').length) closeSheet();
    });
    // Applying from inside the sheet closes it once the request is made.
    $(document).on('click', '[data-at-offers-sheet] [data-at-offer-apply], [data-at-offers-sheet] [data-at-offer-remove]', function () {
      closeSheet();
    });

    $('[data-at-coupon-remove]').on('click', function () {
      postCoupon({ remove: '1' }, function (err) {
        if (!err) {
          showCouponFeedback('Coupon removed.', false);
        }
      });
    });

    function initCheckoutQuote() {
      var cfg = window.AT_CHECKOUT;
      if (!cfg || !cfg.quoteUrl) return;

      var quoteSeq = 0;
      var quoteBusy = false;
      var $summary = $('[data-checkout-summary]');
      var $alert = $('[data-checkout-quote-error]');

      function syncCheckoutPayState() {
        var $btn = $('#checkout-proceed-pay');
        if (!$btn.length || $btn.attr('data-at-checkout-hard-disabled') === '1') return;
        var gatewayOk = $btn.attr('data-at-gateway-ready') === '1';
        var quoteOk = $btn.attr('data-at-quote-ready') === '1';
        var $terms = $('#checkout-form input[name="terms"]');
        var termsOk = !$terms.length || $terms.prop('checked');
        $btn.prop('disabled', !(gatewayOk && quoteOk && termsOk && !quoteBusy));
      }

      function setQuoteLoading(loading) {
        quoteBusy = loading;
        if ($summary.length) {
          $summary.attr('aria-busy', loading ? 'true' : 'false');
          $summary.toggleClass('opacity-60', loading);
        }
        syncCheckoutPayState();
      }

      window.AT_CHECKOUT_SYNC_PAY = syncCheckoutPayState;

      function applyQuoteResponse(res, seq) {
        if (seq !== quoteSeq) return;
        setQuoteLoading(false);
        if (!res || !res.success || !res.data || !res.data.totals) {
          var failMsg = (res && res.error && res.error.message) || 'Could not update delivery quote.';
          if ($alert.length) $alert.removeClass('hidden').text(failMsg);
          $('#checkout-proceed-pay').attr('data-at-quote-ready', '0');
          syncCheckoutPayState();
          return;
        }
        var totals = res.data.totals;
        var checkout = res.data.checkout || {};
        refreshSummary(totals);
        updateCheckoutPayAmounts(totals);
        var $minAlert = $('[data-checkout-min-order]');
        if ($minAlert.length) {
          if (totals.min_order_enabled && !totals.min_order_met) {
            var minMsg = String(totals.min_order_message || '');
            var minShort = parseFloat(totals.min_order_shortfall) || 0;
            if (minShort > 0) {
              minMsg += ' Add ' + formatMoney(minShort) + ' more to proceed to checkout.';
            }
            $minAlert.removeClass('hidden').text(minMsg);
          } else {
            $minAlert.addClass('hidden').text('');
          }
        }
        if ($alert.length) {
          if (checkout.quote_message) {
            $alert.removeClass('hidden').text(checkout.quote_message);
          } else {
            $alert.addClass('hidden').text('');
          }
        }
        $('#checkout-proceed-pay').attr('data-at-quote-ready', checkout.can_pay ? '1' : '0');
        syncCheckoutPayState();
      }

      function selectedAddressId() {
        var $r = $('input[name="address_id"][form="checkout-form"]:checked');
        return $r.length ? String($r.val()) : '';
      }

      function selectedSlot() {
        var $r = $('input[name="delivery_slot"][form="checkout-form"]:checked');
        if ($r.length) return String($r.val());
        var $h = $('input[name="delivery_slot"][form="checkout-form"][type="hidden"]');
        return $h.length ? String($h.val()) : 'auto';
      }

      function selectedDeliveryDate() {
        var $h = $('#checkout-delivery-date');
        if ($h.length && $h.val()) return String($h.val());
        var $r = $('input[name="delivery_slot"][form="checkout-form"]:checked');
        if ($r.length && $r.attr('data-delivery-date')) return String($r.attr('data-delivery-date'));
        return '';
      }

      function fetchQuote() {
        var addressId = selectedAddressId();
        if (!addressId) {
          $('#checkout-proceed-pay').attr('data-at-quote-ready', '0');
          syncCheckoutPayState();
          return;
        }
        var seq = ++quoteSeq;
        setQuoteLoading(true);
        var postData = {
          _csrf_token: cfg.csrf || csrfToken(),
          address_id: addressId,
          delivery_slot: selectedSlot()
        };
        var slotDate = selectedDeliveryDate();
        if (slotDate) postData.delivery_date = slotDate;
        $.ajax({
          url: cfg.quoteUrl,
          method: 'POST',
          dataType: 'json',
          data: postData
        })
          .done(function (res) {
            applyQuoteResponse(res, seq);
          })
          .fail(function (xhr) {
            if (seq !== quoteSeq) return;
            setQuoteLoading(false);
            if ($alert.length) $alert.removeClass('hidden').text(parseAjaxError(xhr, 'Could not update delivery quote.'));
            $('#checkout-proceed-pay').attr('data-at-quote-ready', '0');
            syncCheckoutPayState();
          });
      }

      $(document).on('change', 'input[name="address_id"][form="checkout-form"]', fetchQuote);
      $(document).on('change', 'input[name="delivery_slot"][form="checkout-form"]', fetchQuote);
      window.AT_CHECKOUT_REFRESH_QUOTE = fetchQuote;
      syncCheckoutPayState();
    }

    initCheckoutQuote();
  });
})(jQuery);
