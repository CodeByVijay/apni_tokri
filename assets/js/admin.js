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

  function initSubmitOnce() {
    $(document).on('click', 'form button[type="submit"][data-at-submit-once], form input[type="submit"][data-at-submit-once]', function () {
      var $btn = $(this);
      var $form = $btn.closest('form');
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
      $(this).closest('.at-admin-tabs').find('[data-admin-tab]').removeClass('is-active');
      $(this).addClass('is-active');
      $('[data-admin-panel]').addClass('hidden');
      $('[data-admin-panel="' + target + '"]').removeClass('hidden');
    });
    $('.js-image-sortable li').each(function (i) {
      $(this).attr('data-order', i + 1);
    });
    initAdminNotifications();
    initNotificationCenter();
    initSecretCredentialToggles();
    initAdminSlugSync();
    initAdminSkuFill();
    initAdminTabbedFormValidation();
    initSubmitOnce();
    initAdminFlashDismiss();
  });
})(jQuery);
