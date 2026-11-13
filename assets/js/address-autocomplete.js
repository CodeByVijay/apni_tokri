/**
 * Address autocomplete — server-side Google Places or Photon (admin-configured).
 * Never embed API keys; uses CSRF POST to /ajax/address/*.
 */
(function ($, window, document) {
  'use strict';
  if (!$) return;

  var CFG = window.AT_ADDRESS_AUTOCOMPLETE || {};
  if (CFG.searchEnabled !== true && CFG.enabled !== true) {
    return;
  }

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m && m.getAttribute ? (m.getAttribute('content') || '') : '';
  }

  function ajaxUrl(key) {
    var u = CFG[key];
    if (u && typeof u === 'string' && u.indexOf('/ajax/address/') !== -1) {
      return u;
    }
    var path = window.location.pathname;
    var idx = path.indexOf('/ajax/');
    if (idx !== -1) return path.substring(0, idx);
    var last = path.lastIndexOf('/');
    var base = last > 0 ? path.substring(0, last) : '';
    var fallback = '/ajax/address/autocomplete-search.php';
    if (key === 'googlePlaceDetailsUrl') fallback = '/ajax/address/google-place-details.php';
    else if (key === 'reverseGeocodeUrl') fallback = '/ajax/address/reverse-geocode.php';
    return base + fallback;
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var a = arguments, ctx = this;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(ctx, a); }, ms);
    };
  }

  function parseCoord(v) {
    if (v === null || v === undefined || String(v).trim() === '') return null;
    var n = parseFloat(v);
    return isFinite(n) ? n : null;
  }

  function validCoord(lat, lng) {
    return typeof lat === 'number' && typeof lng === 'number' && isFinite(lat) && isFinite(lng) &&
      lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
  }

  function sessionToken() {
    if (!window.crypto || !crypto.getRandomValues) return String(Date.now()) + '-' + Math.random();
    var b = new Uint8Array(16);
    crypto.getRandomValues(b);
    var h = '';
    for (var i = 0; i < b.length; i++) h += (b[i] < 16 ? '0' : '') + b[i].toString(16);
    return h;
  }

  function initAddressForm(form) {
    var $form = $(form);
    if ($form.data('atAddrAcInit')) return;
    $form.data('atAddrAcInit', true);

    var tok = sessionToken();

    var $searchIn = $form.find('[data-addr-ac-search]');
    var $searchList = $form.find('[data-addr-ac-list]');
    var $searchStatus = $form.find('[data-addr-ac-status]');
    var $searchBusy = $form.find('[data-addr-ac-busy]');
    var $lat = $form.find('input[name="latitude"]');
    var $lng = $form.find('input[name="longitude"]');
    var $conf = $form.find('input[name="location_confirmed"]');
    var $msg = $form.find('[data-addr-map-msg]');

    var $house = $form.find('[name="house_number"]');
    var $building = $form.find('[name="building_name"]');
    var $street = $form.find('[name="street"]');
    var $area = $form.find('[name="area"]');
    var $city = $form.find('[name="addr_city"]');
    var $state = $form.find('[name="addr_state"]');
    var $pin = $form.find('[name="pincode"]');
    var $hintDetected = $form.find('[data-addr-detected-hint]');
    var $btnGps = $form.find('[data-addr-gps]');

    var minChars = typeof CFG.minChars === 'number' ? CFG.minChars : 3;
    var debounceMs = typeof CFG.debounceMs === 'number' ? CFG.debounceMs : 400;

    var seq = 0;
    var results = [];
    var active = -1;
    var selectedPlaceQuery = null;
    var detailsPending = false;

    function setMsg(text, kind) {
      if (!$msg.length) return;
      $msg.text(text || '').attr('data-kind', kind || 'info').prop('hidden', !text);
    }

    function syncLabelType() {
      var v = $form.find('input[name="label_type"]:checked').val() || 'Home';
      $form.find('[data-addr-label-custom]').prop('hidden', v !== 'Other');
      $form.find('.at-addr-type').removeClass('is-selected');
      $form.find('input[name="label_type"]:checked').closest('.at-addr-type').addClass('is-selected');
    }

    $form.on('change', 'input[name="label_type"]', syncLabelType);
    syncLabelType();

    var $placeFields = $house.add($building).add($street).add($area).add($city).add($state).add($pin);

    function clearCoords() {
      $lat.val('');
      $lng.val('');
      $conf.val('0');
    }

    function clearPlaceDerivedFields() {
      $placeFields.val('').removeAttr('data-addr-detected');
      if ($hintDetected.length) $hintDetected.prop('hidden', true);
    }

    function clearPlaceSelection() {
      selectedPlaceQuery = null;
      clearCoords();
      clearPlaceDerivedFields();
      setMsg('');
    }

    function showDetectedHint() {
      if ($hintDetected.length) $hintDetected.prop('hidden', false);
    }

    function setPlaceField($el, val) {
      if (!$el.length) return;
      if (val === null || val === undefined) return;
      var s = String(val).trim();
      if (s === '') return;
      $el.val(s);
    }

    function fillFromGooglePlace(addr) {
      addr = addr || {};
      var detected = 0;
      function fill($el, val) {
        if (!$el.length || val === null || val === undefined) return;
        var s = String(val).trim();
        if (s === '') return;
        $el.val(s).attr('data-addr-detected', '1');
        detected++;
      }
      fill($house, addr.house_number);
      fill($building, addr.building_name);
      fill($street, addr.street);
      fill($area, addr.area);
      fill($city, addr.city);
      fill($state, addr.state);
      if (addr.postcode) {
        var p = String(addr.postcode).replace(/\D/g, '');
        if (p.length === 6) fill($pin, p);
      }
      if (detected > 0) showDetectedHint();
    }

    function missingRequiredFields() {
      var missing = [];
      if (!$.trim($house.val() || '')) missing.push('house / flat number');
      if (!$.trim($street.val() || '')) missing.push('street / road');
      if (!$.trim($area.val() || '')) missing.push('area / locality');
      if (!$.trim($city.val() || '')) missing.push('city');
      if (!$.trim($state.val() || '')) missing.push('state');
      if (!$.trim($pin.val() || '')) missing.push('pincode');
      return missing;
    }

    function afterPlaceFill() {
      var missing = missingRequiredFields();
      if (missing.length) {
        setMsg('Location selected. Please enter: ' + missing.join(', ') + '.', 'warn');
      } else {
        setMsg('Location selected — please verify all fields before saving.', 'info');
      }
    }

    function writeCoords(lat, lng) {
      $lat.val(lat.toFixed(7));
      $lng.val(lng.toFixed(7));
      $conf.val('1');
    }

    function hideList() {
      active = -1;
      results = [];
      $searchList.empty().prop('hidden', true);
      $searchIn.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    }

    function setStatus(text, kind) {
      if (!text) {
        $searchStatus.text('').attr('data-kind', '').prop('hidden', true);
        return;
      }
      $searchStatus.text(text).attr('data-kind', kind || 'info').prop('hidden', false);
    }

    function highlight(i) {
      $searchList.find('[role="option"]').removeClass('is-active').attr('aria-selected', 'false');
      active = i;
      var $opts = $searchList.find('[role="option"]');
      if (i >= 0 && i < $opts.length) {
        var $o = $opts.eq(i);
        $o.addClass('is-active').attr('aria-selected', 'true');
        var oid = $o.attr('id');
        if (oid) $searchIn.attr('aria-activedescendant', oid);
        $searchIn.attr('aria-expanded', 'true');
      }
    }

    var runSearch = debounce(function () {
      var q = $.trim($searchIn.val() || '');
      if (q.length < minChars) {
        seq += 1;
        hideList();
        setStatus('', '');
        $searchBusy.prop('hidden', true);
        return;
      }
      seq += 1;
      var my = seq;
      $searchBusy.prop('hidden', false);
      setStatus('', '');
      $.ajax({
        url: ajaxUrl('autocompleteSearchUrl'),
        method: 'POST',
        dataType: 'json',
        data: { _csrf_token: csrfToken(), q: q, session_token: tok }
      }).done(function (res) {
        if (my !== seq) return;
        $searchBusy.prop('hidden', true);
        if (!res || !res.success || !res.data) {
          setStatus((res && res.error && res.error.message) || 'Search could not complete.', 'warn');
          hideList();
          return;
        }
        var list = res.data.suggestions || [];
        if (!list.length) {
          setStatus('No suggestions. Type more or fill the address manually.', 'info');
          hideList();
          return;
        }
        results = list;
        setStatus('', '');
        $searchList.empty().prop('hidden', false);
        $searchIn.attr('aria-expanded', 'true');
        var uid = ($form.attr('id') || 'af') + '-ac-opt-';
        $.each(list, function (i, s) {
          var main = (s.description != null) ? String(s.description) : '';
          var cat = (s.category != null) ? String(s.category) : '';
          var note = (s.relevance_note != null) ? String(s.relevance_note) : '';
          var dis = s.disabled === true;
          var id = uid + i;
          var $li = $('<li role="option" tabindex="-1"></li>').attr('id', id)
            .attr('data-idx', String(i))
            .attr('aria-disabled', dis ? 'true' : 'false')
            .toggleClass('at-addr-search__item--disabled', dis);
          var $row = $('<div class="at-addr-search__item-row"></div>');
          $row.append($('<span class="at-addr-search__item-main"></span>').text(main));
          if (cat) $row.append($('<span class="at-addr-search__item-cat"></span>').text(cat));
          $li.append($row);
          if (note) $li.append($('<span class="at-addr-search__item-note"></span>').text(note));
          $searchList.append($li);
        });
        highlight(-1);
      }).fail(function (xhr) {
        if (my !== seq) return;
        $searchBusy.prop('hidden', true);
        hideList();
        var msg = 'Search failed. You can type the address manually.';
        try {
          var jr = xhr.responseJSON;
          if (jr && jr.error && jr.error.message) msg = String(jr.error.message);
        } catch (e) { /* ignore */ }
        setStatus(msg, xhr.status === 429 ? 'warn' : 'error');
      });
    }, debounceMs);

    function applySelection(index) {
      if (index < 0 || index >= results.length) return;
      var s = results[index];
      if (s.disabled) return;

      clearCoords();
      setMsg('');
      clearPlaceDerivedFields();
      detailsPending = true;

      if (s.provider === 'google' && s.needs_details) {
        setMsg('Loading address details…', 'info');
        $.ajax({
          url: ajaxUrl('googlePlaceDetailsUrl'),
          method: 'POST',
          dataType: 'json',
          data: { _csrf_token: csrfToken(), place_id: s.place_id, session_token: tok }
        }).done(function (res) {
          detailsPending = false;
          if (!res || !res.success || !res.data || !res.data.address) {
            clearCoords();
            setMsg((res && res.error && res.error.message) || 'Could not load this place.', 'warn');
            selectedPlaceQuery = null;
            return;
          }
          var a = res.data.address;
          var lat = parseCoord(a.latitude);
          var lng = parseCoord(a.longitude);
          if (!validCoord(lat, lng)) {
            clearCoords();
            setMsg('This place has no coordinates. Choose another result.', 'warn');
            selectedPlaceQuery = null;
            return;
          }
          writeCoords(lat, lng);
          fillFromGooglePlace(a);
          selectedPlaceQuery = $.trim($searchIn.val() || '');
          afterPlaceFill();
          $form.trigger('input');
          tok = sessionToken();
        }).fail(function (xhr) {
          detailsPending = false;
          clearCoords();
          selectedPlaceQuery = null;
          var msg = 'Could not load place details.';
          try {
            var jr = xhr.responseJSON;
            if (jr && jr.error && jr.error.message) msg = String(jr.error.message);
          } catch (e) { /* ignore */ }
          setMsg(msg, 'warn');
        });
        return;
      }

      var plat = parseCoord(s.latitude);
      var plng = parseCoord(s.longitude);
      detailsPending = false;
      if (!validCoord(plat, plng)) {
        clearCoords();
        setMsg('This suggestion has no coordinates. Try another.', 'warn');
        selectedPlaceQuery = null;
        return;
      }
      writeCoords(plat, plng);
      fillFromGooglePlace(s);
      selectedPlaceQuery = $.trim($searchIn.val() || '');
      afterPlaceFill();
      $form.trigger('input');
    }

    if ($btnGps.length) {
      var gpsBusy = false;
      var gpsReset = function () {
        gpsBusy = false;
        $btnGps.prop('disabled', false).removeClass('is-busy');
        $btnGps.find('[data-label]').text('Use my current location');
      };
      var geoErrorMsg = function (err) {
        var code = err && typeof err.code === 'number' ? err.code : 0;
        if (code === 1) {
          return 'Please allow location access in your browser settings, then try again. Or search / enter your address manually.';
        }
        return 'Your current location could not be detected. Please try again or search your address.';
      };
      $btnGps.on('click', function (e) {
        e.preventDefault();
        if (gpsBusy) return;
        if (window.isSecureContext === false || !navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
          setMsg('Your browser does not support location detection on this connection. Please enter the address manually.', 'warn');
          return;
        }
        gpsBusy = true;
        $btnGps.prop('disabled', true).addClass('is-busy');
        $btnGps.find('[data-label]').text('Finding your location…');
        setMsg('Finding your location…', 'info');
        navigator.geolocation.getCurrentPosition(function (pos) {
          var lat = pos && pos.coords ? Number(pos.coords.latitude) : NaN;
          var lng = pos && pos.coords ? Number(pos.coords.longitude) : NaN;
          if (!validCoord(lat, lng)) {
            gpsReset();
            setMsg('Your current location could not be detected. Please try again or search your address.', 'warn');
            return;
          }
          setMsg('Location found — looking up address details…', 'info');
          $.ajax({
            url: ajaxUrl('reverseGeocodeUrl'),
            method: 'POST',
            dataType: 'json',
            timeout: 15000,
            data: { _csrf_token: csrfToken(), latitude: lat.toFixed(7), longitude: lng.toFixed(7) }
          }).done(function (res) {
            gpsReset();
            if (!res || !res.success || !res.data || !res.data.address) {
              setMsg('Location detected, but address details could not be loaded. Please search or enter your address manually.', 'warn');
              return;
            }
            var ra = res.data.address;
            clearPlaceDerivedFields();
            writeCoords(lat, lng);
            selectedPlaceQuery = null;
            fillFromGooglePlace({
              street: ra.street,
              area: ra.area,
              city: ra.city,
              state: ra.state,
              postcode: ra.pincode
            });
            var missing = missingRequiredFields();
            if (missing.length) {
              setMsg('Your current location was detected. Please complete: ' + missing.join(', ') + ', then verify before saving.', 'warn');
            } else {
              setMsg('Your current location was detected. Please verify your address details.', 'info');
            }
            $form.trigger('input');
          }).fail(function (xhr) {
            gpsReset();
            var msg = 'Location detected, but address details could not be loaded. Please search or enter your address manually.';
            var status = xhr && typeof xhr.status === 'number' ? xhr.status : 0;
            var jr = null;
            try { jr = xhr && xhr.responseJSON; } catch (e) { /* ignore */ }
            if (jr && jr.error && jr.error.message) {
              msg = String(jr.error.message);
            } else if (status === 0) {
              msg = 'Could not reach the server. Please check your connection and try again.';
            } else if (status === 403) {
              msg = 'Your session has expired. Please reload the page and try again.';
            }
            setMsg(msg, 'warn');
          });
        }, function (geoErr) {
          gpsReset();
          setMsg(geoErrorMsg(geoErr), 'warn');
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
      });
    }

    // Editing a detected field clears its "filled from search" marker.
    $placeFields.on('input', function () {
      $(this).removeAttr('data-addr-detected');
    });

    // GPS / manual-entry forms may not render the search box at all.
    if ($searchIn.length === 0 || $searchList.length === 0 || (CFG.searchEnabled !== true && CFG.enabled !== true)) {
      return;
    }

    $searchIn.on('input', function () {
      var q = $.trim($searchIn.val() || '');
      if (selectedPlaceQuery !== null && q !== selectedPlaceQuery) {
        clearPlaceSelection();
      }
      if (q.length < minChars) {
        seq += 1;
        hideList();
        setStatus('', '');
        $searchBusy.prop('hidden', true);
        return;
      }
      runSearch();
    });

    $searchIn.on('keydown', function (e) {
      if ($searchList.prop('hidden')) return;
      var max = results.length - 1;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(active < 0 ? 0 : Math.min(max, active + 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(active <= 0 ? -1 : active - 1);
      } else if (e.key === 'Enter') {
        if (active >= 0) {
          e.preventDefault();
          applySelection(active);
          hideList();
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        hideList();
      }
    });

    $searchList.on('mousedown', '[role="option"]', function (e) {
      e.preventDefault();
      var idx = parseInt($(this).attr('data-idx'), 10);
      if (!isNaN(idx)) {
        applySelection(idx);
        hideList();
      }
    });
  }

  $(function () {
    $('[data-at-address-form][data-addr-checkout-autocomplete="1"]').each(function () {
      initAddressForm(this);
    });
  });
})(window.jQuery, window, document);
