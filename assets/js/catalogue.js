(function ($) {
  'use strict';

  function initGallery() {
    var $gallery = $('[data-at-gallery]');
    if (!$gallery.length) return;

    var images = [];
    try {
      images = JSON.parse($('#at-gallery-data').text() || '[]');
    } catch (e) {
      images = [];
    }
    var index = 0;
    var initialIndex = parseInt($gallery.attr('data-initial-index'), 10);
    if (isNaN(initialIndex) || initialIndex < 0) {
      initialIndex = 0;
    }
    var $main = $('#at-gallery-main');
    var productName = $main.attr('alt') || 'Product image';

    function show(i) {
      if (!images.length) return;
      index = (i + images.length) % images.length;
      $main.attr('src', images[index]);
      $('.at-gallery-thumb').removeClass('border-brand-green').addClass('border-transparent');
      $('.at-gallery-thumb[data-index="' + index + '"]').removeClass('border-transparent').addClass('border-brand-green');
      $gallery.find('.at-gallery__dots i').removeClass('is-on').eq(index).addClass('is-on');
    }

    $('.at-gallery-thumb').on('click', function () {
      show(parseInt($(this).data('index'), 10));
    });
    $('.at-gallery-prev').on('click', function (e) {
      e.stopPropagation();
      show(index - 1);
    });
    $('.at-gallery-next').on('click', function (e) {
      e.stopPropagation();
      show(index + 1);
    });

    var touchStartX = 0;
    var lightboxOpen = false;
    $gallery.on('touchstart', function (e) {
      if (lightboxOpen) return;
      touchStartX = e.originalEvent.changedTouches[0].screenX;
    });
    $gallery.on('touchend', function (e) {
      if (lightboxOpen) return;
      if (e.originalEvent.changedTouches.length !== 1) return;
      var dx = e.originalEvent.changedTouches[0].screenX - touchStartX;
      if (Math.abs(dx) > 40) {
        show(dx > 0 ? index - 1 : index + 1);
      }
    });

    var $stage = $gallery.find('[data-at-gallery-stage]');

    function prefersFinePointer() {
      return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    /* --- Touch / mobile full-screen viewer --- */
    var $lightbox = null;
    var lbState = { scale: 1, tx: 0, ty: 0, lastTap: 0 };

    function applyLbTransform($pan) {
      $pan.css(
        'transform',
        'translate(' + lbState.tx + 'px,' + lbState.ty + 'px) scale(' + lbState.scale + ')'
      );
    }

    function closeLightbox() {
      if (!$lightbox || !$lightbox.length) return;
      $lightbox.attr('hidden', 'hidden');
      $('body').removeClass('at-gallery-lightbox-open');
      lightboxOpen = false;
      $(document).off('keydown.atGalleryLb');
    }

    function lbSetImage(idx) {
      if (!$lightbox || !images.length) return;
      index = (idx + images.length) % images.length;
      show(index);
      lbState.scale = 1;
      lbState.tx = 0;
      lbState.ty = 0;
      $lightbox.find('.at-gallery-lightbox__pan img').attr('src', images[index]).attr('alt', productName);
      if (images.length > 1) {
        $lightbox.find('.at-gallery-lightbox__title').text('Image ' + (index + 1) + ' of ' + images.length);
      } else {
        $lightbox.find('.at-gallery-lightbox__title').text(productName);
      }
      applyLbTransform($lightbox.find('.at-gallery-lightbox__pan'));
    }

    function ensureLightbox() {
      if ($lightbox) return;
      $lightbox = $(
        '<div class="at-gallery-lightbox" hidden role="dialog" aria-modal="true" aria-label="Product image viewer">' +
          '<div class="at-gallery-lightbox__bar">' +
          '<p class="at-gallery-lightbox__title"></p>' +
          '<button type="button" class="at-gallery-lightbox__close at-focusable" aria-label="Close image viewer">&times;</button>' +
          '</div>' +
          '<div class="at-gallery-lightbox__stage">' +
          (images.length > 1
            ? '<button type="button" class="at-gallery-lightbox__nav at-gallery-lightbox__nav--prev at-focusable" aria-label="Previous image">‹</button>' +
              '<button type="button" class="at-gallery-lightbox__nav at-gallery-lightbox__nav--next at-focusable" aria-label="Next image">›</button>'
            : '') +
          '<div class="at-gallery-lightbox__pan"><img src="" alt=""></div>' +
          '</div>' +
          '<p class="at-gallery-lightbox__hint">Pinch or double-tap to zoom · Drag to pan</p>' +
          '</div>'
      );
      $('body').append($lightbox);
      $lightbox.find('.at-gallery-lightbox__close').on('click', closeLightbox);
      $lightbox.on('click', function (e) {
        if ($(e.target).is('.at-gallery-lightbox')) closeLightbox();
      });
      if (images.length > 1) {
        $lightbox.find('.at-gallery-lightbox__nav--prev').on('click', function (e) {
          e.stopPropagation();
          lbSetImage(index - 1);
        });
        $lightbox.find('.at-gallery-lightbox__nav--next').on('click', function (e) {
          e.stopPropagation();
          lbSetImage(index + 1);
        });
      }
      var $pan = $lightbox.find('.at-gallery-lightbox__pan');
      var pinchStart = null;
      $pan.on('touchstart', function (e) {
        var ev = e.originalEvent;
        if (ev.touches.length === 2) {
          pinchStart = {
            dist: Math.hypot(
              ev.touches[0].clientX - ev.touches[1].clientX,
              ev.touches[0].clientY - ev.touches[1].clientY
            ),
            scale: lbState.scale,
          };
        } else if (ev.touches.length === 1) {
          pinchStart = {
            mode: 'pan',
            x: ev.touches[0].clientX,
            y: ev.touches[0].clientY,
            tx: lbState.tx,
            ty: lbState.ty,
          };
        }
      });
      $pan.on('touchmove', function (e) {
        var ev = e.originalEvent;
        if (!pinchStart) return;
        if (ev.touches.length === 2 && pinchStart.dist) {
          e.preventDefault();
          var dist = Math.hypot(
            ev.touches[0].clientX - ev.touches[1].clientX,
            ev.touches[0].clientY - ev.touches[1].clientY
          );
          lbState.scale = Math.min(4, Math.max(1, pinchStart.scale * (dist / pinchStart.dist)));
          applyLbTransform($pan);
        } else if (ev.touches.length === 1 && pinchStart.mode === 'pan' && lbState.scale > 1) {
          e.preventDefault();
          lbState.tx = pinchStart.tx + (ev.touches[0].clientX - pinchStart.x);
          lbState.ty = pinchStart.ty + (ev.touches[0].clientY - pinchStart.y);
          applyLbTransform($pan);
        }
      });
      $pan.on('touchend touchcancel', function () {
        pinchStart = null;
        if (lbState.scale <= 1.05) {
          lbState.scale = 1;
          lbState.tx = 0;
          lbState.ty = 0;
          applyLbTransform($pan);
        }
      });
      $pan.on('dblclick', function (e) {
        e.preventDefault();
        lbState.scale = lbState.scale > 1 ? 1 : 2;
        if (lbState.scale === 1) {
          lbState.tx = 0;
          lbState.ty = 0;
        }
        applyLbTransform($pan);
      });
    }

    function openLightbox(startIndex) {
      if (!images.length) return;
      ensureLightbox();
      lbSetImage(startIndex);
      $lightbox.removeAttr('hidden');
      $('body').addClass('at-gallery-lightbox-open');
      lightboxOpen = true;
      $(document).on('keydown.atGalleryLb', function (ev) {
        if (ev.key === 'Escape') closeLightbox();
      });
    }

    $('.js-gallery-zoom').on('click', function (e) {
      e.stopPropagation();
      e.preventDefault();
      if (prefersFinePointer()) {
        $stage.toggleClass('is-zoomed');
        if (!$stage.hasClass('is-zoomed')) $main.css('transform-origin', 'center');
      } else {
        openLightbox(index);
      }
    });

    if (prefersFinePointer()) {
      $stage.on('click', function (e) {
        if ($(e.target).closest('button').length) return;
        $stage.toggleClass('is-zoomed');
      });
      $stage.on('mousemove', function (e) {
        if (!$stage.hasClass('is-zoomed')) return;
        var r = this.getBoundingClientRect();
        $main.css(
          'transform-origin',
          ((e.clientX - r.left) / r.width) * 100 + '% ' + ((e.clientY - r.top) / r.height) * 100 + '%'
        );
      });
      $stage.on('mouseleave', function () {
        $stage.removeClass('is-zoomed');
        $main.css('transform-origin', 'center');
      });
    } else {
      $stage.on('click', function (e) {
        if ($(e.target).closest('button').length) return;
        openLightbox(index);
      });
    }

    if (images.length) {
      if (initialIndex >= images.length) {
        initialIndex = 0;
      }
      show(initialIndex);
    }
  }

  function csrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
  }

  function cartAjaxUrl(route) {
    var path = window.location.pathname;
    var idx = path.indexOf('/ajax/');
    var base = idx !== -1 ? path.substring(0, idx) : path.substring(0, path.lastIndexOf('/'));
    return base + '/ajax/cart/' + route;
  }

  function initVariantPicker() {
    var $form = $('[data-at-product-form]');
    if (!$form.length) return;

    var $price = $('#at-product-price');
    var $regular = $('#at-product-price-regular');
    var $saveBadge = $('#at-product-save-badge');
    var $stock = $('#at-product-stock-msg');
    var $add = $('#at-add-cart');
    var $buy = $('#at-buy-now');
    var $stickyAdd = $('[data-sticky-add]');
    var cartUrl = String($form.data('cart-url') || '');
    var productSlug = String($form.data('product-slug') || '');

    var variantsInCart = [];
    try {
      variantsInCart = JSON.parse($form.attr('data-variants-in-cart') || '[]');
    } catch (e) {
      variantsInCart = [];
    }
    variantsInCart = variantsInCart.map(String);

    var addBtnHtml = $add.html();
    var stickyAddText = $stickyAdd.length ? $stickyAdd.text() : 'Add to cart';

    function selectedVariantId() {
      var $variant = $form.find('input[name=variant_id]:checked');
      if (!$variant.length) {
        $variant = $form.find('input[name=variant_id]').first();
      }
      return $variant.length ? String($variant.val()) : '';
    }

    function variantInCart(variantId) {
      return variantsInCart.indexOf(String(variantId)) !== -1;
    }

    function setPdpCartButtonMode(inCart) {
      if (!$add.length) return;
      if (inCart) {
        $add.attr('data-pdp-cart-mode', 'go').html(addBtnHtml.replace(/Add to cart/i, 'Go to cart'));
        if ($stickyAdd.length) {
          $stickyAdd.text('Go to cart');
        }
      } else {
        $add.attr('data-pdp-cart-mode', 'add').html(addBtnHtml);
        if ($stickyAdd.length) {
          $stickyAdd.text(stickyAddText);
        }
      }
    }

    function refreshPdpCartButton() {
      var vid = selectedVariantId();
      setPdpCartButtonMode(vid !== '' && variantInCart(vid));
    }

    function fetchVariantsInCart(done) {
      if (!productSlug) {
        if (typeof done === 'function') done();
        return;
      }
      $.ajax({
        url: cartAjaxUrl('variants-in-product'),
        method: 'POST',
        dataType: 'json',
        data: {
          _csrf_token: csrfToken(),
          product_slug: productSlug
        }
      })
        .done(function (res) {
          if (res && res.success && res.data && res.data.variants_in_cart) {
            variantsInCart = res.data.variants_in_cart.map(String);
          }
          if (typeof done === 'function') done();
        })
        .fail(function () {
          if (typeof done === 'function') done();
        });
    }

    $form.data('pdpRefreshCartButton', function () {
      fetchVariantsInCart(refreshPdpCartButton);
    });
    $form.data('pdpApplyVariantsInCart', function (ids) {
      if (ids && ids.length) {
        variantsInCart = ids.map(String);
      } else {
        var vid = selectedVariantId();
        if (vid && variantsInCart.indexOf(vid) === -1) {
          variantsInCart.push(vid);
        }
      }
      refreshPdpCartButton();
    });

    function updateFromVariant($opt, resetQuantity) {
      var price = parseFloat($opt.data('price'));
      var regular = parseFloat($opt.data('regular'));
      var onSale = $opt.data('sale') === 1 || $opt.data('sale') === '1';
      var stock = parseInt($opt.data('stock'), 10);
      var low = parseInt($opt.data('low'), 10);
      var orderCap = parseInt($opt.data('order-cap'), 10);
      var orderMin = parseInt($opt.data('order-min'), 10) || 1;
      if (!orderCap || orderCap < 0) {
        orderCap = Math.max(0, stock);
      }
      if (orderCap > 0 && orderMin > orderCap) {
        orderMin = orderCap;
      }
      var $qty = $('#qty');
      var $hint = $('#at-product-qty-hint');
      if ($qty.length) {
        if (orderCap > 0) {
          $qty.attr('max', orderCap);
          $qty.attr('min', orderMin);
          if (resetQuantity) {
            $qty.val(orderMin);
          } else {
            var cur = parseInt($qty.val(), 10) || orderMin;
            if (cur > orderCap) {
              $qty.val(orderCap);
            } else if (cur < orderMin) {
              $qty.val(orderMin);
            }
          }
          $qty.attr('data-pdp-min-toast', 'Minimum ' + orderMin + ' units required for this variant.');
        }
      }
      if ($hint.length) {
        $hint.text(orderMin > 1 ? 'Minimum order: ' + orderMin + ' units.' : '');
      }

      $price.text('₹' + Math.round(price).toLocaleString('en-IN'));
      var variantOnSale = onSale && regular > price && price > 0 && regular > 0;
      if (variantOnSale) {
        $regular.removeClass('hidden').text('₹' + Math.round(regular).toLocaleString('en-IN'));
      } else {
        $regular.addClass('hidden').text('');
      }
      if ($saveBadge.length) {
        if (variantOnSale) {
          var saveAmt = regular - price;
          var savePct = Math.round((saveAmt / regular) * 100);
          $saveBadge
            .removeClass('hidden')
            .text(
              'You save ₹' +
                Math.round(saveAmt).toLocaleString('en-IN') +
                ' (' +
                savePct +
                '%)'
            );
        } else {
          $saveBadge.addClass('hidden').text('');
        }
      }

      $stock.removeClass('at-stockline at-stockline--out text-[var(--at-success)]');
      if (stock <= 0) {
        $stock.addClass('at-stockline at-stockline--out').text('Out of stock for this size');
        $add.prop('disabled', true);
        $buy.prop('disabled', true);
      } else if (stock <= low) {
        $stock.addClass('at-stockline').text('Only ' + stock + ' left in stock (demo inventory)');
        $add.prop('disabled', false);
        $buy.prop('disabled', false);
      } else {
        $stock.text('In stock').addClass('font-semibold text-[var(--at-success)]');
        $add.prop('disabled', false);
        $buy.prop('disabled', false);
      }
      $('#at-sticky-price').text($price.text());
      $stickyAdd.prop('disabled', stock <= 0);
      refreshPdpCartButton();
    }

    $form.find('input[name=variant_id]').on('change', function () {
      updateFromVariant($(this), true);
      fetchVariantsInCart(refreshPdpCartButton);
    });
    var $checked = $form.find('input[name=variant_id]:checked');
    if (!$checked.length) {
      $checked = $form.find('input[name=variant_id]').first();
    }
    if ($checked.length) updateFromVariant($checked);
    else refreshPdpCartButton();

    window.addEventListener('pageshow', function (ev) {
      if (!ev.persisted) return;
      fetchVariantsInCart(refreshPdpCartButton);
    });
  }

  function toast(msg, type) {
    var ui = (window.VeggieTokri && window.VeggieTokri.toast) ? window.VeggieTokri : window.ApniTokri;
    if (ui && ui.toast) {
      ui.toast(msg, type);
    }
  }

  function initProductShare() {
    var $root = $('[data-at-product-share]');
    if (!$root.length) return;
    var $data = $('#at-product-share-data');
    var config = {};
  try {
      config = JSON.parse($data.text() || '{}');
    } catch (e) {
      config = {};
    }
    var hasNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';
    var $menu = $root.find('[data-at-product-share-menu]');
    var $trigger = $root.find('[data-at-product-share-trigger]');

    function toastUi(msg, type) {
      var ui = window.VeggieTokri || window.ApniTokri;
      if (ui && typeof ui.toast === 'function') {
        ui.toast(msg, type || 'success');
      }
    }

    function openMenu() {
      $menu.removeClass('hidden').prop('hidden', false);
      $trigger.attr('aria-expanded', 'true');
    }

    function closeMenu() {
      $menu.addClass('hidden').prop('hidden', true);
      $trigger.attr('aria-expanded', 'false');
    }

    function copyLink() {
      var url = String(config.url || '').trim();
      if (!url) return;
      function onCopied() {
        toastUi('Link copied to clipboard');
        closeMenu();
      }
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(url).then(onCopied).catch(function () {
          fallbackCopy(url, onCopied);
        });
      } else {
        fallbackCopy(url, onCopied);
      }
    }

    function fallbackCopy(text, done) {
      var $ta = $('<textarea readonly></textarea>').val(text).css({ position: 'fixed', left: '-9999px' });
      $('body').append($ta);
      $ta[0].select();
      try {
        document.execCommand('copy');
        if (typeof done === 'function') done();
      } catch (err) {
        toastUi('Could not copy link', 'error');
      }
      $ta.remove();
    }

    $trigger.on('click', function () {
      var menuOpen = $trigger.attr('aria-expanded') === 'true';
      if (hasNativeShare && !menuOpen) {
        navigator.share({
          title: config.title || document.title,
          text: config.text || '',
          url: config.url || window.location.href
        }).catch(function (err) {
          if (err && err.name === 'AbortError') return;
          openMenu();
        });
        return;
      }
      if (menuOpen) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    $root.on('click', '[data-at-share-copy]', function (e) {
      e.preventDefault();
      copyLink();
    });

    $(document).on('click', function (e) {
      if (!$root.is(e.target) && !$root.has(e.target).length) {
        closeMenu();
      }
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') closeMenu();
    });
  }

  $(function () {
    initGallery();
    initProductShare();
    initVariantPicker();

    $('.js-demo-add-cart').on('click', function (e) {
      if ($(this).prop('disabled')) return;
      e.preventDefault();
      var $btn = $(this);
      var slug = $btn.data('product');
      var variantId = $btn.data('variant-id');
      var qty = 1;
      if (!slug || !variantId) {
        toast('Select a variant first.', 'error');
        return;
      }
      var cartApi = window.VeggieTokriCart || window.ApniTokriCart;
      if (cartApi) {
        cartApi.add(slug, variantId, qty, function (err, res) {
          if (err) {
            toast(err.message, 'error');
          } else {
            toast(res.message || 'Added to cart');
            if ($btn.hasClass('at-product-card__add')) {
              var original = $btn.html();
              $btn.addClass('is-done').text('Added ✓');
              setTimeout(function () { $btn.removeClass('is-done').html(original); }, 1600);
            }
          }
        });
      }
    });

    $('#at-add-cart').on('click', function (e) {
      if ($(this).prop('disabled')) return;
      e.preventDefault();
      var $form = $('[data-at-product-form]');
      if (!$form.length) return;

      if ($(this).attr('data-pdp-cart-mode') === 'go') {
        var goUrl = String($form.data('cart-url') || '');
        if (goUrl) {
          window.location.href = goUrl;
        }
        return;
      }

      var slug = $form.data('product-slug');
      var $variant = $form.find('input[name=variant_id]:checked');
      if (!$variant.length) {
        $variant = $form.find('input[name=variant_id]').first();
      }
      var variantId = $variant.val();
      var qty = parseInt($('#qty').val(), 10) || 1;
      if (!slug || !variantId) {
        toast('Select a variant first.', 'error');
        return;
      }
      var cartApi = window.VeggieTokriCart || window.ApniTokriCart;
      if (!cartApi) return;
      cartApi.add(slug, variantId, qty, function (err, res) {
        if (err) {
          toast(err.message, 'error');
        } else {
          toast(res.message || 'Added to cart');
          var apply = $form.data('pdpApplyVariantsInCart');
          if (typeof apply === 'function') {
            if (res && res.data && res.data.variants_in_cart) {
              apply(res.data.variants_in_cart);
            } else {
              apply(null);
              var refresh = $form.data('pdpRefreshCartButton');
              if (typeof refresh === 'function') refresh();
            }
          }
        }
      });
    });
    $('#at-buy-now').on('click', function (e) {
      if ($(this).prop('disabled')) return;
      e.preventDefault();
      var $form = $('[data-at-product-form]');
      var slug = $form.data('product-slug');
      var $variant = $form.find('input[name=variant_id]:checked');
      if (!$variant.length) $variant = $form.find('input[name=variant_id]').first();
      if (!(window.VeggieTokriCart || window.ApniTokriCart) || !slug) {
        toast('Demo: checkout flow.');
        return;
      }
      (window.VeggieTokriCart || window.ApniTokriCart).add(slug, $variant.val(), parseInt($('#qty').val(), 10) || 1, function (err) {
        if (!err) window.location.href = $form.data('checkout-url');
        else toast(err.message, 'error');
      });
    });
  });
})(jQuery);
