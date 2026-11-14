(function ($) {
  'use strict';

  function relativeTime(iso) {
    if (!iso) return '';
    var t = new Date(iso.replace(' ', 'T'));
    if (isNaN(t.getTime())) return iso;
    var sec = Math.floor((Date.now() - t.getTime()) / 1000);
    if (sec < 60) return 'Just now';
    if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
    if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
    return Math.floor(sec / 86400) + 'd ago';
  }

  function postNotificationAction(pollUrl, csrf, data, done) {
    $.ajax({
      url: pollUrl,
      method: 'POST',
      data: $.extend({ _csrf_token: csrf }, data),
      dataType: 'json'
    }).done(done);
  }

  function initAdminNotifications() {
    var $root = $('#at-admin-notifications');
    if (!$root.length) return;

    var pollUrl = $root.data('poll-url');
    var csrf = $root.data('csrf');
    var $badge = $('#at-admin-notifications-badge');
    var $list = $('#at-admin-notifications-list');
    var $panel = $('#at-admin-notifications-panel');
    var $toggle = $('#at-admin-notifications-toggle');
    var pollMs = 45000;
    var timer = null;
    var open = false;
    var cachedItems = [];
    var ignoreNextDocClick = false;

    function renderItems(items) {
      if (!items || !items.length) {
        $list.html('<p class="text-sm at-muted p-4">No notifications.</p>');
        return;
      }
      function esc(s) { return $('<div>').text(s || '').html(); }
      var bellSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17z"/><path d="M10 21h4"/></svg>';
      var chatSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 5h16v11H9l-5 4V5z"/></svg>';
      var html = '<ul>';
      items.forEach(function (it) {
        var unread = !it.is_read;
        var icon = it.event_kind === 'customer_reply' ? chatSvg : bellSvg;
        html += '<li><a class="at-admin-notif-card at-admin-notif-card--compact at-focusable' + (unread ? ' is-unread' : '') + '" href="' + esc(it.href || '#') + '" data-notification-id="' + (parseInt(it.id, 10) || '') + '">';
        html += '<span class="at-admin-notif-card__icon" aria-hidden="true">' + icon + '</span>';
        html += '<span class="at-admin-notif-card__body">';
        html += '<span class="at-admin-notif-card__title-row"><span class="at-admin-notif-card__title">' + esc(it.title) + '</span>';
        if (unread) html += '<span class="at-admin-notif-card__dot" aria-label="Unread"></span>';
        html += '</span>';
        html += '<span class="at-admin-notif-card__desc">' + esc(it.body) + '</span>';
        html += '<span class="at-admin-notif-card__foot">';
        if (it.ticket_number) html += '<span class="font-mono">' + esc(it.ticket_number) + '</span> · ';
        if (it.customer_name) html += esc(it.customer_name) + ' · ';
        html += esc(it.relative_time || relativeTime(it.created_at || ''));
        html += '</span></span></a></li>';
      });
      html += '</ul>';
      $list.html(html);
    }

    function setBadge(n) {
      n = parseInt(n, 10) || 0;
      if (n <= 0) {
        $badge.addClass('hidden').text('0');
        return;
      }
      $badge.removeClass('hidden').text(n > 99 ? '99+' : String(n));
    }

    function closePanel() {
      open = false;
      $panel.removeClass('is-open');
      $toggle.attr('aria-expanded', 'false');
    }

    function openPanel() {
      open = true;
      $panel.addClass('is-open');
      $toggle.attr('aria-expanded', 'true');
      renderItems(cachedItems);
    }

    function refresh() {
      if (document.hidden || !pollUrl) return;
      $.getJSON(pollUrl).done(function (res) {
        if (res && res.success && res.data) {
          setBadge(res.data.unread);
          cachedItems = res.data.items || [];
          if (open) renderItems(cachedItems);
        }
      });
    }

    function startPoll() {
      if (timer) clearInterval(timer);
      timer = setInterval(refresh, pollMs);
    }

    $toggle.on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      ignoreNextDocClick = true;
      if (open) {
        closePanel();
      } else {
        openPanel();
        refresh();
      }
    });

    $(document).on('click', function () {
      if (ignoreNextDocClick) {
        ignoreNextDocClick = false;
        return;
      }
      if (open) closePanel();
    });

    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && open) {
        closePanel();
      }
    });

    $list.on('click', 'a[data-notification-id]', function () {
      var id = $(this).data('notification-id');
      if (!id) return;
      postNotificationAction(pollUrl, csrf, { action: 'mark_read', notification_id: id });
    });

    $('#at-admin-notifications-mark-all').on('click', function (e) {
      e.preventDefault();
      postNotificationAction(pollUrl, csrf, { action: 'mark_all_read' }, function (res) {
        if (res && res.success) {
          setBadge(0);
          cachedItems = (cachedItems || []).map(function (it) {
            it.is_read = true;
            return it;
          });
          renderItems(cachedItems);
        }
      });
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refresh();
    });

    refresh();
    startPoll();
  }

  function initNotificationCenter() {
    var $center = $('#at-notification-center');
    if (!$center.length) return;
    var pollUrl = $center.data('poll-url');
    var csrf = $center.data('csrf');

    $center.on('click', 'a[data-notification-id]', function (e) {
      var id = $(this).data('notification-id');
      var href = $(this).attr('href');
      if (!id || !href || !pollUrl) return;
      e.preventDefault();
      postNotificationAction(pollUrl, csrf, { action: 'mark_read', notification_id: id }, function () {
        window.location.href = href;
      });
    });
  }

  function initSecretCredentialToggles() {
    $(document).on('click', '[data-secret-toggle]', function () {
      var $btn = $(this);
      var id = $btn.attr('aria-controls');
      var $input = id ? $('#' + id) : $btn.closest('.at-secret-input-wrap').find('.at-secret-input');
      if (!$input.length) return;
      var show = $input.attr('type') === 'password';
      $input.attr('type', show ? 'text' : 'password');
      $btn.attr('aria-pressed', show ? 'true' : 'false');
      $btn.attr('aria-label', show ? 'Hide value' : 'Show value');
    });
  }

  function slugifyAscii(text) {
    var s = (text || '').toString().trim();
    if (!s) return '';
    if (s.normalize) {
      s = s.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
    }
    s = s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-+/g, '-');
    return s.substring(0, 191);
  }

  function needsServerSlug(text) {
    return /[^\u0000-\u007f]/.test(text || '');
  }

  function initAdminSlugSync() {
    $('[data-at-admin-slug]').each(function () {
      var $form = $(this);
      var apiUrl = $form.attr('data-slug-api') || '';
      $form.find('[data-at-slug-group]').each(function () {
        var $group = $(this);
        var $source = $($group.data('source'));
        var $target = $($group.data('target'));
        var $manual = $($group.data('manual'));
        var $preview = $group.data('preview') ? $($group.data('preview')) : null;
        var previewPrefix = $group.data('previewPrefix') || $group.data('preview-prefix') || '';
        if (!$source.length || !$target.length || !$manual.length) return;

        var debounceTimer = null;
        var slugManual = $manual.val() === '1';

        function setManual(isManual) {
          slugManual = isManual;
          $manual.val(isManual ? '1' : '0');
        }

        function updatePreview() {
          if (!$preview || !$preview.length) return;
          var s = ($target.val() || '').trim();
          $preview.text(previewPrefix + (s ? slugifyAscii(s) : '…'));
        }

        function applySlug(val) {
          if (slugManual) return;
          $target.val(val || '');
          updatePreview();
        }

        function syncFromSource() {
          if (slugManual) return;
          var t = $source.val() || '';
          if (needsServerSlug(t) && apiUrl) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
              $.getJSON(apiUrl, { name: t }).done(function (res) {
                if (res && res.success && res.data && !slugManual) {
                  applySlug(res.data.slug || '');
                }
              });
            }, 200);
          } else {
            applySlug(slugifyAscii(t));
          }
        }

        $source.on('input paste', function () {
          setTimeout(syncFromSource, 0);
        });

        $target.on('input', function () {
          var v = ($target.val() || '').trim();
          if (v === '') {
            setManual(false);
            syncFromSource();
          } else {
            setManual(true);
          }
          updatePreview();
        });

        if ($manual.val() === '1') {
          slugManual = true;
        }
        if (!$target.val().trim() && ($source.val() || '').trim()) {
          syncFromSource();
        } else {
          updatePreview();
        }
      });
    });
  }

  function dismissAdminFlash($flash) {
    if (!$flash.length || $flash.hasClass('is-dismissed')) {
      return;
    }
    $flash.addClass('is-dismissed');
    $flash.one('animationend', function () {
      $flash.remove();
      var $wrap = $('[data-at-admin-flashes]');
      if ($wrap.length && $wrap.children('.at-admin-flash').length === 0) {
        $wrap.remove();
      }
    });
  }

  function initAdminFlashDismiss() {
    $(document).on('click', '.at-admin-flash__close', function () {
      dismissAdminFlash($(this).closest('.at-admin-flash'));
    });
    $('[data-at-admin-flash-autodismiss]').each(function () {
      var $flash = $(this);
      var ms = parseInt($flash.attr('data-at-admin-flash-autodismiss'), 10);
      if (!ms || ms < 1000) {
        return;
      }
      window.setTimeout(function () {
        dismissAdminFlash($flash);
      }, ms);
    });
  }

  function initAdminSkuFill() {
    $(document).on('click', '[data-at-fill-sku]', function () {
      var sku = $(this).attr('data-at-fill-sku') || '';
      if (sku) {
        $('#sku').val(sku).trigger('focus');
      }
    });
  }

  /** Hidden tab panels use display:none; required fields there block submit with no visible hint. */
  function initAdminTabbedFormValidation() {
    $('form[data-at-admin-tabbed-form]').on('submit', function () {
      var $form = $(this);
      $form.find('[data-admin-panel].hidden').find('input, select, textarea').each(function () {
        this.required = false;
      });
    });
  }

  function initProductInlineReorder($root) {
    var $tbody = $root.find('[data-at-product-reorder-body]');
    if (!$tbody.length) {
      return null;
    }
    var rowSel = '[data-at-product-reorder-row]';
    var dragKey = null;

    function ensureRowKeys() {
      $tbody.find(rowSel).each(function () {
        var $row = $(this);
        if (!$row.attr('data-at-product-reorder-key')) {
          $row.attr('data-at-product-reorder-key', 'r' + Math.random().toString(36).slice(2, 11));
        }
      });
    }

    function syncUi() {
      ensureRowKeys();
      var $rows = $tbody.find(rowSel);
      $rows.each(function (i) {
        var $row = $(this);
        $row.find('[data-at-product-reorder-seq]').text(i + 1);
        var isFirst = i === 0;
        var isLast = i === $rows.length - 1;
        $row.find('[data-at-product-reorder-up]').prop('disabled', isFirst).attr('aria-disabled', isFirst ? 'true' : 'false');
        $row.find('[data-at-product-reorder-down]').prop('disabled', isLast).attr('aria-disabled', isLast ? 'true' : 'false');
      });
    }

    function moveRow($row, direction) {
      if (direction < 0) {
        var $prev = $row.prev(rowSel);
        if ($prev.length) {
          $prev.before($row);
        }
      } else {
        var $next = $row.next(rowSel);
        if ($next.length) {
          $next.after($row);
        }
      }
      syncUi();
    }

    $root.on('click', '[data-at-product-reorder-up]', function () {
      moveRow($(this).closest(rowSel), -1);
    });
    $root.on('click', '[data-at-product-reorder-down]', function () {
      moveRow($(this).closest(rowSel), 1);
    });

    $tbody.on('dragstart', '[data-at-product-reorder-drag-handle]', function (e) {
      var $row = $(this).closest(rowSel);
      ensureRowKeys();
      dragKey = $row.attr('data-at-product-reorder-key');
      if (e.originalEvent && e.originalEvent.dataTransfer) {
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', dragKey || '');
      }
      $row.addClass('is-dragging');
      $tbody.addClass('is-reorder-dragging');
    });
    $tbody.on('dragend', '[data-at-product-reorder-drag-handle]', function () {
      $(this).closest(rowSel).removeClass('is-dragging');
      $tbody.removeClass('is-reorder-dragging');
      dragKey = null;
    });
    $tbody.on('dragover', rowSel, function (e) {
      e.preventDefault();
      if (e.originalEvent && e.originalEvent.dataTransfer) {
        e.originalEvent.dataTransfer.dropEffect = 'move';
      }
    });
    $tbody.on('drop', rowSel, function (e) {
      e.preventDefault();
      var targetKey = $(this).attr('data-at-product-reorder-key');
      if (!dragKey || !targetKey || dragKey === targetKey) {
        return;
      }
      var $drag = $tbody.find('[data-at-product-reorder-key="' + dragKey + '"]');
      var $target = $tbody.find('[data-at-product-reorder-key="' + targetKey + '"]');
      if (!$drag.length || !$target.length) {
        return;
      }
      if ($drag.index() < $target.index()) {
        $target.after($drag);
      } else {
        $target.before($drag);
      }
      syncUi();
    });

    $root.on('keydown', '[data-at-product-reorder-up], [data-at-product-reorder-down]', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') {
        return;
      }
      e.preventDefault();
      $(this).trigger('click');
    });

    syncUi();

    return { syncUi: syncUi, clearRowKey: function ($row) { $row.removeAttr('data-at-product-reorder-key'); } };
  }

  function initAdminProductDynamicRows() {
    var $form = $('form[data-at-product-form]');
    if (!$form.length) return;

    var variantReorder = initProductInlineReorder($('[data-at-product-variant-reorder]'));
    var attributeReorder = initProductInlineReorder($('[data-at-product-attribute-reorder]'));

    $form.on('click', '[data-at-add-variant]', function () {
      var $tbody = $('#at-variant-rows');
      var $proto = $tbody.find('[data-at-variant-row]').first();
      if (!$proto.length) return;
      var $row = $proto.clone();
      $row.removeAttr('data-at-product-reorder-key').removeClass('is-dragging');
      $row.find('input').each(function () {
        var $el = $(this);
        if ($el.attr('name') === 'variant_id[]') {
          $el.val('');
        } else if ($el.attr('type') === 'number') {
          var n = $el.attr('name');
          $el.val(n === 'variant_low[]' ? '5' : n === 'variant_stock[]' ? '0' : n === 'variant_max[]' ? '20' : n === 'variant_min[]' ? '1' : '');
        } else {
          $el.val('');
        }
      });
      $tbody.append($row);
      if (variantReorder) {
        variantReorder.syncUi();
      }
    });

    $form.on('click', '[data-at-remove-variant]', function () {
      var $tbody = $('#at-variant-rows');
      var $rows = $tbody.find('[data-at-variant-row]');
      if ($rows.length <= 1) {
        $rows.first().find('input').each(function () {
          var $el = $(this);
          if ($el.attr('name') === 'variant_id[]') {
            $el.val('');
          } else if ($el.attr('type') === 'number') {
            var n = $el.attr('name');
            $el.val(n === 'variant_low[]' ? '5' : n === 'variant_max[]' ? '20' : n === 'variant_min[]' ? '1' : '0');
          } else {
            $el.val('');
          }
        });
        if (variantReorder) {
          variantReorder.syncUi();
        }
        return;
      }
      $(this).closest('[data-at-variant-row]').remove();
      if (variantReorder) {
        variantReorder.syncUi();
      }
    });

    $form.on('click', '[data-at-add-attribute]', function () {
      var $wrap = $('#at-attribute-rows');
      var $proto = $wrap.find('[data-at-attribute-row]').first();
      if (!$proto.length) return;
      var $row = $proto.clone();
      $row.removeAttr('data-at-product-reorder-key').removeClass('is-dragging');
      $row.find('input').val('');
      $wrap.append($row);
      if (attributeReorder) {
        attributeReorder.syncUi();
      }
    });

    $form.on('click', '[data-at-remove-attribute]', function () {
      var $wrap = $('#at-attribute-rows');
      var $rows = $wrap.find('[data-at-attribute-row]');
      if ($rows.length <= 1) {
        $rows.first().find('input').val('');
        if (attributeReorder) {
          attributeReorder.syncUi();
        }
        return;
      }
      $(this).closest('[data-at-attribute-row]').remove();
      if (attributeReorder) {
        attributeReorder.syncUi();
      }
    });
  }

  function initSettingsTabsNav() {
    var $wrap = $('[data-at-settings-tabs]');
    if (!$wrap.length) {
      return;
    }
    function scrollActiveIntoView($link, smooth) {
      if (!$link.length) {
        return;
      }
      var el = $link[0];
      var wrap = $wrap[0];
      if (wrap.scrollWidth <= wrap.clientWidth) {
        return;
      }
      var target =
        el.offsetLeft - wrap.clientWidth / 2 + el.offsetWidth / 2;
      var max = Math.max(0, wrap.scrollWidth - wrap.clientWidth);
      target = Math.max(0, Math.min(target, max));
      if (typeof wrap.scrollTo === 'function') {
        wrap.scrollTo({ left: target, behavior: smooth ? 'smooth' : 'auto' });
      } else {
        wrap.scrollLeft = target;
      }
    }

    var $active = $wrap.find('.at-admin-tabs a.is-active').first();
    scrollActiveIntoView($active, false);

    $wrap.on('focusin', 'a', function () {
      scrollActiveIntoView($(this), true);
    });

    var resizeTimer;
    $(window).on('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        scrollActiveIntoView($wrap.find('.at-admin-tabs a.is-active').first(), false);
      }, 120);
    });
  }

  function formatFileSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
  }

  function initAdminProductImages() {
    var $root = $('[data-at-product-images]');
    if (!$root.length) return;

    var uploadUrl = $root.data('upload-url');
    var csrf = $root.data('csrf');
    var productId = parseInt($root.data('product-id'), 10);
    var maxImages = parseInt($root.data('max-images'), 10) || 10;
    var queue = [];
    var uploading = false;
    var seenKeys = {};

    function refreshMeta() {
      var count = $root.find('[data-image-id]').length;
      $root.attr('data-image-count', String(count));
    }

    function syncUploadButton() {
      $root.find('[data-at-image-upload]').prop('disabled', queue.length === 0 || uploading);
      $root.find('[data-at-image-clear-queue]').toggleClass('hidden', queue.length === 0);
      $root.find('[data-at-image-queue]').toggleClass('hidden', queue.length === 0);
    }

    function renderQueue() {
      var $list = $root.find('[data-at-image-queue]');
      $list.empty();
      queue.forEach(function (file) {
        var $li = $('<li class="flex items-center justify-between gap-2 text-sm border border-neutral-200 rounded-lg px-3 py-2"/>');
        $li.append(
          $('<span class="truncate"/>').text(file.name + ' · ' + formatFileSize(file.size))
        );
        $li.append(
          $('<button type="button" class="at-btn at-btn--ghost at-btn--xs at-focusable">Remove</button>').on('click', function () {
            var i = queue.indexOf(file);
            if (i >= 0) queue.splice(i, 1);
            rebuildSeen();
            renderQueue();
            syncUploadButton();
          })
        );
        $list.append($li);
      });
      syncUploadButton();
    }

    function rebuildSeen() {
      seenKeys = {};
      queue.forEach(function (f) {
        seenKeys[f.name + '|' + f.size] = true;
      });
    }

    function addFiles(fileList) {
      var existing = $root.find('[data-image-id]').length;
      var slots = maxImages - existing;
      if (slots <= 0) {
        alert('This product already has the maximum number of images.');
        return;
      }
      var added = 0;
      for (var i = 0; i < fileList.length; i++) {
        if (queue.length >= slots) break;
        var f = fileList[i];
        if (!f || !f.type || f.type.indexOf('image/') !== 0) continue;
        var key = f.name + '|' + f.size;
        if (seenKeys[key]) continue;
        queue.push(f);
        seenKeys[key] = true;
        added++;
      }
      if (fileList.length > added + (slots - queue.length) && queue.length >= slots) {
        alert('Only ' + slots + ' more image(s) can be added (maximum ' + maxImages + ' per product).');
      }
      renderQueue();
    }

    function buildGalleryItem(img) {
      var primaryBadge = img.is_primary
        ? '<span class="at-product-img-card__badge">Primary</span>'
        : '';
      var primaryAction = img.is_primary
        ? '<span class="at-product-img-card__primary-pill" aria-hidden="true">Primary</span>'
        : '<button type="button" class="at-btn at-btn--ghost at-focusable at-product-img-card__btn" data-at-set-primary="' +
          img.id +
          '">Set primary</button>';
      var altVal = img.alt_text ? $('<span/>').text(img.alt_text).html() : '';
      var altBlock =
        String($root.data('media-library')) === '1'
          ? '<input type="text" class="at-input at-product-img-card__alt" maxlength="255" placeholder="Alt text (optional)" value="' +
            altVal +
            '" data-at-image-alt="' +
            img.id +
            '">'
          : '';
      return (
        '<li class="at-product-img-card" data-image-id="' +
        img.id +
        '" draggable="true">' +
        '<div class="at-product-img-card__media">' +
        '<img src="' +
        $('<span/>').text(img.url).html() +
        '" alt="' +
        altVal +
        '" class="at-product-img-card__thumb" width="112" height="112" loading="lazy">' +
        primaryBadge +
        '</div>' +
        '<div class="at-product-img-card__body">' +
        altBlock +
        '<div class="at-product-img-card__actions">' +
        primaryAction +
        '<form method="post" data-at-delete-image-form">' +
        '<input type="hidden" name="_csrf_token" value="' +
        $('<span/>').text(csrf).html() +
        '">' +
        '<input type="hidden" name="action" value="delete_image">' +
        '<input type="hidden" name="id" value="' +
        productId +
        '">' +
        '<input type="hidden" name="image_id" value="' +
        img.id +
        '">' +
        '<button type="submit" class="at-btn at-btn--danger at-focusable at-product-img-card__btn">Remove</button>' +
        '</form></div></div></li>'
      );
    }

    function renderGallery(images) {
      var html = '';
      images.forEach(function (img) {
        html += buildGalleryItem(img);
      });
      $root.find('[data-at-product-images-gallery]').html(html);
      refreshMeta();
      bindGalleryActions();
      bindGalleryDnD();
    }

    function persistOrder() {
      var ids = [];
      $root.find('[data-at-product-images-gallery] [data-image-id]').each(function () {
        ids.push($(this).attr('data-image-id'));
      });
      $.ajax({
        url: uploadUrl,
        method: 'POST',
        data: {
          _csrf_token: csrf,
          action: 'reorder',
          product_id: productId,
          image_order: ids.join(','),
        },
        dataType: 'json',
      });
    }

    var dragId = null;

    function bindGalleryDnD() {
      var $gal = $root.find('[data-at-product-images-gallery]');
      $gal.find('[data-image-id]').each(function () {
        var el = this;
        el.ondragstart = function (e) {
          dragId = $(el).attr('data-image-id');
          e.dataTransfer.effectAllowed = 'move';
        };
        el.ondragover = function (e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
        };
        el.ondrop = function (e) {
          e.preventDefault();
          var targetId = $(el).attr('data-image-id');
          if (!dragId || dragId === targetId) return;
          var $drag = $gal.find('[data-image-id="' + dragId + '"]');
          var $target = $gal.find('[data-image-id="' + targetId + '"]');
          if ($drag.index() < $target.index()) {
            $target.after($drag);
          } else {
            $target.before($drag);
          }
          persistOrder();
        };
      });
    }

    function bindGalleryActions() {
      $root.find('[data-at-image-alt]').off('change').on('change', function () {
        var imageId = $(this).attr('data-at-image-alt');
        var alt = $(this).val();
        $.ajax({
          url: uploadUrl,
          method: 'POST',
          data: {
            _csrf_token: csrf,
            action: 'update_alt',
            product_id: productId,
            image_id: imageId,
            alt_text: alt,
          },
          dataType: 'json',
        });
      });
      $root.find('[data-at-set-primary]').off('click').on('click', function () {
        var imageId = $(this).attr('data-at-set-primary');
        $.ajax({
          url: uploadUrl,
          method: 'POST',
          data: {
            _csrf_token: csrf,
            action: 'set_primary',
            product_id: productId,
            image_id: imageId,
          },
          dataType: 'json',
        }).done(function (res) {
          if (res && res.success && res.data && res.data.images) {
            renderGallery(res.data.images);
          }
        });
      });
    }

    var $drop = $root.find('[data-at-image-dropzone]');
    var $input = $root.find('[data-at-image-file-input]');
    if ($drop.length && $input.length) {
      $drop.on('click', function (e) {
        if (e.target === $input[0]) return;
        $input[0].click();
      });
      $drop.on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          $input[0].click();
        }
      });
      $drop.on('dragover', function (e) {
        e.preventDefault();
        $drop.addClass('is-dragover');
      });
      $drop.on('dragleave drop', function (e) {
        e.preventDefault();
        $drop.removeClass('is-dragover');
        if (e.type === 'drop' && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files) {
          addFiles(e.originalEvent.dataTransfer.files);
        }
      });
      $input.on('change', function () {
        if (this.files) addFiles(this.files);
        this.value = '';
      });
    }

    $root.find('[data-at-image-clear-queue]').on('click', function () {
      queue = [];
      rebuildSeen();
      renderQueue();
    });

    $root.find('[data-at-image-upload]').on('click', function () {
      if (uploading || queue.length === 0) return;
      uploading = true;
      syncUploadButton();
      var $status = $root.find('[data-at-image-upload-status]');
      $status.empty();
      var fd = new FormData();
      fd.append('_csrf_token', csrf);
      fd.append('action', 'upload');
      fd.append('product_id', String(productId));
      queue.forEach(function (file) {
        fd.append('images[]', file);
      });
      $.ajax({
        url: uploadUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
      })
        .done(function (res) {
          if (!res || !res.success) {
            $status.append($('<p class="text-red-700"/>').text((res.error && res.error.message) || 'Upload failed.'));
            return;
          }
          (res.data.results || []).forEach(function (r) {
            var $line = $('<p/>');
            $line.text((r.ok ? '✓ ' : '✗ ') + (r.filename || 'File') + (r.message ? ' — ' + r.message : ''));
            if (!r.ok) $line.addClass('text-red-700');
            else $line.addClass('text-green-800');
            $status.append($line);
          });
          if (res.data.images) {
            renderGallery(res.data.images);
          }
          queue = [];
          rebuildSeen();
          renderQueue();
        })
        .fail(function (xhr) {
          var msg = 'Upload failed.';
          try {
            var j = xhr.responseJSON;
            if (j && j.error && j.error.message) msg = j.error.message;
          } catch (err) {
            /* ignore */
          }
          $status.append($('<p class="text-red-700"/>').text(msg));
        })
        .always(function () {
          uploading = false;
          syncUploadButton();
        });
    });

    bindGalleryActions();
    bindGalleryDnD();
    initProductMediaPicker($root, uploadUrl, csrf, productId, {
      buildGalleryItem: buildGalleryItem,
      refreshMeta: refreshMeta,
      bindGalleryActions: bindGalleryActions,
      bindGalleryDnD: bindGalleryDnD,
    });
  }

  function initProductMediaPicker($root, uploadUrl, csrf, productId, helpers) {
    var $picker = $root.find('[data-at-media-picker]');
    if (!$picker.length) return;
    var mediaAjax = $picker.data('media-ajax');
    var selected = {};
    var page = 1;
    var totalPages = 1;

    function renderPickerItems(items, append) {
      var $grid = $picker.find('[data-at-media-picker-grid]');
      if (!append) $grid.empty();
      items.forEach(function (item) {
        var mid = item.id;
        var on = selected[mid] ? ' is-selected' : '';
        var $li = $(
          '<li class="at-media-card at-media-card--selectable' +
            on +
            '" data-pick-media="' +
            mid +
            '"><img src="' +
            $('<span/>').text(item.url).html() +
            '" alt="" class="at-media-card__thumb" loading="lazy"><span class="at-media-card__check" aria-hidden="true">✓</span></li>'
        );
        $grid.append($li);
      });
    }

    function loadPicker(append) {
      if (!append) page = 1;
      var q = $picker.find('[data-at-media-picker-q]').val() || '';
      var usage = $picker.find('[data-at-media-picker-usage]').val() || '';
      $picker.find('[data-at-media-picker-status]').text('Loading…');
      $.ajax({
        url: mediaAjax,
        method: 'GET',
        data: {
          action: 'list',
          q: q,
          usage: usage,
          sort: 'newest',
          page: page,
          per_page: 24,
        },
        dataType: 'json',
      })
        .done(function (res) {
          if (!res || !res.success || !res.data) return;
          totalPages = res.data.total_pages || 1;
          renderPickerItems(res.data.items || [], !!append);
          $picker.find('[data-at-media-picker-status]').text(
            (res.data.total || 0) + ' item(s) · page ' + (res.data.page || 1) + '/' + (totalPages || 1)
          );
          $picker.find('[data-at-media-picker-more]').toggleClass('hidden', page >= totalPages);
        })
        .fail(function () {
          $picker.find('[data-at-media-picker-status]').text('Could not load media.');
        });
    }

    $root.find('[data-at-open-media-picker]').on('click', function () {
      selected = {};
      $picker.removeClass('hidden');
      $picker.find('[data-at-media-picker-confirm]').prop('disabled', true);
      loadPicker(false);
    });
    $picker.find('[data-at-media-picker-close], .at-media-picker__backdrop').on('click', function () {
      $picker.addClass('hidden');
    });
    $picker.find('[data-at-media-picker-search]').on('click', function () {
      loadPicker(false);
    });
    $picker.find('[data-at-media-picker-more]').on('click', function () {
      if (page < totalPages) {
        page += 1;
        loadPicker(true);
      }
    });
    $picker.on('click', '[data-pick-media]', function () {
      var id = parseInt($(this).attr('data-pick-media'), 10);
      if (selected[id]) {
        delete selected[id];
        $(this).removeClass('is-selected');
      } else {
        selected[id] = true;
        $(this).addClass('is-selected');
      }
      var n = Object.keys(selected).length;
      $picker.find('[data-at-media-picker-confirm]').prop('disabled', n === 0);
    });
    $picker.find('[data-at-media-picker-confirm]').on('click', function () {
      var ids = Object.keys(selected);
      if (!ids.length) return;
      $.ajax({
        url: uploadUrl,
        method: 'POST',
        data: {
          _csrf_token: csrf,
          action: 'attach_media',
          product_id: productId,
          media_ids: ids.join(','),
        },
        dataType: 'json',
      }).done(function (res) {
        if (res && res.success && res.data && res.data.images) {
          var html = '';
          res.data.images.forEach(function (img) {
            html += helpers.buildGalleryItem(img);
          });
          $root.find('[data-at-product-images-gallery]').html(html);
          helpers.refreshMeta();
          helpers.bindGalleryActions();
          helpers.bindGalleryDnD();
          $picker.addClass('hidden');
        } else if (res && !res.success) {
          alert((res.error && res.error.message) || 'Could not attach media.');
        }
      });
    });
  }

  function initAdminMediaLibrary() {
    var $root = $('[data-at-media-library]');
    if (!$root.length) return;
    var ajaxUrl = $root.data('ajax-url');
    var csrf = $root.data('csrf');
    var mediaTab = String($root.data('media-tab') || 'all');
    var $drop = $root.find('[data-at-media-dropzone]');
    var $input = $root.find('[data-at-media-file-input]');
    var $status = $root.find('[data-at-media-upload-status]');
    var $bulkToolbar = $root.find('[data-at-media-bulk-toolbar]');
    var $bulkCount = $root.find('[data-at-media-selected-count]');
    var $bulkResults = $root.find('[data-at-media-bulk-results]');
    var $selectAll = $root.find('[data-at-media-select-all-page]');

    function uploadFiles(files) {
      if (!files || !files.length) return;
      $status.empty().append($('<p/>').text('Uploading…'));
      var fd = new FormData();
      fd.append('_csrf_token', csrf);
      fd.append('action', 'upload');
      for (var i = 0; i < files.length; i++) {
        fd.append('images[]', files[i]);
      }
      $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
      })
        .done(function (res) {
          if (!res || !res.success) {
            $status.append($('<p class="text-red-700"/>').text((res.error && res.error.message) || 'Upload failed.'));
            return;
          }
          (res.data.results || []).forEach(function (r) {
            var $p = $('<p/>').text((r.ok ? '✓ ' : '✗ ') + r.filename + (r.message ? ' — ' + r.message : ''));
            if (!r.ok) $p.addClass('text-red-700');
            else $p.addClass('text-green-800');
            $status.append($p);
          });
          if ((res.data.results || []).some(function (r) {
            return r.ok;
          })) {
            window.setTimeout(function () {
              window.location.reload();
            }, 600);
          }
        })
        .fail(function () {
          $status.append($('<p class="text-red-700"/>').text('Upload failed.'));
        });
    }

    $root.find('[data-at-media-upload-trigger]').on('click', function () {
      $input[0].click();
    });
    $drop.on('click', function (e) {
      if (e.target === $input[0]) return;
      $input[0].click();
    });
    $drop.on('dragover', function (e) {
      e.preventDefault();
      $drop.addClass('is-dragover');
    });
    $drop.on('dragleave drop', function (e) {
      e.preventDefault();
      $drop.removeClass('is-dragover');
      if (e.type === 'drop' && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files) {
        uploadFiles(e.originalEvent.dataTransfer.files);
      }
    });
    $input.on('change', function () {
      if (this.files) uploadFiles(this.files);
      this.value = '';
    });

    var $zipPanel = $root.find('[data-at-media-zip-panel]');
    var $zipInput = $root.find('[data-at-media-zip-input]');
    var $zipMeta = $root.find('[data-at-media-zip-meta]');
    var $zipSubmit = $root.find('[data-at-media-zip-submit]');
    var $zipResultPanel = $root.find('[data-at-media-zip-result-panel]');
    var $zipResultHeading = $root.find('[data-at-media-zip-result-heading]');
    var $zipResults = $root.find('[data-at-media-zip-results]');
    var $zipProgressWrap = $root.find('[data-at-media-zip-progress-wrap]');
    var $zipProgress = $root.find('[data-at-media-zip-progress]');
    var $zipProgressLabel = $root.find('[data-at-media-zip-progress-label]');
    var $zipErrDl = $root.find('[data-at-media-zip-error-download]');
    var $zipErrBtn = $root.find('[data-at-media-zip-download-errors]');
    var $zipDismiss = $root.find('[data-at-media-zip-dismiss]');
    var $zipRefresh = $root.find('[data-at-media-zip-refresh-library]');
    var zipCsvBlob = null;
    var zipCsvName = 'media-zip-errors.csv';
    var selectedZip = null;
    /** ZIP results remain until admin dismisses; no auto-hide (meets 20s+ visibility requirement). */
    var ZIP_RESULT_MIN_VISIBLE_MS = 20000;

    function formatBytes(n) {
      n = parseInt(n, 10) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      return (n / 1048576).toFixed(2) + ' MB';
    }

    function resetZipProgressOnly() {
      $zipProgressWrap.addClass('hidden');
      $zipProgress.val(0);
      $zipProgressLabel.text('');
    }

    function clearZipResultPanel() {
      zipCsvBlob = null;
      $zipErrDl.addClass('hidden');
      $zipResults.empty();
      $zipResultHeading.text('');
      $zipResultPanel.addClass('hidden').removeClass('is-error');
      $zipRefresh.addClass('hidden');
    }

    function showZipResultPanel(zipFileName, statusText, summary, isError, showRefresh) {
      $zipResultPanel.removeClass('hidden');
      $zipResultPanel.toggleClass('is-error', !!isError);
      $zipResultHeading.text(
        (zipFileName || 'ZIP') + ' — ' + (statusText || 'Processing complete')
      );
      $zipResults.empty();
      if (isError) {
        $zipResults.append($('<p class="text-red-700"/>').text(statusText || 'ZIP import failed.'));
        return;
      }
      summary = summary || {};
      var lines = [
        'Total files found: ' + (summary.total_found || 0),
        'Successfully uploaded: ' + (summary.uploaded || 0),
        'Duplicates skipped: ' + (summary.duplicates || 0),
        'Invalid rejected: ' + (summary.invalid || 0),
        'Failed: ' + (summary.failed || 0),
      ];
      lines.forEach(function (line) {
        $zipResults.append($('<p class="text-green-900"/>').text(line));
      });
      if (showRefresh) {
        $zipRefresh.removeClass('hidden');
      } else {
        $zipRefresh.addClass('hidden');
      }
    }

    function uploadZip(file) {
      if (!file) return;
      clearZipResultPanel();
      resetZipProgressOnly();
      $zipResultPanel.removeClass('hidden');
      $zipResults.append($('<p class="at-muted"/>').text('Uploading ZIP…'));
      $zipSubmit.prop('disabled', true);
      var zipFileName = file.name || 'archive.zip';
      var fd = new FormData();
      fd.append('_csrf_token', csrf);
      fd.append('action', 'upload_zip');
      fd.append('archive', file);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ajaxUrl, true);
      xhr.upload.addEventListener('progress', function (ev) {
        if (!ev.lengthComputable) return;
        $zipProgressWrap.removeClass('hidden');
        var pct = Math.round((ev.loaded / ev.total) * 100);
        $zipProgress.val(pct);
        $zipProgressLabel.text('Upload ' + pct + '%');
      });
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        $zipSubmit.prop('disabled', false);
        if (xhr.status < 200 || xhr.status >= 300) {
          showZipResultPanel(zipFileName, 'ZIP import failed.', null, true, false);
          return;
        }
        var res;
        try {
          res = JSON.parse(xhr.responseText);
        } catch (err) {
          showZipResultPanel(zipFileName, 'Invalid server response.', null, true, false);
          return;
        }
        if (!res || !res.success) {
          showZipResultPanel(
            zipFileName,
            (res.error && res.error.message) || 'ZIP import failed.',
            null,
            true,
            false
          );
          return;
        }
        $zipProgressLabel.text('Processing complete');
        var summary = (res.data && res.data.summary) || {};
        var refresh =
          (summary.uploaded || 0) > 0
          || (summary.duplicates || 0) > 0
          || (summary.total_found || 0) > 0;
        showZipResultPanel(zipFileName, 'Processing complete', summary, false, refresh);
        var b64 = res.data && res.data.error_report_csv_base64;
        $zipErrDl.addClass('hidden');
        if (b64) {
          try {
            var bin = atob(b64);
            var bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            zipCsvBlob = new Blob([bytes], { type: 'text/csv;charset=utf-8' });
            zipCsvName = (res.data.error_report_filename) || zipCsvName;
            $zipErrDl.removeClass('hidden');
          } catch (e2) {
            zipCsvBlob = null;
          }
        }
      };
      xhr.send(fd);
    }

    $root.find('[data-at-media-zip-trigger]').on('click', function () {
      $zipInput[0].click();
    });
    $zipInput.on('change', function () {
      var file = this.files && this.files[0];
      selectedZip = file || null;
      if (!file) {
        $zipMeta.addClass('hidden').text('');
        $zipSubmit.addClass('hidden').prop('disabled', true);
        return;
      }
      var name = file.name || 'archive.zip';
      if (!/\.zip$/i.test(name)) {
        $zipMeta.removeClass('hidden').text('Please choose a .zip file.');
        $zipSubmit.addClass('hidden').prop('disabled', true);
        this.value = '';
        selectedZip = null;
        return;
      }
      $zipMeta.removeClass('hidden').text(name + ' · ' + formatBytes(file.size));
      $zipSubmit.removeClass('hidden').prop('disabled', false);
    });
    $zipSubmit.on('click', function () {
      uploadZip(selectedZip);
    });
    $zipDismiss.on('click', function () {
      clearZipResultPanel();
      resetZipProgressOnly();
    });
    $zipRefresh.on('click', function () {
      window.location.reload();
    });
    $zipErrBtn.on('click', function () {
      if (!zipCsvBlob) return;
      var url = URL.createObjectURL(zipCsvBlob);
      var a = document.createElement('a');
      a.href = url;
      a.download = zipCsvName;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });

    function selectableCheckboxes() {
      return $root.find('[data-at-media-select]:not(:disabled)');
    }

    function selectedIds() {
      var ids = [];
      selectableCheckboxes().filter(':checked').each(function () {
        ids.push(String($(this).val()));
      });
      return ids;
    }

    function syncBulkToolbar() {
      var ids = selectedIds();
      var n = ids.length;
      $bulkCount.text(n + ' selected');
      $root.find('[data-at-media-bulk-trash],[data-at-media-bulk-restore],[data-at-media-bulk-purge],[data-at-media-clear-selection]')
        .prop('disabled', n === 0);
      var $enabled = selectableCheckboxes();
      var checked = $enabled.filter(':checked').length;
      $selectAll.prop('indeterminate', checked > 0 && checked < $enabled.length);
      $selectAll.prop('checked', $enabled.length > 0 && checked === $enabled.length);
    }

    function renderBulkResults(payload) {
      if (!payload || !payload.results) {
        $bulkResults.addClass('hidden').empty();
        return;
      }
      var html = '<p><strong>' + (payload.succeeded || 0) + '</strong> succeeded, '
        + '<strong>' + (payload.failed || 0) + '</strong> failed, '
        + '<strong>' + (payload.blocked || 0) + '</strong> blocked.</p>';
      if (payload.results.length) {
        html += '<ul>';
        payload.results.forEach(function (r) {
          var line = (r.success ? '✓' : '✗') + ' #' + r.media_id;
          if (r.filename) line += ' ' + r.filename;
          if (r.reason) line += ' — ' + r.reason;
          if (r.warning) line += ' — ' + r.warning;
          html += '<li>' + line + '</li>';
        });
        html += '</ul>';
      }
      $bulkResults.removeClass('hidden').html(html);
    }

    function applyBulkSelectionAfterRun(payload) {
      (payload.results || []).forEach(function (r) {
        var $cb = $root.find('[data-at-media-select][value="' + r.media_id + '"]');
        if (r.success) {
          $cb.prop('checked', false);
          var $card = $root.find('[data-media-id="' + r.media_id + '"]');
          if (r.action === 'soft_delete' || r.action === 'purge') {
            $card.fadeOut(200, function () {
              $(this).remove();
              syncBulkToolbar();
            });
          } else if (r.action === 'restore' && mediaTab === 'trash') {
            $card.fadeOut(200, function () {
              $(this).remove();
              syncBulkToolbar();
            });
          }
        }
      });
      syncBulkToolbar();
    }

    function runBulkAction(action) {
      var ids = selectedIds();
      if (!ids.length) return;
      var fd = new FormData();
      fd.append('_csrf_token', csrf);
      fd.append('action', action);
      ids.forEach(function (id) {
        fd.append('media_ids[]', id);
      });
      $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
      }).done(function (res) {
        if (!res || !res.success) {
          alert((res && res.error && res.error.message) || 'Bulk action failed.');
          return;
        }
        renderBulkResults(res.data);
        applyBulkSelectionAfterRun(res.data);
        if ((res.data.succeeded || 0) > 0 && (res.data.failed || 0) === 0 && (res.data.blocked || 0) === 0) {
          window.setTimeout(function () {
            window.location.reload();
          }, 800);
        }
      }).fail(function () {
        alert('Bulk action failed.');
      });
    }

    $root.on('change', '[data-at-media-select]', syncBulkToolbar);
    $selectAll.on('change', function () {
      var on = $selectAll.prop('checked');
      selectableCheckboxes().prop('checked', on);
      syncBulkToolbar();
    });
    $root.on('click', '[data-at-media-clear-selection]', function () {
      selectableCheckboxes().prop('checked', false);
      $selectAll.prop('checked', false).prop('indeterminate', false);
      syncBulkToolbar();
    });
    $root.on('click', '[data-at-media-bulk-trash]', function () {
      if (!window.confirm('Move ' + selectedIds().length + ' unused image(s) to trash?')) return;
      runBulkAction('bulk_soft_delete');
    });
    $root.on('click', '[data-at-media-bulk-restore]', function () {
      runBulkAction('bulk_restore');
    });

    $root.on('click', '[data-at-media-soft-delete]', function () {
      var mid = $(this).attr('data-at-media-soft-delete');
      if (!window.confirm('Move this unused image to trash?')) return;
      $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { _csrf_token: csrf, action: 'soft_delete', media_id: mid },
        dataType: 'json',
      }).done(function (res) {
        if (res && res.success) window.location.reload();
        else alert((res.error && res.error.message) || 'Delete failed.');
      });
    });

    $root.on('click', '[data-at-media-restore]', function () {
      var mid = $(this).attr('data-at-media-restore');
      $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { _csrf_token: csrf, action: 'restore', media_id: mid },
        dataType: 'json',
      }).done(function (res) {
        if (res && res.success) window.location.reload();
        else alert((res.error && res.error.message) || 'Restore failed.');
      });
    });

    var $purgeModal = $root.find('[data-at-media-purge-modal]');
    var $purgePreview = $root.find('[data-at-media-purge-preview]');
    var $purgeFilename = $root.find('[data-at-media-purge-filename]');
    var $purgeSize = $root.find('[data-at-media-purge-size]');
    var $purgeConfirm = $root.find('[data-at-media-purge-confirm]');
    var $purgeSubmit = $root.find('[data-at-media-purge-submit]');
    var $purgeHint = $root.find('[data-at-media-purge-confirm-hint]');
    var $purgeSingle = $root.find('[data-at-media-purge-single]');
    var $purgeBulkSummary = $root.find('[data-at-media-purge-bulk-summary]');
    var $purgeTitle = $root.find('[data-at-media-purge-title]');
    var purgeMediaId = null;
    var purgeBulkIds = null;

    function closePurgeModal() {
      purgeMediaId = null;
      purgeBulkIds = null;
      $purgeModal.addClass('hidden');
      $purgeConfirm.prop('checked', false);
      $purgeSubmit.prop('disabled', true);
      $purgeHint.addClass('hidden');
      $purgeSingle.removeClass('hidden');
      $purgeBulkSummary.addClass('hidden').text('');
      $purgeTitle.text('Permanently delete image?');
    }

    function openPurgeModalSingle($btn) {
      purgeBulkIds = null;
      purgeMediaId = $btn.attr('data-at-media-purge');
      $purgeSingle.removeClass('hidden');
      $purgeBulkSummary.addClass('hidden').text('');
      $purgeTitle.text('Permanently delete image?');
      $purgePreview.attr('src', $btn.attr('data-media-url') || '');
      $purgeFilename.text($btn.attr('data-media-filename') || '');
      var bytes = parseInt($btn.attr('data-media-size') || '0', 10);
      $purgeSize.text(bytes > 0 ? (Math.round((bytes / 1024) * 10) / 10) + ' KB' : '—');
      $purgeConfirm.prop('checked', false);
      $purgeSubmit.prop('disabled', true);
      $purgeHint.addClass('hidden');
      $purgeModal.removeClass('hidden');
    }

    function openPurgeModalBulk(ids) {
      purgeMediaId = null;
      purgeBulkIds = ids.slice();
      $purgeSingle.addClass('hidden');
      $purgeBulkSummary.removeClass('hidden').text(
        'You selected ' + ids.length + ' trashed image(s). This action is irreversible.'
      );
      $purgeTitle.text('Permanently delete selected images?');
      $purgeConfirm.prop('checked', false);
      $purgeSubmit.prop('disabled', true);
      $purgeHint.addClass('hidden');
      $purgeModal.removeClass('hidden');
    }

    $root.on('click', '[data-at-media-purge]', function () {
      openPurgeModalSingle($(this));
    });
    $root.on('click', '[data-at-media-bulk-purge]', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      openPurgeModalBulk(ids);
    });
    $root.on('click', '[data-at-media-purge-close]', closePurgeModal);
    $purgeConfirm.on('change', function () {
      var on = $purgeConfirm.prop('checked');
      $purgeSubmit.prop('disabled', !on);
      $purgeHint.toggleClass('hidden', on);
    });
    $purgeSubmit.on('click', function () {
      if (!$purgeConfirm.prop('checked')) {
        $purgeHint.removeClass('hidden');
        return;
      }
      if (!purgeMediaId && (!purgeBulkIds || !purgeBulkIds.length)) {
        $purgeHint.removeClass('hidden');
        return;
      }
      $purgeSubmit.prop('disabled', true);
      var fd = new FormData();
      fd.append('_csrf_token', csrf);
      fd.append('confirm_purge', '1');
      if (purgeBulkIds && purgeBulkIds.length) {
        fd.append('action', 'bulk_purge');
        purgeBulkIds.forEach(function (id) {
          fd.append('media_ids[]', id);
        });
      } else {
        fd.append('action', 'purge');
        fd.append('media_id', purgeMediaId);
      }
      $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
      })
        .done(function (res) {
          if (res && res.success) {
            closePurgeModal();
            if (purgeBulkIds && purgeBulkIds.length) {
              renderBulkResults(res.data);
              applyBulkSelectionAfterRun(res.data);
              if ((res.data.failed || 0) === 0 && (res.data.blocked || 0) === 0) {
                window.setTimeout(function () {
                  window.location.reload();
                }, 800);
              }
            } else {
              if (res.message && res.message.indexOf('could not be deleted') !== -1) {
                alert(res.message);
              }
              window.location.reload();
            }
          } else {
            alert((res.error && res.error.message) || 'Permanent delete failed.');
            $purgeSubmit.prop('disabled', !$purgeConfirm.prop('checked'));
          }
        })
        .fail(function () {
          alert('Permanent delete failed.');
          $purgeSubmit.prop('disabled', !$purgeConfirm.prop('checked'));
        });
    });
    syncBulkToolbar();
  }

  function initSortableReorder($root, rowIdAttr) {
    var $form = $root.find('[data-at-dslots-reorder-form]');
    if (!$form.length) return;

    var $tbody = $root.find('[data-at-dslots-reorder-body]');
    var $orderInput = $form.find('[data-at-dslots-order-input]');
    var $baselineInput = $form.find('[data-at-dslots-baseline-input]');
    var $save = $form.find('[data-at-dslots-save]');
    var $cancel = $form.find('[data-at-dslots-cancel]');
    var $unsaved = $form.find('[data-at-dslots-unsaved-hint]');
    var $status = $root.find('[data-at-dslots-reorder-status]');
    var initial = String($form.attr('data-at-dslots-initial') || $baselineInput.val() || '');
    var dragId = null;

    function currentOrder() {
      var ids = [];
      $tbody.find('[data-at-dslots-row]').each(function () {
        ids.push($(this).attr(rowIdAttr));
      });
      return ids.join(',');
    }

    function syncUi() {
      $tbody.find('[data-at-dslots-row]').each(function (i) {
        var $row = $(this);
        $row.find('[data-at-dslots-seq]').text(i + 1);
        var isFirst = i === 0;
        var isLast = i === $tbody.find('[data-at-dslots-row]').length - 1;
        $row.find('[data-at-dslots-up]').prop('disabled', isFirst).attr('aria-disabled', isFirst ? 'true' : 'false');
        $row.find('[data-at-dslots-down]').prop('disabled', isLast).attr('aria-disabled', isLast ? 'true' : 'false');
      });
      var order = currentOrder();
      $orderInput.val(order);
      var dirty = order !== initial;
      $save.prop('disabled', !dirty);
      $unsaved.toggleClass('hidden', !dirty);
      $root.toggleClass('is-dirty', dirty);
      if (dirty) {
        $status.removeClass('hidden').text('Unsaved changes — save or cancel to finish.');
      } else {
        $status.addClass('hidden').text('');
      }
    }

    function moveRow($row, direction) {
      if (direction < 0) {
        var $prev = $row.prev('[data-at-dslots-row]');
        if ($prev.length) {
          $prev.before($row);
        }
      } else {
        var $next = $row.next('[data-at-dslots-row]');
        if ($next.length) {
          $next.after($row);
        }
      }
      syncUi();
    }

    function restoreInitial() {
      var ids = initial.split(',').filter(Boolean);
      ids.forEach(function (id) {
        var $row = $tbody.find('[' + rowIdAttr + '="' + id + '"]');
        if ($row.length) {
          $tbody.append($row);
        }
      });
      syncUi();
    }

    $form.on('click', '[data-at-dslots-up]', function () {
      moveRow($(this).closest('[data-at-dslots-row]'), -1);
    });
    $form.on('click', '[data-at-dslots-down]', function () {
      moveRow($(this).closest('[data-at-dslots-row]'), 1);
    });
    $cancel.on('click', function () {
      restoreInitial();
    });

    $tbody.on('dragstart', '[data-at-dslots-drag-handle]', function (e) {
      var $row = $(this).closest('[data-at-dslots-row]');
      dragId = $row.attr(rowIdAttr);
      if (e.originalEvent && e.originalEvent.dataTransfer) {
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', dragId || '');
      }
      $row.addClass('is-dragging');
    });
    $tbody.on('dragend', '[data-at-dslots-drag-handle]', function () {
      $(this).closest('[data-at-dslots-row]').removeClass('is-dragging');
      dragId = null;
    });
    $tbody.on('dragover', '[data-at-dslots-row]', function (e) {
      e.preventDefault();
      if (e.originalEvent && e.originalEvent.dataTransfer) {
        e.originalEvent.dataTransfer.dropEffect = 'move';
      }
    });
    $tbody.on('drop', '[data-at-dslots-row]', function (e) {
      e.preventDefault();
      var targetId = $(this).attr(rowIdAttr);
      if (!dragId || !targetId || dragId === targetId) return;
      var $drag = $tbody.find('[' + rowIdAttr + '="' + dragId + '"]');
      var $target = $tbody.find('[' + rowIdAttr + '="' + targetId + '"]');
      if (!$drag.length || !$target.length) return;
      if ($drag.index() < $target.index()) {
        $target.after($drag);
      } else {
        $target.before($drag);
      }
      syncUi();
    });

    $form.on('keydown', '[data-at-dslots-up], [data-at-dslots-down]', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      e.preventDefault();
      $(this).trigger('click');
    });

    syncUi();
  }

  function initDeliverySlotReorder() {
    $('[data-at-delivery-slot-reorder]').each(function () {
      initSortableReorder($(this), 'data-slot-id');
    });
  }

  function initCategoryReorder() {
    $('[data-at-category-reorder]').each(function () {
      initSortableReorder($(this), 'data-category-id');
    });
  }

  function initAdminRefresh() {
    var $btn = $('#at-admin-refresh');
    if (!$btn.length) {
      return;
    }
    $btn.on('click', function () {
      if ($btn.hasClass('is-loading')) {
        return;
      }
      var base = $btn.attr('data-refresh-url') || '';
      if (base === '') {
        return;
      }
      $btn.addClass('is-loading').prop('disabled', true).attr('aria-busy', 'true');
      var target;
      try {
        target = new URL(base, window.location.origin);
      } catch (e) {
        target = new URL(window.location.pathname, window.location.origin);
      }
      target.searchParams.delete('_at_r');
      target.searchParams.delete('_at_cb');
      target.searchParams.set('_at_r', String(Date.now()));
      if (window.location.hash) {
        target.hash = window.location.hash;
      }
      window.location.replace(target.pathname + target.search + target.hash);
    });
  }

  function initSubmitOnce() {
    $(document).on('click', 'button[type="submit"][data-at-submit-once], input[type="submit"][data-at-submit-once]', function () {
      var $btn = $(this);
      var formId = $btn.attr('form');
      var $form = formId ? $('#' + formId) : $btn.closest('form');
      if (!$form.length) return;
      $form.one('submit', function () {
        $btn.prop('disabled', true).attr('aria-disabled', 'true');
      });
    });
  }

  $(function () {
    $('#at-admin-menu-toggle').on('click', function () {
      var $n = $('#at-admin-mobile-nav').toggleClass('hidden');
      $(this).attr('aria-expanded', $n.hasClass('hidden') ? 'false' : 'true');
    });
    $('[data-admin-tab]').on('click', function (e) {
      e.preventDefault();
      var target = $(this).data('admin-tab');
      var $root = $(this).closest('[data-at-product-edit]');
      var $scope = $root.length ? $root : $(this).closest('.at-admin-tabs').parent();
      $(this).closest('.at-admin-tabs').find('[data-admin-tab]').removeClass('is-active');
      $(this).addClass('is-active');
      $scope.find('[data-admin-panel]').addClass('hidden');
      $scope.find('[data-admin-panel="' + target + '"]').removeClass('hidden');
    });

    var $productEdit = $('[data-at-product-edit]');
    if ($productEdit.length && window.location.hash === '#images') {
      $productEdit.find('[data-admin-tab="images"]').trigger('click');
    }
    $('.js-image-sortable li').each(function (i) {
      $(this).attr('data-order', i + 1);
    });
    initAdminRefresh();
    initAdminNotifications();
    initNotificationCenter();
    initSecretCredentialToggles();
    initAdminSlugSync();
    initAdminSkuFill();
    initAdminTabbedFormValidation();
    initAdminProductDynamicRows();
    initAdminProductImages();
    initAdminMediaLibrary();
    initSubmitOnce();
    initAdminFlashDismiss();
    initSettingsTabsNav();
    initDeliverySlotReorder();
    initCategoryReorder();
  });
})(jQuery);
