/**
 * Checkout address autocomplete — server-side Google Places or Photon (admin-configured).
 * Never embed API keys; uses CSRF POST to /ajax/address/*.
 */
(function ($, window, document) {
  'use strict';
  if (!$) return;

  var CFG = window.AT_ADDRESS_AUTOCOMPLETE || {};
  if (!CFG.enabled) return;

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m && m.getAttribute ? (m.getAttribute('content') || '') : '';
  }

  function appBasePrefix() {
    var path = window.location.pathname;
    var idx = path.indexOf('/ajax/');
    if (idx !== -1) return path.substring(0, idx);
    var last = path.lastIndexOf('/');
    return last > 0 ? path.substring(0, last) : '';
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

  function initCheckoutForm(form) {
    var $form = $(form);
    if ($form.data('atAddrAcInit')) return;
    $form.data('atAddrAcInit', true);

    var tok = sessionToken();
    var provider = CFG.provider || 'photon';

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
    var $landmark = $form.find('[name="landmark"]');
    var $instr = $form.find('[name="delivery_instructions"]');
    var $hintDetected = $form.find('[data-addr-detected-hint]');

    var minChars = typeof CFG.minChars === 'number' ? CFG.minChars : 3;
    var debounceMs = typeof CFG.debounceMs === 'number' ? CFG.debounceMs : 400;

    var seq = 0;
    var results = [];
    var active = -1;

    function setMsg(text, kind) {
      if (!$msg.length) return;
      $msg.text(text || '').attr('data-kind', kind || 'info').prop('hidden', !text);
    }

    function showDetectedHint() {
      if ($hintDetected.length) $hintDetected.prop('hidden', false);
    }

    function setOnlyIfEmpty($el, val) {
      if (!$el.length || val === null || val === undefined) return;
      var s = String(val).trim();
      if (s === '') return;
      if ($.trim($el.val() || '') !== '') return;
      $el.val(s);
      showDetectedHint();
    }

    function fillFromNormalized(addr) {
      addr = addr || {};
      if (addr.house_number) setOnlyIfEmpty($house, addr.house_number);
      if (addr.street) setOnlyIfEmpty($street, addr.street);
      if (addr.area) setOnlyIfEmpty($area, addr.area);
      if (addr.city) setOnlyIfEmpty($city, addr.city);
      if (addr.state) setOnlyIfEmpty($state, addr.state);
      if (addr.postcode) {
        var p = String(addr.postcode).replace(/\D/g, '');
        if (p.length === 6) setOnlyIfEmpty($pin, p);
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
        url: appBasePrefix() + '/ajax/address/autocomplete-search',
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
          var dis = s.disabled === true || s.needs_details === false && s.latitude === null && s.longitude === null && (s.provider === 'photon' ? false : false);
          if (s.disabled === true) dis = true;
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

      if (s.provider === 'google' && s.needs_details) {
        setMsg('Loading address details…', 'info');
        $.ajax({
          url: appBasePrefix() + '/ajax/address/google-place-details',
          method: 'POST',
          dataType: 'json',
          data: { _csrf_token: csrfToken(), place_id: s.place_id, session_token: tok }
        }).done(function (res) {
          if (!res || !res.success || !res.data || !res.data.address) {
            setMsg((res && res.error && res.error.message) || 'Could not load this place.', 'warn');
            return;
          }
          var a = res.data.address;
          var lat = parseCoord(a.latitude);
          var lng = parseCoord(a.longitude);
          if (!validCoord(lat, lng)) {
            setMsg('This place has no coordinates. Choose another result.', 'warn');
            return;
          }
          writeCoords(lat, lng);
          fillFromNormalized(a);
          setMsg('Location selected — please verify all fields before saving.', 'info');
          $form.trigger('input');
          tok = sessionToken();
        }).fail(function (xhr) {
          var msg = 'Could not load place details.';
          try {
            var jr = xhr.responseJSON;
            if (jr && jr.error && jr.error.message) msg = String(jr.error.message);
          } catch (e) { /* ignore */ }
          setMsg(msg, 'warn');
        });
        return;
      }

      // Photon (coords + partial fields on row)
      var plat = parseCoord(s.latitude);
      var plng = parseCoord(s.longitude);
      if (!validCoord(plat, plng)) {
        setMsg('This suggestion has no coordinates. Try another.', 'warn');
        return;
      }
      writeCoords(plat, plng);
      fillFromNormalized(s);
      setMsg('Location selected — please verify all fields before saving.', 'info');
      $form.trigger('input');
    }

    $searchIn.on('input', function () {
      var q = $.trim($searchIn.val() || '');
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

    // Never overwrite manual typing in non-search fields
    $building.add($landmark).add($instr).on('input', function () { /* no-op hook for clarity */ });
  }

  $(function () {
    $('[data-at-address-form][data-addr-checkout-autocomplete="1"]').each(function () {
      initCheckoutForm(this);
    });
  });
})(window.jQuery, window, document);
