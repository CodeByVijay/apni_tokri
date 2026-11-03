/*
 * Apni Tokri — delivery address form + Leaflet/OpenStreetMap pin picker.
 * No API key. Tile provider comes from window.AT_MAP_CONFIG (PHP MapConfig). Server-side validation is the source of truth.
 * Requires jQuery and Leaflet (assets/vendor/leaflet). Loaded only on address-related pages.
 */
(function ($, window, document) {
  'use strict';
  if (!$ || !window.L) return;

  var CFG = window.AT_MAP_CONFIG || {};
  var instances = [];
  var tileErrorShown = false;

  if (CFG.imagePath) {
    window.L.Icon.Default.imagePath = CFG.imagePath;
  }

  function validCoord(lat, lng) {
    return typeof lat === 'number' && typeof lng === 'number' && isFinite(lat) && isFinite(lng) &&
      lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
  }

  function parseCoord(v) {
    if (v === null || v === undefined || String(v).trim() === '') return null;
    var n = parseFloat(v);
    return isFinite(n) ? n : null;
  }

  function addTiles(map, onFirstLoad, onError) {
    var layer = window.L.tileLayer(CFG.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: CFG.maxZoom || 19,
      attribution: CFG.attribution || '&copy; OpenStreetMap contributors',
      // Default Leaflet behaviour: no prefetching of tiles beyond the viewport (keepBuffer kept small).
      keepBuffer: 1,
      updateWhenIdle: true
    });
    var errors = 0;
    layer.once('load', function () { if (onFirstLoad) onFirstLoad(); });
    layer.on('tileerror', function () {
      errors += 1;
      if (errors === 3 && onError) onError();
    });
    layer.addTo(map);
    return layer;
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var a = arguments, ctx = this;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(ctx, a); }, ms);
    };
  }

  /* ---------------------------------------------------------------- picker */

  function initForm(form) {
    var $form = $(form);
    if ($form.data('atAddrInit')) return;
    $form.data('atAddrInit', true);

    var $mapEl = $form.find('[data-addr-map]');
    var $lat = $form.find('input[name="latitude"]');
    var $lng = $form.find('input[name="longitude"]');
    var $conf = $form.find('input[name="location_confirmed"]');
    var $msg = $form.find('[data-addr-map-msg]');
    var $acc = $form.find('[data-addr-accuracy]');
    var $status = $form.find('[data-addr-pin-status]');
    var $loading = $form.find('[data-addr-map-loading]');
    var $btnGps = $form.find('[data-addr-gps]');
    var $btnConfirm = $form.find('[data-addr-confirm-loc]');
    var $btnClear = $form.find('[data-addr-clear-pin]');
    var $preview = $form.find('[data-addr-preview]');

    var map = null, marker = null, accuracyCircle = null, busyGps = false;

    function setMsg(text, kind) {
      $msg.text(text || '').attr('data-kind', kind || 'info').prop('hidden', !text);
    }

    function updateStatus() {
      var lat = parseCoord($lat.val()), lng = parseCoord($lng.val());
      var has = validCoord(lat, lng);
      var confirmed = has && $conf.val() === '1';
      if (!has) {
        $status.text('No pin placed yet. Tap the map or use your current location.').attr('data-state', 'none');
      } else if (confirmed) {
        $status.text('Location confirmed ✓').attr('data-state', 'confirmed');
      } else {
        $status.text('Pin placed — drag it to your building entrance, then confirm.').attr('data-state', 'placed');
      }
      $btnConfirm.prop('disabled', !has || confirmed);
      $btnClear.prop('hidden', !has);
      updatePreview();
    }

    function writeCoords(lat, lng) {
      $lat.val(lat.toFixed(7));
      $lng.val(lng.toFixed(7));
    }

    function placeMarker(lat, lng, opts) {
      opts = opts || {};
      if (!validCoord(lat, lng)) {
        setMsg('That location is not valid. Please tap the map to place the pin.', 'error');
        return;
      }
      var ll = window.L.latLng(lat, lng);
      if (!marker) {
        marker = window.L.marker(ll, { draggable: true, autoPan: true, keyboard: true, title: 'Delivery pin — drag to move', alt: 'Delivery location pin' });
        marker.on('dragend', function () {
          var p = marker.getLatLng();
          writeCoords(p.lat, p.lng);
          clearAccuracyCircle();
          $acc.prop('hidden', true).text('');
          $conf.val('0');
          setMsg('', '');
          updateStatus();
        });
        marker.addTo(map);
      } else {
        marker.setLatLng(ll);
      }
      writeCoords(lat, lng);
      if (opts.confirmed) {
        $conf.val('1');
      } else {
        $conf.val('0');
      }
      if (opts.view !== false) {
        map.setView(ll, Math.max(map.getZoom(), opts.zoom || 17));
      }
      updateStatus();
    }

    function clearAccuracyCircle() {
      if (accuracyCircle) { map.removeLayer(accuracyCircle); accuracyCircle = null; }
    }

    function clearPin() {
      if (marker) { map.removeLayer(marker); marker = null; }
      clearAccuracyCircle();
      $lat.val('');
      $lng.val('');
      $conf.val('0');
      $acc.prop('hidden', true).text('');
      setMsg('', '');
      updateStatus();
    }

    function buildMap() {
      if (map) return;
      var startLat = parseCoord($lat.val()), startLng = parseCoord($lng.val());
      var hasStart = validCoord(startLat, startLng);
      var center = hasStart ? [startLat, startLng] : (CFG.center || [22.7196, 75.8577]);
      map = window.L.map($mapEl[0], { center: center, zoom: hasStart ? 17 : (CFG.zoom || 13), zoomControl: true, scrollWheelZoom: false, tap: true });
      addTiles(map, function () { $loading.prop('hidden', true); }, function () {
        if (!tileErrorShown) tileErrorShown = true;
        setMsg('Map tiles are not loading. Check your internet connection — you can still fill in the address and save it.', 'error');
      });
      // Safety: hide loader even if tiles are slow.
      window.setTimeout(function () { $loading.prop('hidden', true); }, 8000);

      map.on('click', function (e) {
        placeMarker(e.latlng.lat, e.latlng.lng, { view: false });
        clearAccuracyCircle();
        $acc.prop('hidden', true).text('');
        setMsg('', '');
      });

      if (hasStart) {
        placeMarker(startLat, startLng, { confirmed: $conf.val() === '1', zoom: 17 });
      } else {
        updateStatus();
      }
      window.setTimeout(function () { map.invalidateSize(); }, 0);
    }

    function gpsError(err) {
      var code = err && err.code;
      if (code === 1) {
        setMsg('Location permission was not allowed, so we cannot detect where you are. You can still tap the map to place the pin yourself and type your address below.', 'warn');
      } else if (code === 2) {
        setMsg('Your location is not available right now. Move to an open area, or tap the map to place the pin yourself.', 'warn');
      } else if (code === 3) {
        setMsg('Finding your location took too long. Try again, or tap the map to place the pin yourself.', 'warn');
      } else {
        setMsg('We could not get your location. Tap the map to place the pin yourself.', 'warn');
      }
    }

    function useCurrentLocation() {
      if (busyGps) return;
      if (!navigator.geolocation || typeof navigator.geolocation.getCurrentPosition !== 'function') {
        setMsg('Your browser does not support location detection. Tap the map to place the pin yourself.', 'warn');
        return;
      }
      if (window.isSecureContext === false) {
        setMsg('Location detection needs a secure (https) connection. Tap the map to place the pin yourself.', 'warn');
        return;
      }
      busyGps = true;
      $btnGps.prop('disabled', true).addClass('is-busy').attr('aria-busy', 'true');
      $btnGps.find('[data-label]').text('Finding your location…');
      setMsg('Finding your location…', 'info');
      navigator.geolocation.getCurrentPosition(function (pos) {
        busyGps = false;
        $btnGps.prop('disabled', false).removeClass('is-busy').removeAttr('aria-busy');
        $btnGps.find('[data-label]').text('Use my current location');
        var lat = pos && pos.coords ? Number(pos.coords.latitude) : NaN;
        var lng = pos && pos.coords ? Number(pos.coords.longitude) : NaN;
        if (!validCoord(lat, lng)) {
          setMsg('We received an invalid location from your device. Tap the map to place the pin yourself.', 'warn');
          return;
        }
        placeMarker(lat, lng, { zoom: 18 });
        clearAccuracyCircle();
        var acc = Number(pos.coords.accuracy);
        if (isFinite(acc) && acc > 0) {
          accuracyCircle = window.L.circle([lat, lng], { radius: acc, weight: 1, color: '#1f5f3a', fillOpacity: 0.08, interactive: false }).addTo(map);
          $acc.text('Location accuracy: about ' + Math.round(acc) + ' m. GPS can be off by this much — move the pin to your exact building entrance.').prop('hidden', false);
        }
        setMsg('Location found. Please move the pin to your building entrance, then confirm it.', 'success');
      }, function (err) {
        busyGps = false;
        $btnGps.prop('disabled', false).removeClass('is-busy').removeAttr('aria-busy');
        $btnGps.find('[data-label]').text('Use my current location');
        gpsError(err);
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    /* ---- label type cards ---- */
    function syncLabel() {
      var v = $form.find('input[name="label_type"]:checked').val();
      $form.find('[data-addr-label-custom]').prop('hidden', v !== 'Other');
      $form.find('.at-addr-type').each(function () {
        $(this).toggleClass('is-selected', $(this).find('input').is(':checked'));
      });
    }

    /* ---- preview ---- */
    function val(name) { return $.trim($form.find('[name="' + name + '"]').val() || ''); }
    function updatePreview() {
      if (!$preview.length) return;
      var type = $form.find('input[name="label_type"]:checked').val() || 'Home';
      var label = type === 'Other' ? (val('label_custom') || 'Other') : type;
      var rows = [
        ['Address type', label],
        ['Receiver', val('receiver_name') + (val('receiver_phone') ? ' · ' + val('receiver_phone') : '')],
        ['House / Flat', val('house_number')],
        ['Building / Society', val('building_name')],
        ['Street / Road', val('street')],
        ['Area', val('area')],
        ['Pincode', val('pincode')],
        ['Landmark', val('landmark')],
        ['Delivery instructions', val('delivery_instructions')]
      ];
      if (val('alternate_phone')) rows.splice(2, 0, ['Alternate phone', val('alternate_phone')]);
      var lat = parseCoord($lat.val()), lng = parseCoord($lng.val());
      var loc = 'No map pin (delivery will rely on the written address)';
      if (validCoord(lat, lng)) loc = $conf.val() === '1' ? 'Pin confirmed on map ✓' : 'Pin placed but not confirmed yet';
      rows.push(['Map location', loc]);
      var $dl = $('<dl class="at-addr-preview__list"></dl>');
      $.each(rows, function (_, r) {
        if (!r[1]) return;
        $dl.append($('<div></div>').append($('<dt></dt>').text(r[0]), $('<dd></dd>').text(r[1])));
      });
      $preview.empty().append($dl);
    }

    /* ---- events (bound once per form) ---- */
    $btnGps.on('click', function (e) { e.preventDefault(); useCurrentLocation(); });
    $btnConfirm.on('click', function (e) {
      e.preventDefault();
      var lat = parseCoord($lat.val()), lng = parseCoord($lng.val());
      if (!validCoord(lat, lng)) { setMsg('Place the pin on the map first.', 'error'); return; }
      $conf.val('1');
      setMsg('Location confirmed. You can still drag the pin to adjust it.', 'success');
      updateStatus();
    });
    $btnClear.on('click', function (e) { e.preventDefault(); clearPin(); });
    $form.on('change input', 'input, textarea, select', function () { syncLabel(); updatePreview(); });
    $form.find('[data-addr-edit-location]').on('click', function (e) {
      e.preventDefault();
      var el = $form.find('[data-addr-step="location"]')[0];
      if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (map) map.invalidateSize();
    });
    $form.find('[data-addr-edit-address]').on('click', function (e) {
      e.preventDefault();
      var el = $form.find('[data-addr-step="details"]')[0];
      if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      $form.find('[name="house_number"]').trigger('focus');
    });
    $form.on('submit', function () {
      // Never send a half-formed pin: server rejects it, but avoid the round trip when obviously blank.
      var lat = parseCoord($lat.val()), lng = parseCoord($lng.val());
      if (!validCoord(lat, lng)) { $lat.val(''); $lng.val(''); $conf.val('0'); }
    });

    syncLabel();
    buildMap();
    updatePreview();

    var inst = {
      form: form,
      refresh: function () { if (map) map.invalidateSize(); },
      destroy: function () {
        if (map) { map.remove(); map = null; marker = null; accuracyCircle = null; }
        $form.removeData('atAddrInit');
      }
    };
    $form.data('atAddrInstance', inst);
    instances.push(inst);

    // Hidden containers (checkout <details>, tabs, modals): re-measure when shown.
    $form.closest('details').on('toggle', function () { if (this.open) inst.refresh(); });
    if ('IntersectionObserver' in window) {
      var io = new window.IntersectionObserver(function (entries) {
        if (entries[0] && entries[0].isIntersecting) inst.refresh();
      });
      io.observe($mapEl[0]);
      inst.observer = io;
    }
  }

  /* ----------------------------------------------------- read-only viewer */

  var viewer = null;
  function openViewer(lat, lng, title, $opener) {
    if (!validCoord(lat, lng)) return;
    if (!viewer) {
      var $o = $(
        '<div class="at-map-viewer" role="dialog" aria-modal="true" aria-label="Saved delivery location" hidden>' +
          '<div class="at-map-viewer__panel">' +
            '<div class="at-map-viewer__bar"><strong class="at-map-viewer__title"></strong>' +
            '<button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-viewer-close>Close</button></div>' +
            '<div class="at-map-viewer__map"></div>' +
            '<p class="at-map-viewer__note">Approximate location selected by you on the map.</p>' +
          '</div></div>'
      );
      $('body').append($o);
      viewer = { $el: $o, map: null, marker: null, $opener: null };
      $o.on('click', function (e) { if ($(e.target).is('.at-map-viewer')) closeViewer(); });
      $o.find('[data-viewer-close]').on('click', closeViewer);
      $(document).on('keydown.atMapViewer', function (e) { if (e.key === 'Escape' && viewer && !viewer.$el.prop('hidden')) closeViewer(); });
    }
    viewer.$opener = $opener || null;
    viewer.$el.find('.at-map-viewer__title').text(title || 'Delivery location');
    viewer.$el.prop('hidden', false);
    $('body').addClass('at-map-viewer-open');
    var el = viewer.$el.find('.at-map-viewer__map')[0];
    if (!viewer.map) {
      viewer.map = window.L.map(el, { center: [lat, lng], zoom: 17, scrollWheelZoom: false, tap: true });
      addTiles(viewer.map, null, null);
    }
    if (viewer.marker) viewer.map.removeLayer(viewer.marker);
    viewer.marker = window.L.marker([lat, lng], { draggable: false, keyboard: false, alt: 'Saved delivery location' }).addTo(viewer.map);
    viewer.map.setView([lat, lng], 17);
    window.setTimeout(function () { viewer.map.invalidateSize(); }, 0);
    viewer.$el.find('[data-viewer-close]').trigger('focus');
  }
  function closeViewer() {
    if (!viewer) return;
    viewer.$el.prop('hidden', true);
    $('body').removeClass('at-map-viewer-open');
    if (viewer.$opener && viewer.$opener.length) viewer.$opener.trigger('focus');
  }

  /* ------------------------------------------------------------- bootstrap */

  function initAll() {
    $('[data-at-address-form]').each(function () { initForm(this); });
  }

  $(document).on('click', '[data-at-view-location]', function (e) {
    e.preventDefault();
    var $b = $(this);
    openViewer(parseCoord($b.attr('data-lat')), parseCoord($b.attr('data-lng')), $b.attr('data-title'), $b);
  });

  $(document).on('at:address-map-show', function () {
    $.each(instances, function (_, i) { i.refresh(); });
  });

  var onResize = debounce(function () { $.each(instances, function (_, i) { i.refresh(); }); }, 150);
  $(window).on('resize orientationchange', onResize);
  $(window).on('pagehide', function () {
    $.each(instances, function (_, i) { i.destroy(); });
    instances = [];
    $('body').removeClass('at-map-viewer-open');
  });

  // Checkout address cards: visual selected state.
  $(document).on('change', '.at-addr-card input[type="radio"]', function () {
    var name = this.name;
    $('.at-addr-card input[name="' + name + '"]').each(function () {
      $(this).closest('.at-addr-card').toggleClass('is-selected', this.checked);
    });
  });

  // Restore maps when the page comes back from the back/forward cache (they are destroyed on pagehide).
  $(window).on('pageshow', function (e) {
    if (e.originalEvent && e.originalEvent.persisted) initAll();
  });

  $(initAll);
  window.AtAddressMap = { init: initAll, validCoord: validCoord };
})(window.jQuery, window, document);
