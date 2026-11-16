/*
 * Veggie Tokri — header delivery-location selector (Phase 2).
 * Isolated module: lazy-loads the Google Maps JavaScript API only when the map
 * step opens, talks to existing /ajax/address/* + /ajax/delivery/select-address.php
 * endpoints. The browser Maps key comes from the server-rendered config; the
 * server-side Google credential is never sent to the page.
 * Requires jQuery. Loaded on every public page (small, deferred).
 */
(function ($, window, document) {
  'use strict';
  if (!$) return;

  var $modal = $('#at-delivery-modal');
  if (!$modal.length) return;

  var CFG = {};
  try {
    var el = document.getElementById('at-dl-config');
    if (el) CFG = JSON.parse(el.textContent || '{}');
  } catch (e) {
    CFG = {};
  }
  var MAP_CFG = CFG.map || {};

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m && m.getAttribute ? (m.getAttribute('content') || '') : '';
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var a = arguments, ctx = this;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(ctx, a); }, ms);
    };
  }

  function validCoord(lat, lng) {
    return typeof lat === 'number' && typeof lng === 'number' && isFinite(lat) && isFinite(lng) &&
      lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
  }

  var $steps = $modal.find('[data-at-dl-step]');
  var $msg = $modal.find('[data-at-dl-msg]');

  function newSessionToken() {
    if (!window.crypto || !window.crypto.getRandomValues) return String(Date.now()) + '-' + Math.random();
    var b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    var h = '';
    for (var i = 0; i < b.length; i++) h += (b[i] < 16 ? '0' : '') + b[i].toString(16);
    return h;
  }

  var sessionToken = newSessionToken();

  /* Every new location selection (search pick, GPS fix, map confirm) bumps this
     token; in-flight lookups for the previous selection are dropped so a slow
     response can never overwrite the customer's newer choice. */
  var locSeq = 0;
  function beginSelection() {
    return ++locSeq;
  }

  function showStep(name) {
    $steps.each(function () {
      var on = this.getAttribute('data-at-dl-step') === name;
      $(this).prop('hidden', !on).toggleClass('is-active', on);
    });
    // The modal is the scroll container (header stays sticky); reset both so a
    // stale body scroll position can never linger if CSS is cached.
    var modalEl = $modal.find('.at-modal')[0];
    if (modalEl) modalEl.scrollTop = 0;
    var bodyEl = $modal.find('.at-modal__body')[0];
    if (bodyEl) bodyEl.scrollTop = 0;
    if (name === 'map') {
      ensureMap();
    }
  }

  function showMsg(text, kind) {
    if (!text) {
      $msg.prop('hidden', true).text('').removeClass('is-ok is-err is-warn');
      return;
    }
    $msg.prop('hidden', false).text(text).removeClass('is-ok is-err is-warn').addClass('is-' + (kind || 'err'));
  }

  function updateHeader(title, subtitle) {
    if (title) $('[data-at-loc-title]').text(title);
    $('[data-at-loc-pin]').text(subtitle ? ' · ' + subtitle : '');
  }

  /* ------------------------------------------------------------- search */

  var $search = $modal.find('[data-at-dl-search]');
  var $results = $modal.find('[data-at-dl-results]');
  var searchXhr = null;

  function hideResults() {
    $results.prop('hidden', true).empty();
    $search.attr('aria-expanded', 'false');
  }

  function renderResults(list) {
    $results.empty();
    if (!list.length) {
      $results.prop('hidden', true);
      return;
    }
    $.each(list, function (_, s) {
      var label = s.label || s.description || s.formatted_address || '';
      if (!label) return;
      var $li = $('<li>');
      if (s.disabled || s.type === 'notice') {
        $li.append($('<div class="at-dl__result at-dl__result--notice">')
          .append($('<strong>').text(label))
          .append(s.detail ? $('<small>').text(s.detail) : null));
        $results.append($li);
        return;
      }
      var $btn = $('<button type="button" class="at-dl__result">');
      $btn.append($('<strong>').text(label));
      if (s.detail) $btn.append($('<small>').text(s.detail));
      $btn.on('click', function () { pickSuggestion(s); });
      $li.append($btn);
      $results.append($li);
    });
    $results.prop('hidden', false);
    $search.attr('aria-expanded', 'true');
  }

  var runSearch = debounce(function () {
    var q = $.trim($search.val() || '');
    if (!CFG.searchEnabled || q.length < (CFG.minChars || 3)) {
      hideResults();
      return;
    }
    if (searchXhr && searchXhr.abort) searchXhr.abort();
    searchXhr = $.ajax({
      url: CFG.autocompleteUrl,
      method: 'POST',
      dataType: 'json',
      data: { _csrf_token: csrf(), q: q, session_token: sessionToken }
    }).done(function (res) {
      if (!res || !res.success) {
        hideResults();
        return;
      }
      renderResults((res.data && res.data.suggestions) || []);
    }).fail(function (xhr) {
      if (xhr && xhr.statusText === 'abort') return;
      hideResults();
    });
  }, CFG.debounceMs || 400);

  $search.on('input', runSearch);
  $search.on('focus', function () {
    if ($results.children().length) $results.prop('hidden', false);
  });
  $modal.on('click', function (e) {
    if (!$(e.target).closest('[data-at-dl-searchwrap], [data-at-dl-results]').length) hideResults();
  });

  function pickSuggestion(s) {
    hideResults();
    $search.val('');
    var seq = beginSelection();
    // Google Autocomplete predictions carry only place_id + text — address
    // components and coordinates must come from a Place Details lookup.
    if (s.provider === 'google' && s.needs_details && s.place_id && CFG.googlePlaceDetailsUrl) {
      fetchGooglePlace(s, seq);
      return;
    }
    // Photon returns `lat`/`lng`; a Google-shaped suggestion carries `latitude`/`longitude`.
    var lat = parseFloat(s.lat !== undefined ? s.lat : s.latitude);
    var lng = parseFloat(s.lng !== undefined ? s.lng : s.longitude);
    if (validCoord(lat, lng)) {
      // Fill whatever the suggestion already knows, then drop the pin on the map so
      // the customer can fine-tune before confirming — the exact address text is
      // reverse-geocoded again on confirm.
      applyAddressToForm(s);
      showMsg('', null);
      showStep('map');
      ensureGoogleMaps().then(function () {
        initMap();
        if (map && seq === locSeq) {
          setMapPoint(lat, lng);
          map.setZoom(17);
        }
      }).catch(function (err) {
        showMapUnavailable(err);
      });
      return;
    }
    // Provider returned no coordinates: keep any components it did return and the
    // label as a last-resort area hint — let the customer finish manually.
    applyAddressToForm(s);
    if (!userEdited.area && !field('area').val()) field('area').val(s.label || s.description || '');
    clearCoords();
    showMsg('We found the area — please confirm the exact details below.', 'warn');
    showStep('form');
  }

  var selectedPlaceId = null;

  /* Google Places selection: keep the chosen place_id, resolve it through the
     server-side Place Details endpoint (field-masked response → normalized
     components), then run the same shared autofill pipeline as GPS/map. */
  function fetchGooglePlace(s, seq) {
    selectedPlaceId = s.place_id;
    showStep('form');
    showMsg('Fetching address details…', 'warn');
    $.ajax({
      url: CFG.googlePlaceDetailsUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 15000,
      data: { _csrf_token: csrf(), place_id: s.place_id, session_token: sessionToken }
    }).done(function (res) {
      if (seq !== locSeq) return;
      var a = res && res.data ? res.data.address : null;
      if (!res || !res.success || !a) {
        clearCoords();
        showMsg((res && res.error && res.error.message) || 'Could not load that place — please enter the address below.', 'warn');
        return;
      }
      var lat = parseFloat(a.latitude);
      var lng = parseFloat(a.longitude);
      applyAddressToForm(a);
      if (!validCoord(lat, lng)) {
        clearCoords();
        showMsg('This place has no exact coordinates — please confirm the address details or pick it on the map.', 'warn');
        return;
      }
      setCoords(lat, lng, true, 'Location set from your search — verify and complete the details below.');
      showMsg('Address filled from Google — please verify the details before saving.', 'ok');
    }).fail(function (xhr) {
      if (seq !== locSeq) return;
      clearCoords();
      var m = 'Could not load the address details — please enter the address below.';
      try {
        if (xhr && xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) m = xhr.responseJSON.error.message;
      } catch (e) { /* ignore */ }
      showMsg(m, 'warn');
    }).always(function () {
      // Place Details concludes Google's billing session — rotate the token so
      // the next search starts a fresh session (per Places API session rules).
      sessionToken = newSessionToken();
    });
  }

  /* ------------------------------------------------------------- map */

  var gmapsPromise = null;
  var gmapsReady = false;
  var map = null;
  var marker = null;
  var mapCoords = null;
  var $mapLoading = $modal.find('[data-at-dl-maploading]');
  var $mapPoint = $modal.find('[data-at-dl-mappoint]');
  var $mapConfirm = $modal.find('[data-at-dl-mapconfirm]');
  var GMAPS_CFG = CFG.googleMaps || {};

  // Cost control: the Maps JS API is injected only when the map step opens,
  // once per page. One ping per open feeds the server usage dashboard —
  // browser map loads are billed by Google but invisible to server metering.
  function reportMapLoad(ok) {
    if (!CFG.mapLoadPingUrl) return;
    $.ajax({
      url: CFG.mapLoadPingUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 5000,
      data: { _csrf_token: csrf(), ok: ok ? '1' : '0' }
    });
  }

  function ensureGoogleMaps() {
    if (gmapsPromise) return gmapsPromise;
    gmapsPromise = new Promise(function (resolve, reject) {
      if (gmapsReady && window.google && window.google.maps && window.google.maps.Map) return resolve();
      var key = typeof GMAPS_CFG.apiKey === 'string' ? GMAPS_CFG.apiKey : '';
      if (GMAPS_CFG.enabled === false || key === '') {
        return reject(new Error('map_key_missing'));
      }
      var settled = false;
      var cbName = '__atGmapsLoaded';
      var script = null;
      var finish = function (err) {
        if (settled) return;
        settled = true;
        try { delete window[cbName]; } catch (e) { window[cbName] = undefined; }
        if (err) {
          if (script && script.parentNode) script.parentNode.removeChild(script);
          // Transient failures may retry on the next open; a rejected key will not.
          if (err.message === 'map_load_failed' || err.message === 'map_load_timeout') gmapsPromise = null;
          reject(err);
          return;
        }
        gmapsReady = true;
        resolve();
      };
      window[cbName] = function () {
        if (window.google && window.google.maps && window.google.maps.Map) finish(null);
        else finish(new Error('map_load_failed'));
      };
      var prevAuthFailure = window.gm_authFailure;
      window.gm_authFailure = function () {
        if (typeof prevAuthFailure === 'function') {
          try { prevAuthFailure(); } catch (e) { /* ignore */ }
        }
        // Google calls this for key/referrer/billing failures — before the
        // callback when unlucky, or any time after the map has loaded.
        if (settled) showMapUnavailable(new Error('map_key_rejected'));
        else finish(new Error('map_key_rejected'));
      };
      script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) +
        '&loading=async&callback=' + cbName;
      script.async = true;
      script.onerror = function () { finish(new Error('map_load_failed')); };
      document.head.appendChild(script);
      window.setTimeout(function () { finish(new Error('map_load_timeout')); }, 15000);
    });
    // Keep the rejected promise handled even if the caller only uses .then().
    gmapsPromise.catch(function () { /* handled by callers */ });
    return gmapsPromise;
  }

  function setMapPoint(lat, lng, fromGps) {
    mapCoords = { lat: lat, lng: lng };
    var pos = { lat: lat, lng: lng };
    if (!marker) {
      marker = new window.google.maps.Marker({
        position: pos,
        map: map,
        draggable: true,
        title: 'Delivery pin — drag to move'
      });
      marker.addListener('dragend', function () {
        var p = marker.getPosition();
        if (!p) return;
        mapCoords = { lat: p.lat(), lng: p.lng() };
        describePoint();
      });
    } else {
      marker.setPosition(pos);
    }
    map.panTo(pos);
    describePoint(fromGps);
    $mapConfirm.prop('disabled', false);
  }

  function describePoint(fromGps) {
    if (!mapCoords) {
      $mapPoint.text('No location selected yet.');
      return;
    }
    $mapPoint.text((fromGps ? 'Current location: ' : 'Pin: ') +
      mapCoords.lat.toFixed(5) + ', ' + mapCoords.lng.toFixed(5) + ' — drag to adjust.');
  }

  function initMap() {
    if (map) {
      window.setTimeout(function () { window.google.maps.event.trigger(map, 'resize'); }, 60);
      return;
    }
    var $mapEl = $modal.find('[data-at-dl-map-el]');
    if (!$mapEl.length) return;
    $mapLoading.prop('hidden', false);
    var startLat = parseFloat(field('latitude').val());
    var startLng = parseFloat(field('longitude').val());
    var hasStart = validCoord(startLat, startLng);
    var fallback = MAP_CFG.center || [22.7196, 75.8577];
    var center = hasStart ? { lat: startLat, lng: startLng } : { lat: fallback[0], lng: fallback[1] };
    map = new window.google.maps.Map($mapEl[0], {
      center: center,
      zoom: hasStart ? 17 : (MAP_CFG.zoom || 13),
      maxZoom: MAP_CFG.maxZoom || 19,
      mapTypeId: 'roadmap',
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      zoomControl: true,
      scrollwheel: false,
      gestureHandling: 'greedy',
      clickableIcons: false
    });
    window.google.maps.event.addListenerOnce(map, 'idle', function () {
      $mapLoading.prop('hidden', true);
      reportMapLoad(true);
    });
    watchMapErrors($mapEl[0]);
    window.setTimeout(function () { $mapLoading.prop('hidden', true); }, 9000);
    map.addListener('click', function (e) {
      if (e && e.latLng) setMapPoint(e.latLng.lat(), e.latLng.lng());
    });
    if (hasStart) {
      setMapPoint(center.lat, center.lng);
      map.setZoom(17);
    }
    window.setTimeout(function () { window.google.maps.event.trigger(map, 'resize'); }, 60);
  }

  function mapUnavailableMessage(err) {
    if (err && (err.message === 'map_key_missing' || err.message === 'map_key_rejected')) {
      return 'The interactive map is unavailable right now. You can still enter your address manually.';
    }
    return 'Map could not be loaded. You can still enter your address manually.';
  }

  var mapErrObserver = null;
  // Invalid/restricted keys (and billing-off projects) render Google's own
  // gm-err-container instead of an interactive map — catch that and fall back.
  function watchMapErrors(el) {
    if (mapErrObserver || !window.MutationObserver || !el) return;
    mapErrObserver = new window.MutationObserver(function () {
      if (el.querySelector('.gm-err-container')) {
        showMapUnavailable(new Error('map_key_rejected'));
      }
    });
    mapErrObserver.observe(el, { childList: true, subtree: true });
    if (el.querySelector('.gm-err-container')) {
      showMapUnavailable(new Error('map_key_rejected'));
    }
  }

  function showMapUnavailable(err) {
    $mapLoading.prop('hidden', true);
    if (err && err.message === 'map_key_rejected') reportMapLoad(false);
    showMsg(mapUnavailableMessage(err), 'warn');
    showStep('form');
  }

  function ensureMap() {
    ensureGoogleMaps().then(initMap).catch(function (err) {
      showMapUnavailable(err);
    });
  }

  /* ------------------------------------------------------------- GPS */

  function geolocate(onOk, onErr) {
    if (window.isSecureContext === false) {
      onErr('Location needs a secure (HTTPS) connection. Please search or pick on the map.');
      return;
    }
    if (!('geolocation' in navigator) || !navigator.geolocation.getCurrentPosition) {
      onErr('This browser does not support location. Please search or pick on the map.');
      return;
    }
    var settled = false;
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        if (settled) return;
        settled = true;
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        if (!validCoord(lat, lng)) {
          onErr('Could not read your location. Please search or pick on the map.');
          return;
        }
        onOk(lat, lng);
      },
      function (err) {
        if (settled) return;
        settled = true;
        var msg = 'Location unavailable. Please search or pick on the map.';
        if (err && err.code === 1) msg = 'Location permission denied. Please search, pick on the map, or enter the address manually.';
        else if (err && err.code === 3) msg = 'Location timed out. Please try again or pick on the map.';
        onErr(msg);
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
  }

  var $gpsSub = $modal.find('[data-at-dl-gps-sub]');

  $modal.on('click', '[data-at-dl-gps]', function () {
    var $btn = $(this);
    if ($btn.prop('disabled')) return;
    var seq = beginSelection();
    $btn.prop('disabled', true).addClass('is-busy');
    $gpsSub.text('Locating…');
    var reset = function () {
      $btn.prop('disabled', false).removeClass('is-busy');
      $gpsSub.text('GPS — fastest way');
    };
    geolocate(
      function (lat, lng) {
        if (seq !== locSeq) {
          reset();
          return;
        }
        setCoords(lat, lng, true, 'Location detected by GPS — verify and complete the details below.');
        $gpsSub.text('Fetching address…');
        reverseFill(lat, lng, seq, function (filled, errMsg) {
          reset();
          if (seq !== locSeq) return;
          if (!filled) {
            showMsg(errMsg || 'We could not fetch the address details for this location — please fill them in below.', 'warn');
          } else {
            showMsg('Location detected — please verify the address details before saving.', 'ok');
          }
          showStep('form');
        });
      },
      function (msg) {
        reset();
        if (seq !== locSeq) return;
        showMsg(msg, 'warn');
      }
    );
  });

  $modal.on('click', '[data-at-dl-mapgps]', function () {
    var $btn = $(this);
    if ($btn.prop('disabled') || !map) return;
    $btn.prop('disabled', true);
    geolocate(
      function (lat, lng) {
        $btn.prop('disabled', false);
        setMapPoint(lat, lng, true);
        map.setZoom(17);
      },
      function (msg) {
        $btn.prop('disabled', false);
        showMsg(msg, 'warn');
      }
    );
  });

  /* ------------------------------------------------- reverse geocoding */

  function ajaxErrorMsg(res, xhr, fallback) {
    try {
      var e = res && res.error ? res.error : (xhr && xhr.responseJSON ? xhr.responseJSON.error : null);
      if (e && e.message) return String(e.message);
    } catch (ignore) { /* ignore */ }
    return fallback || null;
  }

  /* Reverse-geocode the confirmed coordinates and run the shared autofill.
     `seq` is the selection token captured when the user chose this location —
     a stale response is dropped before it can touch the form. The callback is
     always invoked so callers can reset their loading state. */
  function reverseFill(lat, lng, seq, done) {
    var finish = function (filled, errMsg) {
      if (typeof done === 'function') done(filled, errMsg);
    };
    $.ajax({
      url: CFG.reverseGeocodeUrl,
      method: 'POST',
      dataType: 'json',
      data: { _csrf_token: csrf(), latitude: String(lat), longitude: String(lng) },
      timeout: 12000
    }).done(function (res) {
      if (seq !== locSeq) {
        finish(false, null);
        return;
      }
      if (!res || !res.success) {
        finish(false, ajaxErrorMsg(res, null, null));
        return;
      }
      var a = res.data ? (res.data.address || {}) : {};
      finish(applyAddressToForm(a) > 0, null);
    }).fail(function (xhr) {
      finish(false, ajaxErrorMsg(null, xhr, null));
    });
  }

  /* ------------------------------------------------------------- form */

  var $form = $modal.find('[data-at-dl-form]');

  function field(name) {
    return $form.find('[name="' + name + '"]');
  }

  var DEFAULT_PINNOTE = 'Location confirmed on map — complete the address details.';

  function setCoords(lat, lng, confirmed, noteText) {
    var ok = confirmed && validCoord(lat, lng);
    field('latitude').val(ok ? String(lat) : '');
    field('longitude').val(ok ? String(lng) : '');
    field('location_confirmed').val(ok ? '1' : '0');
    var $note = $modal.find('[data-at-dl-pinnote]');
    $note.find('[data-at-dl-pinnote-text]').text(noteText || DEFAULT_PINNOTE);
    $note.prop('hidden', !ok);
  }

  function clearCoords() {
    setCoords(0, 0, false);
    $modal.find('[data-at-dl-pinnote]').prop('hidden', true);
  }

  // Fields the customer has typed into are never overwritten by geocoded
  // values. autoFilled/origValues track what the pipeline wrote so a later
  // selection can refresh it — or restore the pre-fill value when the new
  // location does not provide that component (no stale text left behind).
  var userEdited = {};
  var autoFilled = {};
  var origValues = {};
  $form.on('input change', 'input[name], textarea[name], select[name]', function () {
    userEdited[this.name] = true;
    delete autoFilled[this.name];
  });

  var ADDR_FIELD_MAX = { house_number: 100, building_name: 150, street: 150, area: 150, landmark: 191, city: 128, state: 128 };

  /* Shared autofill for all three location methods. Accepts any normalized
     address shape — Google Place Details (building_name/postcode), the
     reverse-geocode router (street/area/pincode) or a raw suggestion — and
     returns how many form fields were populated. Missing components are left
     blank (or restored to their pre-fill value); nothing is fabricated. */
  function applyAddressToForm(addr) {
    addr = addr || {};
    var map = {
      house_number: addr.house_number,
      building_name: addr.building_name || addr.premise,
      street: addr.street || addr.road,
      area: addr.area || addr.sublocality || addr.suburb || addr.neighbourhood,
      landmark: addr.landmark,
      city: addr.city,
      state: addr.state,
      pincode: addr.pincode || addr.postcode
    };
    var filled = 0;
    $.each(map, function (name, raw) {
      var $f = field(name);
      if (!$f.length || userEdited[name]) return;
      var v = raw === null || raw === undefined ? '' : String(raw).trim();
      if (name === 'pincode') {
        v = v.replace(/\D/g, '').slice(0, 6);
      } else if (ADDR_FIELD_MAX[name]) {
        v = v.slice(0, ADDR_FIELD_MAX[name]);
      }
      if (v !== '') {
        if (origValues[name] === undefined) origValues[name] = $f.val();
        $f.val(v);
        autoFilled[name] = v;
        filled++;
      } else if (autoFilled[name] !== undefined) {
        $f.val(origValues[name] !== undefined ? origValues[name] : '');
        delete autoFilled[name];
      }
    });
    return filled;
  }

  $modal.on('click', '[data-at-dl-manual]', function () {
    beginSelection();
    selectedPlaceId = null;
    showMsg('', null);
    showStep('form');
  });

  $modal.on('click', '[data-at-dl-map]', function () {
    showMsg('', null);
    showStep('map');
  });

  $modal.on('click', '[data-at-dl-back]', function () {
    showMsg('', null);
    showStep('choose');
  });

  $modal.on('click', '[data-at-dl-mapconfirm]', function () {
    if (!mapCoords || !validCoord(mapCoords.lat, mapCoords.lng)) return;
    var lat = mapCoords.lat, lng = mapCoords.lng;
    var seq = beginSelection();
    selectedPlaceId = null;
    setCoords(lat, lng, true);
    var $btn = $(this);
    $btn.prop('disabled', true).text('Checking address…');
    reverseFill(lat, lng, seq, function (filled, errMsg) {
      $btn.prop('disabled', false).text('Confirm location');
      if (seq !== locSeq) return;
      if (!filled) {
        showMsg(errMsg || 'We could not fetch the address details for this location — please fill them in below.', 'warn');
      } else {
        showMsg('', null);
      }
      showStep('form');
    });
  });

  /* ------------------------------------------------------------- save */

  var CHECK_ICON_SVG = '<svg class="w-3 h-3" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';

  function selectedTagHtml() {
    return CHECK_ICON_SVG + ' Selected';
  }

  /* Keep the saved-address list in sync with the session selection: exactly one
     item carries .is-selected + the Selected tag, matching data.selected_saved_id. */
  function updateSavedSelection(data) {
    var selId = data && data.selected_saved_id !== null && data.selected_saved_id !== undefined
      ? String(data.selected_saved_id) : '';
    $modal.find('[data-at-dl-saved]').each(function () {
      var $b = $(this);
      var on = selId !== '' && String($b.attr('data-at-dl-saved')) === selId;
      $b.toggleClass('is-selected', on);
      if (on) {
        $b.attr('aria-current', 'true');
        var $top = $b.find('.at-dl__saved-top');
        var $tag = $top.find('.at-dl__tag--selected');
        if (!$tag.length) {
          $tag = $('<span class="at-dl__tag at-dl__tag--selected">').html(selectedTagHtml());
          $top.append($tag);
        }
      } else {
        $b.removeAttr('aria-current');
        $b.find('.at-dl__tag--selected').remove();
      }
    });
  }

  /* Tell cart/checkout UIs a new authoritative quote (or a failure) is available. */
  function notifyCartQuote(cart, cartError) {
    $(document).trigger('at:delivery-address-changed', [cart || null, cartError || null]);
  }

  function notifyCartQuoteError(message) {
    notifyCartQuote(null, message);
  }

  /* On the checkout page, preselect the radio matching the session selection and
     re-run the server quote so pay button + summary stay consistent. */
  function syncCheckoutSelection(data) {
    var selId = data && data.selected_saved_id !== null && data.selected_saved_id !== undefined
      ? String(data.selected_saved_id) : '';
    var $radio = selId !== ''
      ? $('input[name="address_id"][form="checkout-form"][value="' + selId.replace(/"/g, '') + '"]')
      : $();
    if ($radio.length) {
      $radio.prop('checked', true);
      // .prop('checked') does not emit change, so card selection styling and
      // quote refresh listeners would go stale. Dispatch a bubbling change.
      var radioEl = $radio[0];
      if (radioEl && radioEl.dispatchEvent) {
        radioEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    if (typeof window.AT_CHECKOUT_REFRESH_QUOTE === 'function') {
      window.AT_CHECKOUT_REFRESH_QUOTE();
    }
  }

  function handleSaved(res) {
    var data = res && res.data ? res.data : {};
    if (data.header) updateHeader(data.header.title, data.header.subtitle);
    updateSavedSelection(data);
    notifyCartQuote(data.cart, data.cart_error);
    syncCheckoutSelection(data);
    var svc = data.serviceable;
    if (svc === false) {
      // Address kept — be honest that we cannot confirm delivery there yet.
      showMsg((data.service_message || 'We do not deliver to this location yet.') + ' Your address is saved and still selected.', 'warn');
      showStep('choose');
      updateCurrentBanner(data);
      return;
    }
    updateCurrentBanner(data);
    var uiToast = (window.VeggieTokri && window.VeggieTokri.toast) ? window.VeggieTokri : window.ApniTokri;
    var msg = data.service_message || 'Delivery address updated.';
    if (uiToast && uiToast.toast && msg) uiToast.toast(msg, 'success');
    showMsg('', null);
    closeSelector();
  }

  function updateCurrentBanner(data) {
    var $cur = $modal.find('[data-at-dl-current]');
    if (!$cur.length) {
      $cur = $('<div class="at-dl__current" data-at-dl-current>' +
        '<span class="at-dl__tag" data-at-dl-current-tag>Current</span>' +
        '<div class="at-dl__current-text"><strong data-at-dl-current-title></strong>' +
        '<span data-at-dl-current-line></span>' +
        '<span class="at-dl__status" data-at-dl-current-status hidden></span></div></div>');
      $modal.find('[data-at-dl-step="choose"]').prepend($cur);
    }
    $cur.find('[data-at-dl-current-title]').text(data.header && data.header.title ? data.header.title : 'Delivery address');
    $cur.find('[data-at-dl-current-tag]').text(data.selected_kind === 'saved' ? 'Saved' : 'Current');
    $cur.find('[data-at-dl-current-line]').text(data.address_line || '');
    var $status = $cur.find('[data-at-dl-current-status]');
    if (data.serviceable === true) {
      $status.prop('hidden', false).attr('class', 'at-dl__status at-dl__status--ok')
        .text('Delivery available' + (data.service_zone ? ' · ' + data.service_zone : ''));
    } else if (data.serviceable === false) {
      $status.prop('hidden', false).attr('class', 'at-dl__status at-dl__status--warn')
        .text(data.service_message || 'We do not deliver here yet.');
    } else {
      $status.prop('hidden', true).text('');
    }
  }

  function closeSelector() {
    var $backdrop = $('#at-delivery-modal');
    $backdrop.removeClass('is-open').attr('aria-hidden', 'true');
    $backdrop.find('.at-modal').off('keydown.atTrap');
    if (!$('.at-modal-backdrop.is-open').length) {
      $('body').removeClass('at-modal-open');
    }
  }

  var saveSeq = 0;

  function postSave(payload, $busyBtn) {
    payload._csrf_token = csrf();
    var seq = ++saveSeq;
    var $controls = $modal.find('[data-at-dl-saved], [data-at-dl-save]');
    $controls.prop('disabled', true);
    if ($busyBtn) $busyBtn.addClass('is-busy');
    $.ajax({
      url: CFG.saveUrl,
      method: 'POST',
      dataType: 'json',
      data: payload
    }).done(function (res) {
      if (seq !== saveSeq) return;
      if (!res || !res.success) {
        var m = (res && res.error && res.error.message) || 'Could not save the address. Please try again.';
        showMsg(m, 'err');
        // Field-validation failures leave the previous selection (and its quote) intact.
        if (!(res && res.errors)) {
          notifyCartQuoteError(m);
        }
        return;
      }
      handleSaved(res);
    }).fail(function (xhr) {
      if (seq !== saveSeq) return;
      var m = 'Could not save the address. Please try again.';
      if (xhr && xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) {
        m = xhr.responseJSON.error.message;
      }
      showMsg(m, 'err');
      if (!(xhr && xhr.responseJSON && xhr.responseJSON.errors)) {
        notifyCartQuoteError(m);
      }
      if (xhr && xhr.responseJSON && xhr.responseJSON.errors) {
        var $err = $modal.find('[data-at-dl-formerr]');
        $err.prop('hidden', false).text(m);
      }
    }).always(function () {
      $controls.prop('disabled', false);
      if ($busyBtn) $busyBtn.removeClass('is-busy');
    });
  }

  $form.on('submit', function (e) {
    e.preventDefault();
    var $err = $modal.find('[data-at-dl-formerr]');
    $err.prop('hidden', true).text('');
    var payload = {};
    $form.serializeArray().forEach(function (f) { payload[f.name] = f.value; });
    postSave(payload, $modal.find('[data-at-dl-save]'));
  });

  $modal.on('click', '[data-at-dl-saved]', function () {
    postSave({ saved_address_id: $(this).attr('data-at-dl-saved') }, $(this));
  });

  /* ------------------------------------------------------------- open */

  $(document).on('click', '[data-at-open-modal="#at-delivery-modal"], [data-pin-change]', function () {
    $('body').addClass('at-modal-open');
    beginSelection();
    selectedPlaceId = null;
    showMsg('', null);
    hideResults();
    $search.val('');
    showStep('choose');
  });
})(window.jQuery, window, document);
