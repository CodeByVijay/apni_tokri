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

  function initAdminFlashDismiss() {
    $(document).on('click', '.at-admin-flash__close', function () {
      var $flash = $(this).closest('.at-admin-flash');
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

  function initAdminProductDynamicRows() {
    var $form = $('form[data-at-product-form]');
    if (!$form.length) return;

    $form.on('click', '[data-at-add-variant]', function () {
      var $tbody = $('#at-variant-rows');
      var $proto = $tbody.find('[data-at-variant-row]').first();
      if (!$proto.length) return;
      var $row = $proto.clone();
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
        return;
      }
      $(this).closest('[data-at-variant-row]').remove();
    });

    $form.on('click', '[data-at-add-attribute]', function () {
      var $wrap = $('#at-attribute-rows');
      var $proto = $wrap.find('[data-at-attribute-row]').first();
      if (!$proto.length) return;
      var $row = $proto.clone();
      $row.find('input').val('');
      $wrap.append($row);
    });

    $form.on('click', '[data-at-remove-attribute]', function () {
      var $wrap = $('#at-attribute-rows');
      var $rows = $wrap.find('[data-at-attribute-row]');
      if ($rows.length <= 1) {
        $rows.first().find('input').val('');
        return;
      }
      $(this).closest('[data-at-attribute-row]').remove();
    });
  }

  function initSettingsTabsNav() {
    var $wrap = $('[data-at-settings-tabs]');
    if (!$wrap.length) {
      return;
    }
    var mq = window.matchMedia('(max-width: 1023px)');

    function scrollActiveIntoView($link, smooth) {
      if (!mq.matches || !$link.length) {
        return;
      }
      var el = $link[0];
      var wrap = $wrap[0];
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
      var primaryBadge = img.is_primary ? '<span class="at-product-img-card__badge">Primary</span>' : '';
      var setPrimary = img.is_primary
        ? ''
        : '<button type="button" class="at-btn at-btn--ghost at-btn--xs at-focusable" data-at-set-primary="' +
          img.id +
          '">Set primary</button>';
      return (
        '<li class="at-product-img-card" data-image-id="' +
        img.id +
        '" draggable="true">' +
        '<img src="' +
        $('<span/>').text(img.url).html() +
        '" alt="" class="at-product-img-card__thumb" width="112" height="112" loading="lazy">' +
        primaryBadge +
        '<div class="at-product-img-card__actions">' +
        setPrimary +
        '<form method="post" class="inline" data-at-delete-image-form">' +
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
        '<button type="submit" class="at-btn at-btn--ghost at-btn--xs text-red-700 at-focusable">Remove</button>' +
        '</form></div></li>'
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
    initAdminNotifications();
    initNotificationCenter();
    initSecretCredentialToggles();
    initAdminSlugSync();
    initAdminSkuFill();
    initAdminTabbedFormValidation();
    initAdminProductDynamicRows();
    initAdminProductImages();
    initSubmitOnce();
    initAdminFlashDismiss();
    initSettingsTabsNav();
  });
})(jQuery);
