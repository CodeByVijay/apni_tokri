/**
 * Apni Tokri — UI behaviours (jQuery): nav, modals, toast, qty stepper, search suggestions
 */
(function ($) {
  'use strict';

  function trapFocus($container) {
    var $focusable = $container.find(
      'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
    );
    if (!$focusable.length) {
      return;
    }
    var first = $focusable[0];
    var last = $focusable[$focusable.length - 1];
    $container.on('keydown.atTrap', function (e) {
      if (e.key !== 'Tab') {
        return;
      }
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });
  }

  function openMobileNav() {
    var $nav = $('#at-mobile-nav');
    $nav.addClass('is-open').attr('aria-hidden', 'false');
    $('#at-menu-toggle').attr('aria-expanded', 'true');
    $('body').addClass('overflow-hidden');
    trapFocus($nav.find('.at-mobile-nav__panel'));
    $nav.find('.at-mobile-nav__panel a').first().focus();
  }

  function closeMobileNav() {
    var $nav = $('#at-mobile-nav');
    $nav.removeClass('is-open').attr('aria-hidden', 'true');
    $('#at-menu-toggle').attr('aria-expanded', 'false');
    $('body').removeClass('overflow-hidden');
    $nav.find('.at-mobile-nav__panel').off('keydown.atTrap');
    $('#at-menu-toggle').focus();
  }

  function openModal(id) {
    var $backdrop = $(id);
    if (!$backdrop.length) {
      return;
    }
    $backdrop.addClass('is-open').attr('aria-hidden', 'false');
    trapFocus($backdrop.find('.at-modal'));
    $backdrop.find('[data-at-modal-close]').first().focus();
  }

  function closeModal($backdrop) {
    $backdrop.removeClass('is-open').attr('aria-hidden', 'true');
    $backdrop.find('.at-modal').off('keydown.atTrap');
  }

  $(function () {
    $('#at-menu-toggle').on('click', function () {
      var expanded = $(this).attr('aria-expanded') === 'true';
      if (expanded) {
        closeMobileNav();
      } else {
        openMobileNav();
      }
    });

    $('#at-mobile-nav [data-at-nav-close]').on('click', closeMobileNav);

    // Mobile category accordion (touch-friendly; no hover dependency).
    $(document).on('click', '[data-at-macc-toggle]', function () {
      var $btn = $(this);
      var open = $btn.attr('aria-expanded') === 'true';
      $btn.attr('aria-expanded', open ? 'false' : 'true');
      $('#' + $btn.attr('aria-controls')).prop('hidden', open);
    });

    // Desktop category menu: keep dropdowns inside the viewport (flip left when they would overflow right).
    function positionDnavMenu($item) {
      var $menu = $item.children('.at-dnav__menu');
      if (!$menu.length) {
        return;
      }
      $item.removeClass('is-flip');
      var rect = $menu[0].getBoundingClientRect();
      if (rect.right > window.innerWidth - 8) {
        $item.addClass('is-flip');
      }
    }
    $('[data-at-dnav]').on('mouseenter focusin', '.at-dnav__item.has-children', function () {
      positionDnavMenu($(this));
    });
    // Keyboard / touch: Enter on a focused parent keeps it open via :focus-within; Escape closes.
    $('[data-at-dnav]').on('keydown', function (e) {
      if (e.key === 'Escape') {
        var a = document.activeElement;
        if (a && a.blur) {
          a.blur();
        }
      }
    });
    // Touch laptops/tablets at desktop width: first tap on a parent opens its menu, second follows the link.
    $('[data-at-dnav]').on('touchstart', '.at-dnav__item.has-children > .at-dnav__link', function (e) {
      var $item = $(this).parent();
      if (!$item.hasClass('is-open')) {
        e.preventDefault();
        $item.siblings('.is-open').removeClass('is-open');
        $item.addClass('is-open');
        positionDnavMenu($item);
      }
    });
    $(document).on('touchstart', function (e) {
      if (!$(e.target).closest('[data-at-dnav]').length) {
        $('[data-at-dnav] .is-open').removeClass('is-open');
      }
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') {
        if ($('#at-mobile-nav').hasClass('is-open')) {
          closeMobileNav();
        }
        $('.at-modal-backdrop.is-open').each(function () {
          closeModal($(this));
        });
      }
    });

    $('[data-at-open-modal]').on('click', function (e) {
      e.preventDefault();
      openModal($(this).data('at-open-modal'));
    });

    $(document).on('click', '[data-at-modal-close]', function () {
      closeModal($(this).closest('.at-modal-backdrop'));
    });

    $('.at-modal-backdrop').on('click', function (e) {
      if (e.target === this) {
        closeModal($(this));
      }
    });
  });

  /* ---------- Toast (shared) ---------- */
  var toastTimer = null;
  function toast(msg, type) {
    var $t = $('#at-toast');
    if (!$t.length) {
      $t = $('<div id="at-toast" role="status" aria-live="polite"></div>');
      $('body').append($t);
    }
    var icon = type === 'error' ? '!' : '✓';
    $t.empty()
      .append($('<span class="at-toast__icon" aria-hidden="true"></span>').text(icon))
      .append($('<span></span>').text(msg))
      .toggleClass('is-error', type === 'error')
      .addClass('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { $t.removeClass('is-visible'); }, 2600);
  }
  window.ApniTokri = window.ApniTokri || {};
  window.ApniTokri.toast = toast;

  /* ---------- Quantity stepper buttons ---------- */
  function initQtySteppers() {
    $(document).on('click', '[data-qty-step]', function () {
      var $wrap = $(this).closest('.at-qty');
      var $field = $wrap.find('input, select').first();
      var step = parseInt($(this).data('qty-step'), 10) || 0;
      var min = parseInt($field.attr('min') || '1', 10);
      var max = parseInt($field.attr('max') || '99', 10);
      var next = Math.min(max, Math.max(min, (parseInt($field.val(), 10) || min) + step));
      if ($field.is('select') && !$field.find('option[value="' + next + '"]').length) return;
      $field.val(next).trigger('change');
    });
  }

  /* ---------- Search suggestions ---------- */
  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function initSearchSuggest() {
    var index = [];
    try { index = JSON.parse($('#at-search-index').text() || '[]'); } catch (e) { index = []; }
    if (!index.length) return;

    $('[data-at-search]').each(function () {
      var $form = $(this);
      var $input = $form.find('input[type=search]');
      var $box = $form.find('.at-suggest');
      var active = -1;

      function close() { $box.removeClass('is-open').empty(); active = -1; }

      function render(q) {
        var term = $.trim(q).toLowerCase();
        if (term.length < 1) { close(); return; }
        var hits = index.filter(function (p) { return p.k.indexOf(term) !== -1; }).slice(0, 6);
        var html = '';
        if (hits.length) {
          html += '<div class="at-suggest__label">Products</div>';
          var re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
          hits.forEach(function (p) {
            html += '<a href="' + escapeHtml(p.u) + '" role="option"><img src="' + escapeHtml(p.i) + '" alt="" loading="lazy">' +
              '<span class="flex-1 min-w-0"><span class="block font-semibold truncate">' +
              escapeHtml(p.n).replace(re, '<mark>$1</mark>') + '</span><small>' + escapeHtml(p.c) + '</small></span>' +
              '<span class="font-bold text-sm">' + escapeHtml(p.p) + '</span></a>';
          });
          html += '<a href="' + escapeHtml($form.attr('action')) + '?q=' + encodeURIComponent($.trim(q)) + '" class="!justify-between border-t border-[var(--at-line)] font-bold text-[var(--at-green)]"><span>See all results for “' + escapeHtml($.trim(q)) + '”</span><span>→</span></a>';
        } else {
          html = '<div class="at-suggest__empty">No match for “' + escapeHtml($.trim(q)) + '”. Try “tomato”, “apple” or “pickle”.</div>';
        }
        $box.html(html).addClass('is-open');
        active = -1;
      }

      $input.on('input focus', function () { render($input.val()); });
      $input.on('keydown', function (e) {
        var $items = $box.find('a');
        if (!$box.hasClass('is-open') || !$items.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          active = (active + (e.key === 'ArrowDown' ? 1 : -1) + $items.length) % $items.length;
          $items.removeClass('is-active').eq(active).addClass('is-active');
        } else if (e.key === 'Enter' && active > -1) {
          e.preventDefault();
          window.location.href = $items.eq(active).attr('href');
        } else if (e.key === 'Escape') {
          close();
        }
      });
      $(document).on('click', function (e) {
        if (!$form[0].contains(e.target)) close();
      });
    });
  }

  /* ---------- Sticky buy bar on product page ---------- */
  function initStickyBuy() {
    var $bar = $('#at-sticky-buy');
    var $anchor = $('#at-add-cart');
    if (!$bar.length || !$anchor.length || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(function (entries) {
      $bar.toggleClass('is-visible', !entries[0].isIntersecting);
    }, { rootMargin: '0px 0px -70px 0px' }).observe($anchor[0]);
    $bar.find('[data-sticky-add]').on('click', function () { $anchor.trigger('click'); });
  }

  function initDeliveryPinCheck() {
    var $modal = $('#at-delivery-modal');
    var $btn = $('#at-pin-check-btn');
    var $input = $('#at-pincode');
    var $result = $('#at-pin-result');
    var $resultDetail = $('#at-pin-result-detail');
    var $mapAction = $('#at-pin-map-action');
    var $pinStep = $('[data-at-delivery-pin-step]');
    var $mapStep = $('[data-at-delivery-map-step]');
    var $heroForm = $('#at-hero-pin-form');
    var $heroInput = $('#hero-pincode');
    var $heroBtn = $('#hero-pin-check');
    var $heroResult = $('#hero-pin-result');
    if (!$modal.length) return;

    var currentPin = ($modal.attr('data-current-pin') || '').replace(/\D/g, '');
    var inFlight = false;
    var lastNeedsMap = false;

    function baseUrl() {
      var path = window.location.pathname;
      var idx = path.indexOf('/ajax/');
      if (idx !== -1) return path.substring(0, idx);
      var last = path.lastIndexOf('/');
      return last > 0 ? path.substring(0, last) : '';
    }

    function syncInputs(pin) {
      if ($input.length) $input.val(pin);
      if ($heroInput.length) $heroInput.val(pin);
    }

    function updateHeader(pin) {
      $('[data-at-loc-pin]').text(' \u00b7 ' + pin);
    }

    function clearModalFeedback() {
      $result.text('').css('color', '');
      $resultDetail.addClass('hidden').text('');
      $mapAction.addClass('hidden');
      lastNeedsMap = false;
    }

    function showModalFeedback(data, loading) {
      if (loading) {
        clearModalFeedback();
        $result.text('Checking\u2026').css('color', '');
        return;
      }
      var ok = !!(data && data.serviceable);
      var needsMap = !!(data && data.requires_map_pin);
      lastNeedsMap = needsMap;
      $result.text((data && data.message) || '').css('color', ok ? 'var(--at-success)' : 'var(--at-danger)');
      if (needsMap && data.message_detail) {
        $resultDetail.removeClass('hidden').text(data.message_detail);
        $mapAction.removeClass('hidden');
      } else {
        $resultDetail.addClass('hidden').text('');
        $mapAction.addClass('hidden');
      }
    }

    function showHero(pin, state, payload) {
      if (!$heroResult.length) return;
      payload = payload || {};
      var ok = state === 'ok';
      var err = state === 'invalid';
      var needsMap = !!payload.requires_map_pin;
      $heroResult.removeClass('hidden is-ok is-bad').addClass(ok ? 'is-ok' : (state === 'loading' ? '' : 'is-bad'));
      var title = state === 'loading' ? 'Checking delivery\u2026' : (ok ? 'Delivery available' : (err ? 'Invalid pincode' : (needsMap ? 'Location needed on map' : 'Delivery not available in this area')));
      $heroResult.find('.at-pin-result__status').text(title + (pin && !err && state !== 'loading' ? ' \u2014 ' + pin : ''));
      var msg = payload.message || '';
      if (needsMap && payload.message_detail) {
        msg = msg + (msg ? ' ' : '') + payload.message_detail;
      }
      $heroResult.find('.at-pin-result__msg').text(state === 'loading' ? '' : msg);
      $heroResult.find('[data-pin-continue]').toggleClass('hidden', !ok);
      $heroResult.find('[data-pin-map-select]').toggleClass('hidden', !needsMap || state === 'loading');
      $heroResult.find('[data-pin-change]').toggleClass('hidden', state === 'loading');
    }

    function showPinStep() {
      $pinStep.removeClass('hidden');
      $mapStep.addClass('hidden');
    }

    function mapCoordsPayload() {
      var $f = $('#at-delivery-map-form');
      if (!$f.length) return {};
      var lat = ($f.find('[name="latitude"]').val() || '').trim();
      var lng = ($f.find('[name="longitude"]').val() || '').trim();
      if (lat === '' || lng === '') return {};
      return { latitude: lat, longitude: lng };
    }

    function ensureDeliveryMapReady(done) {
      if (window.AtAddressMap && window.AtAddressMap.init) {
        window.AtAddressMap.init();
        $(document).trigger('at:address-map-show');
        if (typeof done === 'function') done();
        return;
      }
      if (typeof done === 'function') done();
    }

    function focusPageAddressMap() {
      var $loc = $('[data-at-address-form]:visible [data-addr-step="location"]').first();
      if (!$loc.length) return false;
      closeModal($modal);
      $loc[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
      ensureDeliveryMapReady(function () {
        $loc.find('[data-addr-gps], [data-addr-map]').first().trigger('focus');
      });
      return true;
    }

    function openDeliveryMapStep() {
      if (focusPageAddressMap()) return;
      if (!$mapStep.length) return;
      $pinStep.addClass('hidden');
      $mapStep.removeClass('hidden');
      ensureDeliveryMapReady(function () {
        var $map = $mapStep.find('[data-addr-map]');
        if ($map.length) $map.trigger('focus');
      });
    }

    function setBusy(busy) {
      inFlight = busy;
      $btn.prop('disabled', busy);
      $heroBtn.prop('disabled', busy);
    }

    function check(pinRaw, source, extra) {
      if (inFlight) return;
      var pin = (pinRaw || '').replace(/\D/g, '');
      if (pin.length !== 6) {
        var msg = pin.length === 0 ? 'Enter your 6-digit pincode.' : 'Enter a valid 6-digit pincode.';
        if (source === 'modal') {
          clearModalFeedback();
          $result.text(msg).css('color', 'var(--at-danger)');
        } else {
          showHero(pin, 'invalid', { message: msg });
        }
        return;
      }
      setBusy(true);
      if (source === 'modal') showModalFeedback(null, true);
      else showHero(pin, 'loading', {});
      var postData = $.extend({
        _csrf_token: $('meta[name="csrf-token"]').attr('content'),
        pincode: pin
      }, extra || mapCoordsPayload());
      $.ajax({
        url: baseUrl() + '/ajax/delivery/check-pin',
        method: 'POST',
        dataType: 'json',
        data: postData
      }).done(function (res) {
        if (!res || !res.success) {
          var m = (res && res.error && res.error.message) || (res && res.message) || 'Could not check pincode. Try again.';
          if (source === 'modal') {
            clearModalFeedback();
            $result.text(m).css('color', 'var(--at-danger)');
          } else {
            showHero(pin, 'bad', { message: m });
          }
          return;
        }
        var data = res.data || {};
        var ok = !!data.serviceable;
        currentPin = pin;
        syncInputs(pin);
        if (ok) {
          updateHeader(pin);
          showPinStep();
        }
        if (source === 'modal') showModalFeedback(data, false);
        showHero(pin, ok ? 'ok' : 'bad', data);
        if (window.ApniTokri && window.ApniTokri.toast && data.message) {
          window.ApniTokri.toast(data.message, ok ? 'success' : (data.requires_map_pin ? 'info' : 'error'));
        }
      }).fail(function () {
        var m = 'Could not check pincode. Try again.';
        if (source === 'modal') {
          clearModalFeedback();
          $result.text(m).css('color', 'var(--at-danger)');
        } else {
          showHero(pin, 'bad', { message: m });
        }
      }).always(function () {
        setBusy(false);
      });
    }

    // Header / drawer / hero "change" openers: prefill modal with current pin.
    $(document).on('click', '[data-at-open-modal="#at-delivery-modal"]', function () {
      var v = ($heroInput.val() || '').replace(/\D/g, '') || currentPin;
      $input.val(v);
      clearModalFeedback();
      showPinStep();
    });

    $btn.on('click', function () { check($input.val(), 'modal'); });
    $input.on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); check($input.val(), 'modal'); }
    });

    $mapAction.on('click', openDeliveryMapStep);
    $(document).on('click', '[data-pin-map-select]', function () {
      var v = ($heroInput.val() || '').replace(/\D/g, '') || currentPin;
      $input.val(v);
      clearModalFeedback();
      showPinStep();
      openModal('#at-delivery-modal');
      openDeliveryMapStep();
    });
    $(document).on('click', '[data-at-delivery-back-pin]', showPinStep);

    $(document).on('click', '#at-delivery-map-form [data-addr-confirm-loc]', function () {
      window.setTimeout(function () {
        var pin = ($input.val() || '').replace(/\D/g, '') || currentPin;
        check(pin, 'modal', mapCoordsPayload());
      }, 0);
    });

    $heroForm.on('submit', function (e) {
      e.preventDefault();
      check($heroInput.val(), 'hero');
    });
    $heroInput.on('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    $heroResult.on('click', '[data-pin-change]', function () {
      var v = ($heroInput.val() || '').replace(/\D/g, '') || currentPin;
      $input.val(v);
      clearModalFeedback();
      showPinStep();
      openModal('#at-delivery-modal');
      $input.focus();
    });
  }

  function initPaymentPage() {
    if (typeof window.AT_PAYMENT === 'undefined') return;
    var cfg = window.AT_PAYMENT;
    var csrf = cfg.csrf || $('meta[name="csrf-token"]').attr('content');

    $('#at-pay-simulate button[data-outcome]').on('click', function () {
      var $wrap = $('#at-pay-simulate');
      var outcome = $(this).data('outcome');
      var payload = {
        _csrf_token: csrf,
        payment_token: $wrap.data('token'),
        idempotency_key: $wrap.data('idempotency'),
        outcome: outcome
      };
      if ($wrap.data('subscription')) {
        payload.subscription_number = $wrap.data('subscription');
      } else {
        payload.order_number = $wrap.data('order');
      }
      $.post(cfg.simulateUrl, payload).done(function (res) {
        if (res && res.success && res.data && res.data.redirect) {
          window.location.href = res.data.redirect;
        }
      }).fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Payment failed';
        alert(msg);
      });
    });

    function setPayBusy($btn, busy, msg) {
      if (!$btn || !$btn.length) return;
      if (busy) {
        if (!$btn.data('orig-html')) $btn.data('orig-html', $btn.html());
        $btn.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading');
        if (msg) $('#at-pay-status').text(msg);
      } else {
        $btn.prop('disabled', false).removeAttr('aria-busy').removeClass('is-loading');
        if ($btn.data('orig-html')) $btn.html($btn.data('orig-html'));
      }
    }

    $('#at-razorpay-pay').on('click', function () {
      var $b = $(this);
      if ($b.prop('disabled')) return;
      if (typeof Razorpay === 'undefined') {
        alert('Payment script still loading.');
        return;
      }
      setPayBusy($b, true, 'Opening Razorpay…');
      var rzp = new Razorpay({
        key: $b.data('key-id'),
        amount: $b.data('amount'),
        currency: 'INR',
        name: 'Apni Tokri',
        order_id: $b.data('order-id'),
        prefill: { name: $b.data('customer-name'), contact: $b.data('customer-mobile') },
        handler: function (response) {
          var verifyPayload = {
            _csrf_token: csrf,
            gateway: 'razorpay',
            idempotency_key: $b.data('idempotency'),
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_order_id: response.razorpay_order_id,
            razorpay_signature: response.razorpay_signature
          };
          if ($b.data('subscription-number')) {
            verifyPayload.subscription_number = $b.data('subscription-number');
          } else {
            verifyPayload.order_number = $b.data('order-number');
          }
          setPayBusy($b, true, 'Verifying payment…');
          $.post(cfg.verifyUrl, verifyPayload).done(function (res) {
            if (res && res.success && res.data && res.data.redirect) {
              window.location.href = res.data.redirect;
            } else {
              setPayBusy($b, false);
              alert((res && res.error && res.error.message) || 'Payment not confirmed yet.');
            }
          }).fail(function () {
            setPayBusy($b, false);
            alert('Server could not verify payment.');
          });
        },
        modal: {
          ondismiss: function () {
            setPayBusy($b, false);
            $('#at-pay-status').text('Payment was cancelled. You can try again.');
          }
        }
      });
      rzp.on('payment.failed', function () {
        setPayBusy($b, false);
        $('#at-pay-status').text('Payment failed. Please try again.');
      });
      rzp.open();
    });

    $('#at-cashfree-pay').on('click', function () {
      var $b = $(this);
      if ($b.prop('disabled')) return;
      var session = $b.data('session');
      if (!session || typeof Cashfree === 'undefined') {
        alert('Cashfree session unavailable.');
        return;
      }
      setPayBusy($b, true, 'Opening Cashfree…');
      var mode = (cfg.cashfreeMode === 'production') ? 'production' : 'sandbox';
      var cf = Cashfree({ mode: mode });
      cf.checkout({ paymentSessionId: session, redirectTarget: '_self' }).catch(function () {
        setPayBusy($b, false);
        $('#at-pay-status').text('Could not open Cashfree. Try again or refresh status.');
      });
    });

    function cashfreeSyncStatus($btn) {
      var $pay = $('#at-cashfree-pay');
      var payload = {
        _csrf_token: csrf,
        gateway: 'cashfree',
        order_number: $pay.data('order-number') || '',
        idempotency_key: $pay.data('idempotency') || ''
      };
      if (!payload.order_number || !payload.idempotency_key) {
        alert('Payment session unavailable.');
        return;
      }
      if ($btn) {
        $btn.prop('disabled', true);
      }
      $.post(cfg.verifyUrl, payload).done(function (res) {
        if (res && res.success && res.data && res.data.redirect) {
          window.location.href = res.data.redirect;
          return;
        }
        if (res && res.success && res.data && res.data.pending) {
          alert(res.data.message || 'Payment is still processing.');
          return;
        }
        alert((res && res.error && res.error.message) || 'Payment not confirmed yet.');
      }).fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Could not verify payment.';
        alert(msg);
      }).always(function () {
        if ($btn) {
          $btn.prop('disabled', false);
        }
      });
    }

    $('#at-cashfree-refresh').on('click', function () {
      cashfreeSyncStatus($(this));
    });
  }

  $(function () {
    initQtySteppers();
    initSearchSuggest();
    initStickyBuy();
    initDeliveryPinCheck();
    initPaymentPage();
    initMobileLogin();
    initOtpVerify();
  });

  function initOtpVerify() {
    // Six-box OTP UI lives in assets/js/otp-input.js (loaded by verify-otp.php).
    if (window.AtOtpInput && typeof window.AtOtpInput.init === 'function') {
      window.AtOtpInput.init(document.getElementById('otp-form'));
    }
  }

  function initMobileLogin() {
    var $input = $('#mobile');
    if (!$input.length) {
      return;
    }
    var $hint = $('#mobile-hint');
    function digitsOnly(raw) {
      var d = (raw || '').replace(/\D/g, '');
      if (d.length >= 12 && d.indexOf('91') === 0) {
        d = d.slice(2);
      }
      if (d.length > 10) {
        d = d.slice(0, 10);
      }
      return d;
    }
    function sync() {
      var d = digitsOnly($input.val());
      if ($input.val() !== d) {
        $input.val(d);
      }
      var ok = /^[6-9]\d{9}$/.test(d);
      if ($hint.length) {
        $hint.toggleClass('hidden', ok);
      }
      return ok;
    }
    $input.on('input', sync);
    $input.on('paste', function (e) {
      e.preventDefault();
      var clip = (e.originalEvent && e.originalEvent.clipboardData)
        ? e.originalEvent.clipboardData.getData('text')
        : '';
      $input.val(digitsOnly(clip));
      sync();
    });
    $input.closest('form').on('submit', function (e) {
      if (!sync()) {
        e.preventDefault();
        $input.focus();
      }
    });
    sync();
  }
})(jQuery);
