/* Admin assisted order creation — Phase 1 UI (customer, address, line items draft). */
(function () {
    'use strict';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function fmtMoney(n) {
        var x = Number(n);
        if (!isFinite(x)) { x = 0; }
        return '₹' + x.toFixed(2);
    }

    function rootEl() {
        return document.getElementById('at-assisted-order');
    }

    function fetchJson(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = options.headers || {};
        options.headers.Accept = 'application/json';
        options.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, options).then(function (r) { return r.json(); });
    }

    function init() {
        var root = rootEl();
        if (!root) { return; }

        var csrf = root.getAttribute('data-csrf') || '';
        var csrfField = root.getAttribute('data-csrf-field') || '_csrf_token';
        var urlCustomers = root.getAttribute('data-url-customers') || '';
        var urlAddresses = root.getAttribute('data-url-addresses') || '';
        var urlAddressCreate = root.getAttribute('data-url-address-create') || '';
        var urlAddressPreview = root.getAttribute('data-url-address-preview') || '';
        var urlVariants = root.getAttribute('data-url-variants') || '';
        var urlLine = root.getAttribute('data-url-line') || '';
        var urlQuote = root.getAttribute('data-url-quote') || '';
        var urlPlace = root.getAttribute('data-url-place') || '';
        var slotsEnabled = root.getAttribute('data-slots-enabled') === '1';

        var state = {
            customer: null,
            addressId: null,
            selectedVariant: null,
            selectedVariantLabel: '',
            lines: [],
            customerPage: 1,
            variantPage: 1,
            addressSubmissionKey: '',
            selectedAddress: null,
            discountMode: 'none',
            addrMode: 'existing',
            allowDuplicateOnce: false,
            receiverPrefill: { name: '', phone: '' }
        };

        var customerSearch = document.getElementById('at-aorder-customer-search');
        var customerResults = root.querySelector('[data-at-aorder-customer-results]');
        var customerStatus = root.querySelector('[data-at-aorder-customer-status]');
        var customerChip = root.querySelector('[data-at-aorder-customer-chip]');
        var customerIdInput = root.querySelector('[data-at-aorder-customer-id]');
        var customerPager = root.querySelector('[data-at-aorder-customer-pager]');
        var customerSummary = root.querySelector('[data-at-aorder-customer-summary]');
        var addressSection = root.querySelector('[data-at-aorder-address-section]');
        var addressList = root.querySelector('[data-at-aorder-address-list]');
        var addressEmpty = root.querySelector('[data-at-aorder-address-empty]');
        var addressExistingPanel = root.querySelector('[data-at-aorder-address-existing-panel]');
        var addressForm = root.querySelector('[data-at-aorder-address-form]');
        var addressSummary = root.querySelector('[data-at-aorder-address-summary]');
        var addrModeButtons = root.querySelectorAll('[data-at-aorder-addr-mode]');
        var addrSaveBtn = root.querySelector('[data-at-aorder-addr-save]');
        var addrCancelBtn = root.querySelector('[data-at-aorder-addr-cancel]');
        var addrFormError = root.querySelector('[data-at-aorder-addr-form-error]');
        var addrLabelCustomWrap = root.querySelector('[data-at-aorder-addr-label-custom-wrap]');
        var addrDupes = root.querySelector('[data-at-aorder-addr-dupes]');
        var addrPreview = root.querySelector('[data-at-aorder-addr-preview]');
        var pinPreview = root.querySelector('[data-at-aorder-pin-preview]');
        var receiverNameInput = addressForm ? addressForm.querySelector('[name="receiver_name"]') : null;
        var receiverPhoneInput = addressForm ? addressForm.querySelector('[name="receiver_phone"]') : null;
        var addrLatInput = addressForm ? addressForm.querySelector('[name="latitude"]') : null;
        var addrLngInput = addressForm ? addressForm.querySelector('[name="longitude"]') : null;
        var addrPinInput = addressForm ? addressForm.querySelector('[name="pincode"]') : null;
        var pinPreviewSeq = 0;
        var pinPreviewTimer = null;
        var addrPreviewSeq = 0;

        var variantSearch = document.getElementById('at-aorder-variant-search');
        var variantResults = root.querySelector('[data-at-aorder-variant-results]');
        var variantStatus = root.querySelector('[data-at-aorder-variant-status]');
        var variantPager = root.querySelector('[data-at-aorder-variant-pager]');
        var variantPreview = root.querySelector('[data-at-aorder-variant-preview]');
        var qtyInput = document.getElementById('at-aorder-qty');
        var addBtn = root.querySelector('[data-at-aorder-add-line]');
        var itemsBody = root.querySelector('[data-at-aorder-items-body]');
        var itemsEmpty = root.querySelector('[data-at-aorder-items-empty]');
        var applyDelivery = root.querySelector('[data-at-aorder-apply-delivery]');
        var freeDelivery = root.querySelector('[data-at-aorder-free-delivery]');
        var freeReasonWrap = root.querySelector('[data-at-aorder-free-reason-wrap]');
        var freeReason = root.querySelector('[data-at-aorder-free-reason]');
        var deliverySummary = root.querySelector('[data-at-aorder-delivery-summary]');
        var couponInput = root.querySelector('[data-at-aorder-coupon]');
        var couponApply = root.querySelector('[data-at-aorder-coupon-apply]');
        var couponClear = root.querySelector('[data-at-aorder-coupon-clear]');
        var couponStatus = root.querySelector('[data-at-aorder-coupon-status]');
        var schedSlot = root.querySelector('[data-at-aorder-sched-slot]');
        var schedCustom = root.querySelector('[data-at-aorder-sched-custom]');
        var slotPanel = root.querySelector('[data-at-aorder-slot-panel]');
        var customDeliveryWrap = root.querySelector('[data-at-aorder-custom-delivery-wrap]');
        var customDate = root.querySelector('[data-at-aorder-custom-date]');
        var customStart = root.querySelector('[data-at-aorder-custom-start]');
        var customEnd = root.querySelector('[data-at-aorder-custom-end]');
        var customDeliveryReason = root.querySelector('[data-at-aorder-custom-delivery-reason]');
        var payPending = root.querySelector('[data-at-aorder-pay-pending]');
        var pendingPayHint = root.querySelector('[data-at-aorder-pending-pay-hint]');
        var payOffline = root.querySelector('[data-at-aorder-pay-offline]');
        var offlineWrap = root.querySelector('[data-at-aorder-offline-wrap]');
        var offlineMethod = root.querySelector('[data-at-aorder-offline-method]');
        var offlineAmount = root.querySelector('[data-at-aorder-offline-amount]');
        var offlineRef = root.querySelector('[data-at-aorder-offline-ref]');
        var offlinePayDate = root.querySelector('[data-at-aorder-offline-pay-date]');
        var offlinePayTime = root.querySelector('[data-at-aorder-offline-pay-time]');
        var offlineProvider = root.querySelector('[data-at-aorder-offline-provider]');
        var offlineNote = root.querySelector('[data-at-aorder-offline-note]');
        var offlineConfirm = root.querySelector('[data-at-aorder-offline-confirm]');
        var canRecordOffline = root.getAttribute('data-can-record-offline') === '1';
        var appliedCoupon = '';
        var couponApplyBusy = false;
        var discountNone = root.querySelector('[data-at-aorder-discount-none]');
        var discountCouponMode = root.querySelector('[data-at-aorder-discount-coupon-mode]');
        var discountCustomMode = root.querySelector('[data-at-aorder-discount-custom-mode]');
        var discountCouponPanel = root.querySelector('[data-at-aorder-discount-coupon-panel]');
        var discountCustomPanel = root.querySelector('[data-at-aorder-discount-custom-panel]');
        var customType = root.querySelector('[data-at-aorder-custom-type]');
        var customValue = root.querySelector('[data-at-aorder-custom-value]');
        var customDiscountReason = root.querySelector('[data-at-aorder-custom-discount-reason]');
        var quoteStatus = root.querySelector('[data-at-aorder-quote-status]');
        var quoteDl = root.querySelector('[data-at-aorder-quote-dl]');
        var reviewItems = root.querySelector('[data-at-aorder-review-items]');
        var paySummary = root.querySelector('[data-at-aorder-pay-summary]');
        var refreshQuoteBtn = root.querySelector('[data-at-aorder-refresh-quote]');
        var placeBtn = root.querySelector('[data-at-aorder-place]');
        var placeResult = root.querySelector('[data-at-aorder-place-result]');
        var slotSelect = root.querySelector('[data-at-aorder-delivery-slot]');
        var quoteTimer = null;
        var lastQuote = null;
        // True while a new quote is in flight or the inputs changed since the
        // last successful quote — order creation is blocked in that window.
        var quoteStale = true;

        var stepPanels = Array.prototype.slice.call(root.querySelectorAll('[data-at-aorder-step-panel]'));
        var stepNavBtns = Array.prototype.slice.call(root.querySelectorAll('[data-at-aorder-stepnav]'));
        var currentStep = 1;
        var orderPlaced = false;
        var placedPaymentMode = null;
        var itemsWrap = root.querySelector('[data-at-aorder-items-wrap]');
        var itemsEmptyState = root.querySelector('[data-at-aorder-items-emptystate]');
        var itemsMeta = root.querySelector('[data-at-aorder-items-meta]');
        var addPanel = root.querySelector('[data-at-aorder-add-panel]');
        var lineFeedback = root.querySelector('[data-at-aorder-line-feedback]');
        var offlineGateHint = root.querySelector('[data-at-aorder-offline-gate-hint]');
        var minOrderEls = [
            root.querySelector('[data-at-aorder-minorder]'),
            root.querySelector('[data-at-aorder-minorder4]')
        ];
        var minOverrideWrap = root.querySelector('[data-at-aorder-min-override]');
        var minOverrideToggle = root.querySelector('[data-at-aorder-min-override-toggle]');
        var minOverridePanel = root.querySelector('[data-at-aorder-min-override-panel]');
        var minOverrideSummary = root.querySelector('[data-at-aorder-min-override-summary]');
        var minOverrideReason = root.querySelector('[data-at-aorder-min-override-reason]');
        var minOverrideNoteWrap = root.querySelector('[data-at-aorder-min-override-note-wrap]');
        var minOverrideNote = root.querySelector('[data-at-aorder-min-override-note]');
        var minOverrideConfirm = root.querySelector('[data-at-aorder-min-override-confirm]');
        var canMinOverride = root.getAttribute('data-can-min-override') === '1';
        var offlineTouched = {};
        var offlineShowAllErrors = false;
        var nextDeliveryBtn = root.querySelector('[data-at-aorder-next="3"]');
        var deliveryWhen = root.querySelector('[data-at-aorder-delivery-when]');
        var reviewDl = root.querySelector('[data-at-aorder-review]');
        var qtyDecBtn = root.querySelector('[data-at-aorder-qty-dec]');
        var qtyIncBtn = root.querySelector('[data-at-aorder-qty-inc]');

        var customerTimer = null;
        var customerSeq = 0;
        var variantTimer = null;
        var variantSeq = 0;

        function randomHex(byteCount) {
            var arr = new Uint8Array(byteCount);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(arr);
            } else {
                for (var i = 0; i < byteCount; i++) { arr[i] = Math.floor(Math.random() * 256); }
            }
            return Array.from(arr, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        }

        function clearCustomer() {
            state.customer = null;
            state.addressId = null;
            state.selectedAddress = null;
            state.addressSubmissionKey = '';
            state.allowDuplicateOnce = false;
            customerIdInput.value = '';
            customerChip.innerHTML = '';
            customerChip.appendChild(el('span', 'text-sm at-muted', 'No customer selected.'));
            customerSummary.hidden = true;
            addressSection.hidden = true;
            addressList.innerHTML = '';
            if (addressSummary) { addressSummary.hidden = true; addressSummary.textContent = ''; }
            if (addressForm) { addressForm.hidden = true; }
            hideAddrDupes();
            if (addrPreview) { addrPreview.hidden = true; addrPreview.textContent = ''; }
            updateAddButton();
            updateStepNav();
        }

        function clearAddressFieldErrors() {
            root.querySelectorAll('[data-at-aorder-err]').forEach(function (node) {
                node.hidden = true;
                node.textContent = '';
            });
            if (addrFormError) { addrFormError.hidden = true; addrFormError.textContent = ''; }
        }

        function showAddressFieldErrors(errors) {
            clearAddressFieldErrors();
            if (!errors || typeof errors !== 'object') { return; }
            Object.keys(errors).forEach(function (key) {
                var node = root.querySelector('[data-at-aorder-err="' + key + '"]');
                if (node) {
                    node.textContent = errors[key];
                    node.hidden = false;
                }
            });
            if (addrFormError && errors.form) {
                addrFormError.textContent = errors.form;
                addrFormError.hidden = false;
            }
        }

        function setAddressMode(mode) {
            var isNew = mode === 'new';
            state.addrMode = isNew ? 'new' : 'existing';
            addrModeButtons.forEach(function (btn) {
                var m = btn.getAttribute('data-at-aorder-addr-mode');
                var active = m === mode;
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                btn.classList.toggle('at-btn--secondary', active);
                btn.classList.toggle('at-btn--ghost', !active);
            });
            if (addressExistingPanel) { addressExistingPanel.hidden = isNew; }
            hideAddrDupes();
            if (isNew) {
                // Keep the modes visually separate: no saved-address summary while typing a new address.
                if (addressSummary) { addressSummary.hidden = true; }
                if (addrPreview) { addrPreview.hidden = true; }
            } else {
                updateAddressSummary(state.selectedAddress);
            }
            if (addressForm) {
                addressForm.hidden = !isNew;
                if (isNew && state.addressSubmissionKey === '') {
                    state.addressSubmissionKey = randomHex(16);
                }
            }
        }

        function updateAddressSummary(addr) {
            if (!addressSummary) { return; }
            if (!addr || state.addrMode === 'new') {
                addressSummary.hidden = true;
                if (!addr) { addressSummary.innerHTML = ''; }
                return;
            }
            addressSummary.hidden = false;
            addressSummary.innerHTML = '';
            addressSummary.appendChild(el('p', 'font-semibold mb-1', 'Selected address'));
            var title = (addr.label || 'Address') + (addr.is_default ? ' (Default)' : '');
            addressSummary.appendChild(el('p', '', title));
            if (addr.receiver_name) {
                addressSummary.appendChild(el('p', 'at-muted', addr.receiver_name + (addr.receiver_phone ? ' · ' + addr.receiver_phone : '')));
            }
            addressSummary.appendChild(el('p', '', addr.full_address || ''));
        }

        function appendAddressRadio(addr, forceSelect) {
            var id = 'at-aorder-addr-' + addr.id;
            var wrap = el('label', 'flex gap-2 p-3 border border-gray-200 rounded-lg cursor-pointer hover:border-green-600');
            wrap.setAttribute('for', id);
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'address_id';
            radio.id = id;
            radio.value = String(addr.id);
            radio.className = 'mt-1';
            radio.addEventListener('change', function () {
                selectAddress(addr);
            });
            if (forceSelect || addr.is_default) {
                radio.checked = true;
                selectAddress(addr);
            }
            var body = el('div', 'text-sm flex-1');
            var title = (addr.label || 'Address') + (addr.is_default ? ' (Default)' : '');
            body.appendChild(el('p', 'font-semibold', title));
            if (addr.receiver_name) {
                body.appendChild(el('p', 'at-muted', addr.receiver_name + (addr.receiver_phone ? ' · ' + addr.receiver_phone : '')));
            }
            body.appendChild(el('p', '', addr.full_address || ''));
            wrap.insertBefore(radio, wrap.firstChild);
            wrap.appendChild(body);
            addressList.appendChild(wrap);
        }

        function selectAddress(addr) {
            state.addressId = addr.id;
            state.selectedAddress = addr;
            updateAddressSummary(addr);
            previewServiceability(addr.pincode, addr.latitude, addr.longitude, addrPreview, 'addr');
            scheduleQuote();
        }

        // Delivery serviceability + zone fee preview (server-side, read-only).
        function previewServiceability(pin, lat, lng, targetEl, seqKey) {
            if (!targetEl) { return; }
            var cleanPin = String(pin || '').replace(/\D/g, '');
            if (cleanPin.length !== 6 || !urlAddressPreview) {
                targetEl.hidden = true;
                targetEl.textContent = '';
                return;
            }
            var isPin = seqKey === 'pin';
            if (isPin) { pinPreviewSeq += 1; } else { addrPreviewSeq += 1; }
            var mine = isPin ? pinPreviewSeq : addrPreviewSeq;
            var isCurrent = function () {
                return isPin ? mine === pinPreviewSeq : mine === addrPreviewSeq;
            };
            var baseCls = isPin ? 'text-xs mt-1' : 'text-sm';
            targetEl.textContent = 'Checking delivery availability…';
            targetEl.className = baseCls + ' at-muted';
            targetEl.hidden = false;
            var body = new FormData();
            body.append(csrfField, csrf);
            body.append('pincode', cleanPin);
            if (lat) { body.append('latitude', String(lat)); }
            if (lng) { body.append('longitude', String(lng)); }
            fetchJson(urlAddressPreview, { method: 'POST', body: body }).then(function (j) {
                if (!isCurrent()) { return; }
                if (!j.success || !j.data) {
                    targetEl.textContent = (j.error && j.error.message) || 'Could not check delivery availability.';
                    targetEl.className = baseCls + ' text-red-700';
                    return;
                }
                var d = j.data;
                if (d.serviceable) {
                    var feeBits = [];
                    if (d.flat_fee != null) {
                        var f = 'delivery fee ' + fmtMoney(d.flat_fee);
                        if (d.free_above != null && d.free_above > 0) {
                            f += ' · free above ' + fmtMoney(d.free_above);
                        }
                        feeBits.push(f);
                    }
                    targetEl.textContent = (d.message || 'This address is serviceable.')
                        + (feeBits.length ? ' — ' + feeBits.join(' · ') : '');
                    targetEl.className = baseCls + ' text-green-800';
                } else {
                    var txt = d.message || 'This pincode is not serviceable.';
                    if (d.requires_map_pin) { txt += ' A confirmed map location may be required.'; }
                    targetEl.textContent = txt;
                    targetEl.className = baseCls + ' text-red-700';
                }
            }).catch(function () {
                if (isCurrent()) {
                    targetEl.textContent = 'Could not check delivery availability.';
                    targetEl.className = baseCls + ' text-red-700';
                }
            });
        }

        function hideAddrDupes() {
            if (addrDupes) {
                addrDupes.hidden = true;
                addrDupes.innerHTML = '';
            }
        }

        function showAddrDupes(dupes, msg) {
            if (!addrDupes) { return; }
            addrDupes.innerHTML = '';
            addrDupes.appendChild(el('p', 'font-semibold mb-2', msg || 'A similar address is already saved for this customer.'));
            var list = el('div', 'space-y-2 mb-3');
            dupes.forEach(function (d) {
                var row = el('div', 'flex flex-wrap items-center justify-between gap-2 p-2 rounded border border-amber-200 bg-white');
                var info = el('div', 'text-sm min-w-0');
                info.appendChild(el('p', 'font-semibold mb-0', (d.label || 'Address') + (d.is_default ? ' (Default)' : '')));
                info.appendChild(el('p', 'at-muted mb-0', d.full_address || ''));
                var use = el('button', 'at-btn at-btn--secondary at-btn--sm at-focusable', 'Use this address');
                use.type = 'button';
                use.addEventListener('click', function () {
                    hideAddrDupes();
                    setAddressMode('existing');
                    loadAddresses(state.customer.id, d.id);
                });
                row.appendChild(info);
                row.appendChild(use);
                list.appendChild(row);
            });
            addrDupes.appendChild(list);
            var anyway = el('button', 'at-btn at-btn--ghost at-btn--sm at-focusable', 'Save anyway as a new address');
            anyway.type = 'button';
            anyway.addEventListener('click', function () {
                state.allowDuplicateOnce = true;
                hideAddrDupes();
                saveNewAddress();
            });
            addrDupes.appendChild(anyway);
            addrDupes.hidden = false;
        }

        function prefillReceiverFields() {
            if (!state.customer) { return; }
            var name = state.customer.name || '';
            var phone = state.customer.mobile || '';
            if (receiverNameInput) {
                var curName = receiverNameInput.value.trim();
                if (curName === '' || curName === state.receiverPrefill.name) {
                    receiverNameInput.value = name;
                }
            }
            if (receiverPhoneInput) {
                var curPhone = receiverPhoneInput.value.trim();
                if (curPhone === '' || curPhone === state.receiverPrefill.phone) {
                    receiverPhoneInput.value = phone;
                }
            }
            state.receiverPrefill = { name: name, phone: phone };
        }

        function setCustomer(c) {
            state.customer = c;
            customerIdInput.value = String(c.id);
            customerChip.innerHTML = '';
            var chip = el('span', 'at-picker__chip');
            chip.appendChild(el('span', '', c.name || c.mobile || 'Customer #' + c.id));
            var x = el('button', 'at-picker__chip-x at-focusable', '×');
            x.type = 'button';
            x.setAttribute('aria-label', 'Clear customer');
            x.addEventListener('click', function () {
                clearCustomer();
                customerResults.hidden = true;
            });
            chip.appendChild(x);
            customerChip.appendChild(chip);
            customerSummary.hidden = false;
            customerSummary.textContent = [
                c.mobile ? 'Mobile: ' + c.mobile : '',
                c.email ? 'Email: ' + c.email : '',
                c.is_active ? '' : 'Inactive customer'
            ].filter(Boolean).join(' · ');
            customerResults.hidden = true;
            prefillReceiverFields();
            loadAddresses(c.id);
            updateAddButton();
            updateStepNav();
        }

        function renderPager(container, page, totalPages, onPage) {
            container.innerHTML = '';
            if (totalPages <= 1) {
                container.hidden = true;
                return;
            }
            container.hidden = false;
            if (page > 1) {
                var prev = el('button', 'at-btn at-btn--ghost at-btn--sm at-focusable', 'Previous');
                prev.type = 'button';
                prev.addEventListener('click', function () { onPage(page - 1); });
                container.appendChild(prev);
            }
            container.appendChild(el('span', 'at-muted', 'Page ' + page + ' of ' + totalPages));
            if (page < totalPages) {
                var next = el('button', 'at-btn at-btn--ghost at-btn--sm at-focusable', 'Next');
                next.type = 'button';
                next.addEventListener('click', function () { onPage(page + 1); });
                container.appendChild(next);
            }
        }

        function runCustomerSearch(page) {
            var q = customerSearch.value.trim();
            state.customerPage = page || 1;
            if (q.length < 2) {
                customerResults.hidden = true;
                customerStatus.textContent = 'Type at least 2 characters to search.';
                customerPager.hidden = true;
                return;
            }
            var mine = ++customerSeq;
            customerStatus.textContent = 'Searching…';
            var u = urlCustomers + '?q=' + encodeURIComponent(q) + '&page=' + state.customerPage;
            fetchJson(u).then(function (j) {
                if (mine !== customerSeq) { return; }
                if (!j.success) {
                    customerStatus.textContent = (j.error && j.error.message) || 'Search failed.';
                    return;
                }
                var data = j.data || {};
                var items = data.items || [];
                customerStatus.textContent = items.length ? 'Select a customer.' : 'No customers found.';
                customerResults.innerHTML = '';
                items.forEach(function (row) {
                    var li = el('li', 'at-picker__item' + (row.selectable ? '' : ' is-disabled'));
                    li.setAttribute('role', 'option');
                    if (!row.selectable) {
                        li.setAttribute('aria-disabled', 'true');
                    }
                    var body = el('span', 'at-picker__body');
                    body.appendChild(el('strong', '', row.name || row.mobile || 'Customer'));
                    var sub = [row.mobile, row.email, row.status_label].filter(Boolean).join(' · ');
                    if (sub) { body.appendChild(el('small', 'at-muted', sub)); }
                    li.appendChild(body);
                    if (row.selectable) {
                        li.tabIndex = 0;
                        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        li.addEventListener('click', function () {
                            setCustomer(row);
                            customerSearch.value = '';
                        });
                    } else {
                        body.appendChild(el('small', 'text-red-700', 'Cannot select inactive customer'));
                    }
                    customerResults.appendChild(li);
                });
                customerResults.hidden = false;
                renderPager(customerPager, data.page || 1, data.total_pages || 1, function (p) {
                    runCustomerSearch(p);
                });
            }).catch(function () {
                if (mine === customerSeq) { customerStatus.textContent = 'Search failed. Check your connection.'; }
            });
        }

        customerSearch.addEventListener('input', function () {
            clearTimeout(customerTimer);
            customerTimer = setTimeout(function () { runCustomerSearch(1); }, 280);
        });
        customerSearch.addEventListener('focus', function () {
            if (customerSearch.value.trim().length >= 2) { runCustomerSearch(state.customerPage); }
        });
        document.addEventListener('click', function (e) {
            if (!customerResults.contains(e.target) && e.target !== customerSearch) {
                customerResults.hidden = true;
            }
            if (variantResults && !variantResults.contains(e.target) && e.target !== variantSearch) {
                variantResults.hidden = true;
                if (variantSearch) { variantSearch.setAttribute('aria-expanded', 'false'); }
            }
        });

        function loadAddresses(customerId, selectId) {
            addressSection.hidden = false;
            addressList.innerHTML = '';
            addressEmpty.hidden = true;
            state.addressId = null;
            state.selectedAddress = null;
            updateAddressSummary(null);
            setAddressMode('existing');
            fetchJson(urlAddresses + '?customer_id=' + encodeURIComponent(customerId)).then(function (j) {
                if (!j.success) {
                    addressEmpty.hidden = false;
                    addressEmpty.textContent = (j.error && j.error.message) || 'Could not load addresses.';
                    setAddressMode('new');
                    return;
                }
                var items = (j.data && j.data.items) || [];
                if (!items.length) {
                    addressEmpty.hidden = false;
                    addressEmpty.textContent = 'No saved addresses for this customer. Add a new address below.';
                    setAddressMode('new');
                    return;
                }
                var picked = false;
                items.forEach(function (addr) {
                    var force = selectId != null && Number(selectId) === Number(addr.id);
                    if (force) { picked = true; }
                    appendAddressRadio(addr, force);
                });
                if (!picked && state.addressId) {
                    var sel = items.filter(function (a) { return a.id === state.addressId; })[0];
                    if (sel) { selectAddress(sel); }
                }
                updateActionButtons();
            });
        }

        function buildAddressCreateBody() {
            var body = new FormData();
            body.append(csrfField, csrf);
            body.append('customer_id', String(state.customer.id));
            body.append('address_submission_key', state.addressSubmissionKey || randomHex(16));
            if (!state.addressSubmissionKey) { state.addressSubmissionKey = body.get('address_submission_key'); }
            if (!addressForm) { return body; }
            addressForm.querySelectorAll('input, textarea, select').forEach(function (field) {
                if (!field.name) { return; }
                if (field.type === 'radio' && !field.checked) { return; }
                if (field.type === 'checkbox') {
                    if (field.checked) { body.append(field.name, field.value || '1'); }
                    return;
                }
                body.append(field.name, field.value);
            });
            if (state.allowDuplicateOnce) { body.append('allow_duplicate', '1'); }
            return body;
        }

        function saveNewAddress() {
            if (!state.customer || !urlAddressCreate) { return; }
            clearAddressFieldErrors();
            hideAddrDupes();
            if (addrSaveBtn) { addrSaveBtn.disabled = true; }
            fetchJson(urlAddressCreate, { method: 'POST', body: buildAddressCreateBody() }).then(function (j) {
                if (addrSaveBtn) { addrSaveBtn.disabled = false; }
                if (j && j.success && j.data && j.data.duplicate_warning) {
                    showAddrDupes(j.data.duplicates || [], j.data.message || '');
                    return;
                }
                if (!j.success) {
                    showAddressFieldErrors(j.errors || {});
                    if (j.error && j.error.message && addrFormError) {
                        addrFormError.textContent = j.error.message;
                        addrFormError.hidden = false;
                    }
                    return;
                }
                var addr = (j.data && j.data.address) || null;
                if (!addr) { return; }
                // Reset the idempotency key so a second new address is not silently deduped.
                state.addressSubmissionKey = '';
                state.allowDuplicateOnce = false;
                hideAddrDupes();
                setAddressMode('existing');
                addressEmpty.hidden = true;
                loadAddresses(state.customer.id, addr.id);
            }).catch(function () {
                if (addrSaveBtn) { addrSaveBtn.disabled = false; }
                if (addrFormError) {
                    addrFormError.textContent = 'Could not save address.';
                    addrFormError.hidden = false;
                }
            });
        }

        function clearVariantSelection() {
            state.selectedVariant = null;
            state.selectedVariantLabel = '';
            showVariantPreview(null);
            updateAddButton();
        }

        function showLineFeedback(msg, isError) {
            if (!lineFeedback) { return; }
            if (!msg) {
                lineFeedback.hidden = true;
                lineFeedback.textContent = '';
                return;
            }
            lineFeedback.textContent = msg;
            lineFeedback.className = 'text-xs mt-2 font-medium ' + (isError ? 'text-red-700' : 'text-green-800');
            lineFeedback.hidden = false;
        }

        function variantAvailInfo(v) {
            var avail = v.available_qty != null ? parseInt(v.available_qty, 10) : parseInt(v.stock_qty, 10);
            if (!isFinite(avail)) { avail = 0; }
            if (!v.selectable || v.purchasable === false) {
                return { qty: avail, label: v.unavailable_reason || 'Unavailable', cls: 'at-aorder-badge--danger', canAdd: false };
            }
            if (avail <= 0) {
                return { qty: 0, label: 'Out of stock', cls: 'at-aorder-badge--danger', canAdd: false };
            }
            if (avail <= 5) {
                return { qty: avail, label: 'Low stock (' + avail + ' left)', cls: 'at-aorder-badge--warn', canAdd: true };
            }
            return { qty: avail, label: 'In stock (' + avail + ' available)', cls: 'at-aorder-badge--ok', canAdd: true };
        }

        function showVariantPreview(v) {
            if (addPanel) { addPanel.hidden = !v; }
            if (!v) {
                variantPreview.innerHTML = '';
                return;
            }
            variantPreview.innerHTML = '';
            var row = el('div', 'at-aorder-addpanel__product');
            var media = el('div', 'at-aorder-addpanel__media');
            if (v.image_url) {
                var img = document.createElement('img');
                img.src = v.image_url;
                img.alt = '';
                img.className = 'at-aorder-addpanel__img';
                img.addEventListener('error', function () {
                    media.innerHTML = '';
                    media.appendChild(el('span', 'at-aorder-addpanel__img at-aorder-addpanel__img--empty', '—'));
                });
                media.appendChild(img);
            } else {
                media.appendChild(el('span', 'at-aorder-addpanel__img at-aorder-addpanel__img--empty', '—'));
            }
            row.appendChild(media);

            var text = el('div', 'at-aorder-addpanel__text');
            text.appendChild(el('p', 'at-aorder-addpanel__title', v.product_name));
            var subLine = el('p', 'at-aorder-addpanel__variant');
            subLine.appendChild(el('span', 'font-medium', v.variant_name || 'Standard'));
            if (v.sku) {
                subLine.appendChild(el('span', 'at-muted text-xs', ' · SKU ' + v.sku));
            }
            text.appendChild(subLine);

            var av = variantAvailInfo(v);
            var chips = el('div', 'at-aorder-addpanel__chips');
            var priceSpan = el('span', 'at-aorder-addpanel__price', fmtMoney(v.unit_price));
            if (v.on_sale && v.regular_price > v.unit_price) {
                var reg = el('s', 'at-muted text-xs font-normal ml-1', fmtMoney(v.regular_price));
                priceSpan.appendChild(reg);
            }
            chips.appendChild(priceSpan);
            chips.appendChild(el('span', 'at-aorder-badge ' + av.cls, av.label));
            text.appendChild(chips);

            row.appendChild(text);
            variantPreview.appendChild(row);
            if (qtyInput) {
                qtyInput.value = '1';
                if (av.qty > 0) { qtyInput.max = String(av.qty); } else { qtyInput.removeAttribute('max'); }
            }
        }

        function selectVariantItem(v) {
            showLineFeedback('', false);
            state.selectedVariant = v;
            state.selectedVariantLabel = v.product_name + ' — ' + v.variant_name;
            variantSearch.value = state.selectedVariantLabel;
            variantResults.hidden = true;
            variantPager.hidden = true;
            variantSearch.setAttribute('aria-expanded', 'false');
            var av = variantAvailInfo(v);
            variantStatus.textContent = 'Selected: ' + state.selectedVariantLabel + ' · ' + fmtMoney(v.unit_price) + ' · ' + av.label;
            variantStatus.className = 'text-xs mt-1 ' + (av.canAdd ? 'text-green-800' : 'text-red-700');
            showVariantPreview(v);
            updateAddButton();
        }

        function renderVariantResultItem(v) {
            var av = variantAvailInfo(v);
            var canPick = !!(v.selectable && av.canAdd);
            var li = el('li', 'at-picker__item at-picker__item--variant' + (canPick ? '' : ' is-disabled'));
            li.setAttribute('role', 'option');
            if (v.image_url) {
                var thumb = document.createElement('img');
                thumb.src = v.image_url;
                thumb.alt = '';
                thumb.className = 'at-picker__thumb';
                thumb.addEventListener('error', function () {
                    var ph = el('span', 'at-picker__thumb at-picker__thumb--empty', '—');
                    if (thumb.parentNode) { thumb.parentNode.replaceChild(ph, thumb); }
                });
                li.appendChild(thumb);
            } else {
                li.appendChild(el('span', 'at-picker__thumb at-picker__thumb--empty', '—'));
            }
            var body = el('span', 'at-picker__body');
            body.appendChild(el('strong', '', v.product_name));
            body.appendChild(el('span', 'text-sm', v.variant_name));
            var sub = [
                v.sku ? 'SKU ' + v.sku : '',
                fmtMoney(v.unit_price),
                av.label
            ].filter(Boolean).join(' · ');
            body.appendChild(el('small', 'at-muted', sub));
            li.appendChild(body);
            if (v.selectable) {
                li.tabIndex = 0;
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () { selectVariantItem(v); });
                li.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectVariantItem(v);
                    }
                });
            }
            return li;
        }

        function runVariantSearch(page) {
            var q = variantSearch.value.trim();
            state.variantPage = page || 1;
            if (q.length < 2) {
                clearVariantSelection();
                variantResults.hidden = true;
                variantStatus.textContent = 'Type at least 2 characters.';
                variantStatus.className = 'text-xs at-muted mt-1';
                variantPager.hidden = true;
                if (variantSearch) { variantSearch.setAttribute('aria-expanded', 'false'); }
                return;
            }
            if (state.selectedVariant && q !== state.selectedVariantLabel) {
                clearVariantSelection();
            }
            var mine = ++variantSeq;
            variantStatus.textContent = 'Searching…';
            fetchJson(urlVariants + '?q=' + encodeURIComponent(q) + '&page=' + state.variantPage).then(function (j) {
                if (mine !== variantSeq) { return; }
                if (!j.success) {
                    variantStatus.textContent = (j.error && j.error.message) || 'Search failed.';
                    return;
                }
                var data = j.data || {};
                var items = data.items || [];
                variantStatus.textContent = items.length ? 'Select a variant from the list below.' : 'No products found.';
                variantStatus.className = 'text-xs at-muted mt-1';
                variantResults.innerHTML = '';
                if (!items.length) {
                    variantResults.appendChild(el('li', 'at-picker__empty', 'No matching variants.'));
                }
                items.forEach(function (v) {
                    variantResults.appendChild(renderVariantResultItem(v));
                });
                variantResults.hidden = false;
                if (variantSearch) { variantSearch.setAttribute('aria-expanded', 'true'); }
                renderPager(variantPager, data.page || 1, data.total_pages || 1, function (p) {
                    runVariantSearch(p);
                });
            }).catch(function () {
                if (mine === variantSeq) { variantStatus.textContent = 'Search failed.'; }
            });
        }

        variantSearch.addEventListener('input', function () {
            clearTimeout(variantTimer);
            variantTimer = setTimeout(function () { runVariantSearch(1); }, 280);
        });
        variantSearch.addEventListener('focus', function () {
            if (variantSearch.value.trim().length >= 2) {
                runVariantSearch(state.variantPage);
            }
        });

        function setAorderPanelVisible(node, visible) {
            if (!node) { return; }
            // Use both [hidden] and a class — Tailwind .grid can override UA [hidden] alone.
            node.hidden = !visible;
            node.classList.toggle('at-aorder-is-hidden', !visible);
            node.setAttribute('aria-hidden', visible ? 'false' : 'true');
        }

        function getDiscountMode() {
            if (discountCouponMode && discountCouponMode.checked) { return 'coupon'; }
            if (discountCustomMode && discountCustomMode.checked) { return 'custom'; }
            return 'none';
        }

        function clearCouponState() {
            appliedCoupon = '';
            if (couponInput) { couponInput.value = ''; }
            if (couponStatus) { couponStatus.hidden = true; couponStatus.textContent = ''; }
            if (couponClear) { couponClear.hidden = true; }
        }

        function clearCustomDiscountFields() {
            if (customValue) { customValue.value = ''; }
            if (customDiscountReason) { customDiscountReason.value = ''; }
            if (customType) { customType.value = 'flat'; }
        }

        function toggleDeliveryModeFields() {
            var custom = schedCustom && schedCustom.checked;
            setAorderPanelVisible(slotPanel, !custom);
            setAorderPanelVisible(customDeliveryWrap, !!custom);
        }

        function togglePaymentModeFields() {
            var offline = payOffline && payOffline.checked && canRecordOffline;
            setAorderPanelVisible(offlineWrap, !!offline);
            setAorderPanelVisible(pendingPayHint, !offline);
            if (offline && lastQuote && offlineAmount) {
                offlineAmount.value = String(lastQuote.grand_total);
            }
            updatePlaceButtonLabel();
        }

        function toggleDiscountModeFields() {
            var mode = getDiscountMode();
            state.discountMode = mode;
            setAorderPanelVisible(discountCouponPanel, mode === 'coupon');
            setAorderPanelVisible(discountCustomPanel, mode === 'custom');
            if (couponApply) {
                couponApply.disabled = mode !== 'coupon' || couponApplyBusy || !couponInput || couponInput.value.trim() === '';
            }
        }

        function switchDiscountMode(nextMode, skipConfirm) {
            var prev = state.discountMode;
            if (prev === nextMode) {
                toggleDiscountModeFields();
                return;
            }
            var removing = (appliedCoupon !== '' || (customValue && parseFloat(customValue.value) > 0))
                && (prev === 'coupon' || prev === 'custom');
            if (!skipConfirm && removing) {
                if (!window.confirm('Switching discount mode will remove the current discount from the quote. Continue?')) {
                    if (prev === 'none' && discountNone) { discountNone.checked = true; }
                    if (prev === 'coupon' && discountCouponMode) { discountCouponMode.checked = true; }
                    if (prev === 'custom' && discountCustomMode) { discountCustomMode.checked = true; }
                    return;
                }
                clearCouponState();
                clearCustomDiscountFields();
                if (canQuote()) { refreshQuote(); }
            } else if (nextMode === 'none') {
                clearCouponState();
                clearCustomDiscountFields();
            } else if (nextMode === 'coupon') {
                clearCustomDiscountFields();
            } else if (nextMode === 'custom') {
                clearCouponState();
            }
            state.discountMode = nextMode;
            toggleDiscountModeFields();
            if (canQuote()) { scheduleQuote(); }
        }

        function updateAddButton() {
            var av = state.selectedVariant ? variantAvailInfo(state.selectedVariant) : null;
            var ok = state.selectedVariant && state.selectedVariant.variant_id > 0
                && av && av.canAdd
                && state.customer && state.customer.is_active !== false;
            addBtn.disabled = !ok;
        }

        function updateItemsMeta() {
            var count = state.lines.reduce(function (s, l) { return s + (parseInt(l.quantity, 10) || 0); }, 0);
            var sub = state.lines.reduce(function (s, l) { return s + (parseFloat(l.line_total) || 0); }, 0);
            if (itemsMeta) {
                itemsMeta.textContent = count > 0
                    ? count + ' item(s) · merchandise subtotal ' + fmtMoney(sub)
                    : 'No items added yet.';
            }
            if (itemsWrap) { itemsWrap.hidden = state.lines.length === 0; }
            if (itemsEmptyState) { itemsEmptyState.hidden = state.lines.length !== 0; }
            if (nextDeliveryBtn) { nextDeliveryBtn.disabled = state.lines.length === 0; }
            updateStepNav();
        }

        function renderLines() {
            itemsBody.innerHTML = '';
            updateItemsMeta();
            if (!state.lines.length) {
                itemsBody.appendChild(itemsEmpty);
                itemsEmpty.hidden = false;
                quoteDl.hidden = true;
                scheduleQuote();
                return;
            }
            itemsEmpty.hidden = true;
            state.lines.forEach(function (line, idx) {
                var tr = el('tr', '');
                var tdProduct = el('td', 'font-semibold', line.product_name);
                tdProduct.setAttribute('data-label', 'Product');
                tr.appendChild(tdProduct);
                var tdVariant = el('td', '', line.variant_name);
                tdVariant.setAttribute('data-label', 'Variant');
                tr.appendChild(tdVariant);
                var tdSku = el('td', 'is-id', line.sku || '—');
                tdSku.setAttribute('data-label', 'SKU');
                tr.appendChild(tdSku);
                var up = el('td', 'is-num', fmtMoney(line.unit_price));
                up.setAttribute('data-label', 'Unit price');
                tr.appendChild(up);
                var qTd = el('td', 'is-num');
                qTd.setAttribute('data-label', 'Qty');
                var qWrap = el('span', 'at-qty-stepper at-qty-stepper--sm');
                var qMinus = el('button', 'at-qty-stepper__btn at-focusable', '−');
                qMinus.type = 'button';
                qMinus.setAttribute('aria-label', 'Decrease quantity');
                var qIn = el('input', 'at-input at-focusable at-qty-stepper__input');
                qIn.type = 'number';
                qIn.min = '1';
                if (line.available_qty != null) { qIn.max = String(line.available_qty); }
                qIn.value = String(line.quantity);
                if ((parseInt(line.quantity, 10) || 0) <= 1) { qMinus.disabled = true; }
                qMinus.addEventListener('click', function () {
                    revalidateLine(idx, Math.max(1, (parseInt(qIn.value, 10) || 1) - 1));
                });
                qIn.addEventListener('change', function () {
                    revalidateLine(idx, parseInt(qIn.value, 10) || 0);
                });
                var qPlus = el('button', 'at-qty-stepper__btn at-focusable', '+');
                qPlus.type = 'button';
                qPlus.setAttribute('aria-label', 'Increase quantity');
                qPlus.addEventListener('click', function () {
                    revalidateLine(idx, (parseInt(qIn.value, 10) || 0) + 1);
                });
                qWrap.appendChild(qMinus);
                qWrap.appendChild(qIn);
                qWrap.appendChild(qPlus);
                qTd.appendChild(qWrap);
                tr.appendChild(qTd);
                var tdTotal = el('td', 'is-num font-semibold', fmtMoney(line.line_total));
                tdTotal.setAttribute('data-label', 'Line total');
                tr.appendChild(tdTotal);
                var act = el('td', 'is-actions');
                act.setAttribute('data-label', '');
                var rm = el('button', 'at-btn at-btn--danger at-btn--sm at-focusable', 'Remove');
                rm.type = 'button';
                rm.addEventListener('click', function () {
                    state.lines.splice(idx, 1);
                    renderLines();
                });
                act.appendChild(rm);
                tr.appendChild(act);
                itemsBody.appendChild(tr);
            });
            scheduleQuote();
        }

        function buildQuoteBody() {
            var body = new FormData();
            body.append(csrfField, csrf);
            if (state.customer) { body.append('customer_id', String(state.customer.id)); }
            if (state.addressId) { body.append('address_id', String(state.addressId)); }
            body.append('lines', JSON.stringify(state.lines.map(function (l) {
                return { variant_id: l.variant_id, quantity: l.quantity };
            })));
            body.append('apply_delivery_charges', applyDelivery && applyDelivery.checked ? '1' : '0');
            body.append('free_delivery', freeDelivery && freeDelivery.checked ? '1' : '0');
            if (freeReason) { body.append('free_delivery_reason', freeReason.value.trim()); }
            var discountMode = getDiscountMode();
            body.append('discount_mode', discountMode);
            if (discountMode === 'coupon') {
                body.append('coupon_code', appliedCoupon || (couponInput ? couponInput.value.trim() : ''));
                body.append('custom_discount_enabled', '0');
            } else if (discountMode === 'custom') {
                body.append('coupon_code', '');
                body.append('custom_discount_enabled', '1');
                if (customType) { body.append('custom_discount_type', customType.value); }
                if (customValue) { body.append('custom_discount_value', customValue.value); }
                if (customDiscountReason) {
                    body.append('custom_discount_reason', customDiscountReason.value.trim());
                }
            } else {
                body.append('coupon_code', '');
                body.append('custom_discount_enabled', '0');
            }
            body.append('delivery_schedule_mode', schedCustom && schedCustom.checked ? 'custom' : 'slot');
            if (schedCustom && schedCustom.checked) {
                if (customDate) { body.append('custom_delivery_date', customDate.value); }
                if (customStart) { body.append('custom_delivery_start', customStart.value); }
                if (customEnd) { body.append('custom_delivery_end', customEnd.value); }
                if (customDeliveryReason) {
                    body.append('custom_delivery_reason', customDeliveryReason.value.trim());
                }
            }
            body.append('payment_mode', currentPaymentMode());
            if (payOffline && payOffline.checked && canRecordOffline) {
                if (offlineMethod) { body.append('offline_payment_method', offlineMethod.value); }
                if (offlineAmount) { body.append('offline_payment_amount', offlineAmount.value); }
                if (offlineRef) { body.append('offline_payment_reference', offlineRef.value.trim()); }
                if (offlinePayDate) { body.append('offline_payment_date', offlinePayDate.value); }
                if (offlinePayTime) { body.append('offline_payment_time', offlinePayTime.value); }
                if (offlineProvider) { body.append('offline_payment_provider', offlineProvider.value.trim()); }
                body.append('offline_payment_confirm', offlineConfirm && offlineConfirm.checked ? '1' : '0');
                if (offlineNote) { body.append('offline_payment_verification_note', offlineNote.value.trim()); }
            }
            if (minOverrideActive()) {
                body.append('min_order_override', '1');
                body.append('min_order_override_reason', minOverrideReason ? minOverrideReason.value : '');
                body.append('min_order_override_note', minOverrideNote ? minOverrideNote.value.trim() : '');
            }
            if (!(schedCustom && schedCustom.checked)) {
                if (slotSelect) {
                    var opt = slotSelect.options[slotSelect.selectedIndex];
                    body.append('delivery_slot', slotSelect.value);
                    body.append('delivery_date', opt ? (opt.getAttribute('data-date') || '') : '');
                } else {
                    body.append('delivery_slot', root.getAttribute('data-default-slot') || 'auto');
                    body.append('delivery_date', root.getAttribute('data-default-slot-date') || '');
                }
            }
            return body;
        }

        function canQuote() {
            return !!(state.customer && state.addressId && state.lines.length);
        }

        // Mirrors AdminAssistedOrderService::validateOfflinePaymentInput + full-amount rule.
        function getOfflineValidationErrors() {
            var errs = {};
            if (currentPaymentMode() !== 'offline_paid') { return errs; }
            var method = offlineMethod ? offlineMethod.value.trim().toLowerCase() : '';
            if (method !== 'upi' && method !== 'bank_transfer') {
                errs.method = 'Choose UPI or Bank transfer.';
            }
            var amtStr = offlineAmount ? offlineAmount.value.trim() : '';
            var amtNum = parseFloat(amtStr);
            if (amtStr === '' || !isFinite(amtNum) || amtNum < 0) {
                errs.amount = 'Amount received is required.';
            } else if (lastQuote && Math.abs(Math.round(amtNum * 100) - Math.round(Number(lastQuote.grand_total) * 100)) >= 1) {
                errs.amount = 'Amount received must match the payable total (' + fmtMoney(lastQuote.grand_total) + ').';
            }
            if (!offlineRef || offlineRef.value.trim() === '') {
                errs.reference = 'Enter the transaction or reference number.';
            }
            var dt = offlinePayDate ? offlinePayDate.value.trim() : '';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(dt)) {
                errs.date = 'Enter the payment date.';
            }
            var tm = offlinePayTime ? offlinePayTime.value.trim() : '';
            if (!/^\d{1,2}:\d{2}(:\d{2})?$/.test(tm)) {
                errs.time = 'Enter a valid payment time (HH:MM).';
            }
            if (!offlineNote || offlineNote.value.trim() === '') {
                errs.note = 'Enter a verification note for this offline payment.';
            }
            if (!offlineConfirm || !offlineConfirm.checked) {
                errs.confirm = 'Confirm that payment has been received and verified.';
            }
            return errs;
        }

        function isOfflineReady() {
            return Object.keys(getOfflineValidationErrors()).length === 0;
        }

        function renderOfflineFieldErrors() {
            var errs = getOfflineValidationErrors();
            var fieldEls = {
                method: offlineMethod,
                amount: offlineAmount,
                reference: offlineRef,
                date: offlinePayDate,
                time: offlinePayTime,
                note: offlineNote,
                confirm: offlineConfirm
            };
            var isOffline = currentPaymentMode() === 'offline_paid';
            Object.keys(fieldEls).forEach(function (k) {
                var p = root.querySelector('[data-at-aorder-offline-err="' + k + '"]');
                var input = fieldEls[k];
                var show = isOffline && (offlineShowAllErrors || offlineTouched[k]) && !!errs[k];
                if (p) {
                    p.hidden = !show;
                    p.textContent = show ? errs[k] : '';
                }
                if (input && input.type !== 'checkbox') {
                    input.classList.toggle('is-invalid', show);
                    if (show) {
                        input.setAttribute('aria-invalid', 'true');
                    } else {
                        input.removeAttribute('aria-invalid');
                    }
                }
            });
            if (offlineGateHint) {
                var needHint = isOffline && canQuote() && !!lastQuote && !quoteStale && !orderPlaced && Object.keys(errs).length > 0;
                offlineGateHint.hidden = !needHint;
                offlineGateHint.textContent = needHint
                    ? 'Complete all required offline payment fields (*) and confirm verification above to enable paid order creation.'
                    : '';
            }
        }

        // True when the latest server quote is below the minimum order value.
        // All thresholds/amounts come from the server quote — never computed here.
        function minOrderBelowMin() {
            return !!(lastQuote && lastQuote.min_order_enabled && !lastQuote.min_order_met);
        }

        // Client-side mirror of the server override gate — server re-validates
        // permission, reason and note at quote + placement regardless.
        function minOverrideActive() {
            if (!canMinOverride || !minOverrideToggle || !minOverrideToggle.checked) { return false; }
            if (!minOverrideConfirm || !minOverrideConfirm.checked) { return false; }
            var reason = minOverrideReason ? minOverrideReason.value : '';
            if (reason === '') { return false; }
            if (reason === 'other' && (!minOverrideNote || minOverrideNote.value.trim() === '')) { return false; }
            return true;
        }

        function renderMinOrder() {
            var q = lastQuote;
            var html = '', cls = 'at-aorder-minorder at-aorder-minorder--hidden';
            if (canQuote() && (quoteStale || !q)) {
                cls = 'at-aorder-minorder at-aorder-minorder--pending';
                html = '<span class="at-aorder-minorder__label">Minimum order</span>'
                    + '<span class="at-aorder-minorder__body">Checking minimum order…</span>';
            } else if (q && q.min_order_enabled) {
                var min = fmtMoney(q.min_order_amount_inr);
                var merch = fmtMoney(q.min_order_merchandise_inr);
                if (q.min_order_met) {
                    cls = 'at-aorder-minorder at-aorder-minorder--ok';
                    html = '<span class="at-aorder-minorder__label">Minimum met</span>'
                        + '<span class="at-aorder-minorder__body">Minimum order ' + min
                        + ' · eligible items ' + merch + '</span>';
                } else {
                    var short = fmtMoney(q.min_order_shortfall);
                    cls = 'at-aorder-minorder at-aorder-minorder--warn';
                    html = '<span class="at-aorder-minorder__label">Below minimum</span>'
                        + '<span class="at-aorder-minorder__body">Minimum order ' + min
                        + ' · eligible items ' + merch
                        + ' — add ' + short + ' more of merchandise to create this order.'
                        + '</span>';
                    if (q.min_order_override) {
                        html += '<span class="at-aorder-minorder__override">Minimum-order exception approved by admin'
                            + (q.min_order_override.reason_label ? ' — ' + q.min_order_override.reason_label : '') + '</span>';
                    } else if (minOverrideToggle && minOverrideToggle.checked) {
                        html += '<span class="at-aorder-minorder__override">'
                            + (minOverrideActive()
                                ? 'Override selected — totals refreshing…'
                                : 'Select a reason and confirm the override below to continue.')
                            + '</span>';
                    }
                }
            }
            minOrderEls.forEach(function (node) {
                if (!node) { return; }
                node.className = cls;
                node.innerHTML = html;
                node.hidden = html === '';
            });

            // Override panel only for below-minimum orders placed by an authorized admin.
            var showWrap = !!(minOverrideWrap && canMinOverride && !orderPlaced && q && q.min_order_enabled && !q.min_order_met);
            if (minOverrideWrap) { minOverrideWrap.hidden = !showWrap; }
            if (!showWrap && minOverrideToggle && minOverrideToggle.checked && q && q.min_order_met) {
                minOverrideToggle.checked = false;
            }
            if (minOverridePanel) {
                minOverridePanel.hidden = !(minOverrideToggle && minOverrideToggle.checked);
            }
            if (minOverrideNoteWrap) {
                minOverrideNoteWrap.hidden = !(minOverrideReason && minOverrideReason.value === 'other');
            }
            if (minOverrideSummary && q) {
                minOverrideSummary.innerHTML = '';
                [['Eligible merchandise', fmtMoney(q.min_order_merchandise_inr)],
                 ['Required minimum', fmtMoney(q.min_order_amount_inr)],
                 ['Shortfall', fmtMoney(q.min_order_shortfall)],
                 ['Delivery fee (not counted)', fmtMoney(q.delivery_fee)]].forEach(function (pair) {
                    var d = el('div', 'at-aorder-review__row');
                    d.appendChild(el('dt', 'at-aorder-review__label', pair[0]));
                    d.appendChild(el('dd', 'at-aorder-review__value', pair[1]));
                    minOverrideSummary.appendChild(d);
                });
            }
        }

        function updateActionButtons() {
            var ok = canQuote();
            var offlineBlocked = currentPaymentMode() === 'offline_paid' && !isOfflineReady();
            var minBlocked = minOrderBelowMin() && !minOverrideActive() && !(lastQuote && lastQuote.min_order_override);
            if (refreshQuoteBtn) { refreshQuoteBtn.disabled = !ok || orderPlaced; }
            if (placeBtn) { placeBtn.disabled = !ok || !lastQuote || quoteStale || offlineBlocked || minBlocked || orderPlaced; }
            renderOfflineFieldErrors();
            renderMinOrder();
        }

        function scheduleQuote() {
            clearTimeout(quoteTimer);
            if (canQuote()) { quoteStale = true; }
            quoteTimer = setTimeout(refreshQuote, 400);
            updateDeliveryWhen();
            renderReview();
            renderPaySummary();
            updateActionButtons();
            updateStepNav();
        }

        function renderQuote(q) {
            if (!q) {
                quoteDl.hidden = true;
                quoteStatus.textContent = 'Complete the sections above to calculate totals.';
                quoteStatus.className = 'text-sm at-muted';
                lastQuote = null;
                renderPaySummary();
                updateActionButtons();
                return;
            }
            lastQuote = q;
            quoteStale = false;
            quoteDl.hidden = false;
            quoteStatus.textContent = '';
            quoteDl.innerHTML = '';
            function row(label, val, strong) {
                var d = el('div', 'at-aorder-totals__row' + (strong ? ' at-aorder-totals__row--grand' : ''));
                d.appendChild(el('dt', strong ? 'at-aorder-totals__grand-label' : '', label));
                d.appendChild(el('dd', strong ? 'at-aorder-totals__grand-value' : '', val));
                quoteDl.appendChild(d);
            }
            row('Merchandise subtotal', fmtMoney(q.subtotal));
            if (q.offer_discount > 0) { row('Automatic promotion', '− ' + fmtMoney(q.offer_discount)); }
            if (q.coupon_discount > 0) { row('Coupon' + (q.coupon_code ? ' (' + q.coupon_code + ')' : ''), '− ' + fmtMoney(q.coupon_discount)); }
            if (q.custom_discount > 0) { row('Admin discount', '− ' + fmtMoney(q.custom_discount)); }
            row('Delivery fee', fmtMoney(q.delivery_fee));
            if (q.delivery_zone) {
                deliverySummary.textContent = 'Zone: ' + q.delivery_zone
                    + (q.calculated_delivery_fee !== q.delivery_fee ? ' · Calculated: ' + fmtMoney(q.calculated_delivery_fee) : '');
            }
            row('Total payable', fmtMoney(q.grand_total), true);
            if (offlineAmount && currentPaymentMode() === 'offline_paid') {
                offlineAmount.value = String(q.grand_total);
            }
            if (q.coupon_applied && q.coupon_code) {
                appliedCoupon = q.coupon_code;
                if (couponInput) { couponInput.value = q.coupon_code; }
                if (couponStatus) {
                    couponStatus.hidden = false;
                    couponStatus.className = 'text-sm text-green-800';
                    couponStatus.textContent = 'Coupon applied: ' + q.coupon_code + ' (− ' + fmtMoney(q.coupon_discount) + ')';
                }
                if (couponClear) { couponClear.hidden = false; }
            } else if (appliedCoupon === '' && couponStatus) {
                couponStatus.hidden = true;
                couponStatus.textContent = '';
                if (couponClear) { couponClear.hidden = true; }
            }
            updatePlaceButtonLabel();
            renderReview();
            renderPaySummary();
            updateActionButtons();
        }

        // Single source of truth for the selected payment mode — the review,
        // the submit-button label and the posted payload all derive from this.
        function currentPaymentMode() {
            return (payOffline && payOffline.checked && canRecordOffline) ? 'offline_paid' : 'pending';
        }

        function paymentModeLabel(mode) {
            return mode === 'offline_paid'
                ? 'Already paid — offline payment'
                : 'Pending payment — customer pays online later';
        }

        function paymentStatusLabel(mode) {
            if (orderPlaced) {
                return placedPaymentMode === 'offline_paid'
                    ? 'Paid (offline — verified)'
                    : 'Pending payment';
            }
            if (mode !== 'offline_paid') {
                return 'Pending payment';
            }
            return isOfflineReady()
                ? 'Awaiting server verification (recorded Paid on creation)'
                : 'Pending offline verification — complete required details';
        }

        function renderPaySummary() {
            if (!paySummary) { return; }
            paySummary.innerHTML = '';
            var mode = currentPaymentMode();
            function row(label, value) {
                var d = el('div', 'at-aorder-review__row');
                d.appendChild(el('dt', 'at-aorder-review__label', label));
                d.appendChild(el('dd', 'at-aorder-review__value', value));
                paySummary.appendChild(d);
            }
            row('Mode', paymentModeLabel(mode));
            row('Status', paymentStatusLabel(mode));
            if (mode === 'offline_paid') {
                var amt = offlineAmount && offlineAmount.value !== ''
                    ? offlineAmount.value
                    : (lastQuote ? lastQuote.grand_total : null);
                if (amt != null) { row('Amount received', fmtMoney(amt)); }
                var ref = offlineRef ? offlineRef.value.trim() : '';
                if (ref !== '') { row('Reference', ref); }
            }
            if (lastQuote && lastQuote.min_order_enabled && !lastQuote.min_order_met) {
                var ov = lastQuote.min_order_override;
                row('Min-order', ov
                    ? 'Exception applied — ' + (ov.reason_label || 'admin approved')
                    : 'Below minimum (' + fmtMoney(lastQuote.min_order_shortfall) + ' short)');
            }
        }

        function updatePlaceButtonLabel() {
            if (!placeBtn) { return; }
            if (currentPaymentMode() === 'offline_paid') {
                placeBtn.textContent = 'Create paid order (offline)';
            } else {
                placeBtn.textContent = 'Create order — Pending payment';
            }
        }

        function refreshQuote() {
            if (!canQuote() || !urlQuote) {
                renderQuote(null);
                return;
            }
            quoteStale = true;
            updateActionButtons();
            quoteStatus.textContent = 'Calculating totals…';
            quoteStatus.className = 'text-sm at-muted';
            if (refreshQuoteBtn) { refreshQuoteBtn.classList.add('is-loading'); }
            var done = function () {
                if (refreshQuoteBtn) { refreshQuoteBtn.classList.remove('is-loading'); }
            };
            fetchJson(urlQuote, { method: 'POST', body: buildQuoteBody() }).then(function (j) {
                done();
                if (!j.success) {
                    renderQuote(null);
                    quoteStatus.textContent = (j.error && j.error.message) || 'Quote failed.';
                    quoteStatus.className = 'text-sm text-red-700';
                    return;
                }
                renderQuote(j.data.quote);
            }).catch(function () {
                done();
                renderQuote(null);
                quoteStatus.textContent = 'Quote failed.';
                quoteStatus.className = 'text-sm text-red-700';
            });
        }

        if (applyDelivery) {
            applyDelivery.addEventListener('change', function () {
                var on = applyDelivery.checked;
                if (freeDelivery) { freeDelivery.disabled = !on; if (!on) { freeDelivery.checked = false; } }
                scheduleQuote();
            });
        }
        if (freeDelivery) {
            freeDelivery.addEventListener('change', function () {
                if (freeReasonWrap) { freeReasonWrap.hidden = !freeDelivery.checked; }
                scheduleQuote();
            });
        }
        if (freeReason) { freeReason.addEventListener('input', scheduleQuote); }
        if (couponInput) {
            couponInput.addEventListener('input', function () {
                toggleDiscountModeFields();
                if (couponInput.value.trim() !== appliedCoupon) {
                    appliedCoupon = '';
                    if (couponStatus) { couponStatus.hidden = true; couponStatus.textContent = ''; }
                    if (couponClear) { couponClear.hidden = true; }
                }
            });
        }
        if (couponApply) {
            couponApply.addEventListener('click', function () {
                if (getDiscountMode() !== 'coupon') { return; }
                if (couponApplyBusy || !canQuote()) { return; }
                couponApplyBusy = true;
                couponApply.disabled = true;
                fetchJson(urlQuote, { method: 'POST', body: buildQuoteBody() }).then(function (j) {
                    couponApplyBusy = false;
                    couponApply.disabled = !canQuote() || !couponInput || couponInput.value.trim() === '';
                    if (!j.success) {
                        if (couponStatus) {
                            couponStatus.hidden = false;
                            couponStatus.className = 'text-sm text-red-700';
                            couponStatus.textContent = (j.error && j.error.message) || 'Coupon could not be applied.';
                        }
                        return;
                    }
                    renderQuote(j.data.quote);
                }).catch(function () {
                    couponApplyBusy = false;
                    couponApply.disabled = false;
                });
            });
        }
        if (couponClear) {
            couponClear.addEventListener('click', function () {
                if (couponInput) { couponInput.value = ''; }
                appliedCoupon = '';
                if (couponStatus) { couponStatus.hidden = true; couponStatus.textContent = ''; }
                couponClear.hidden = true;
                refreshQuote();
            });
        }
        [discountNone, discountCouponMode, discountCustomMode].forEach(function (radio) {
            if (!radio) { return; }
            radio.addEventListener('change', function () {
                switchDiscountMode(getDiscountMode(), false);
            });
        });
        [customType, customValue, customDiscountReason].forEach(function (el) {
            if (el) { el.addEventListener('input', scheduleQuote); el.addEventListener('change', scheduleQuote); }
        });
        if (slotSelect) { slotSelect.addEventListener('change', scheduleQuote); }
        function onDeliveryModeChange() {
            toggleDeliveryModeFields();
            scheduleQuote();
        }
        if (schedSlot) { schedSlot.addEventListener('change', onDeliveryModeChange); }
        if (schedCustom) { schedCustom.addEventListener('change', onDeliveryModeChange); }
        [customDate, customStart, customEnd, customDeliveryReason].forEach(function (el) {
            if (el) { el.addEventListener('change', scheduleQuote); el.addEventListener('input', scheduleQuote); }
        });
        function onPaymentModeChange() {
            offlineShowAllErrors = false;
            togglePaymentModeFields();
            renderPaySummary();
            updatePlaceButtonLabel();
            updateActionButtons();
            // Payment mode changes the quote payload (and the offline place token),
            // so re-quote instead of showing the previous mode's totals.
            scheduleQuote();
        }
        if (payPending) { payPending.addEventListener('change', onPaymentModeChange); }
        if (payOffline) { payOffline.addEventListener('change', onPaymentModeChange); }
        if (minOverrideToggle) {
            minOverrideToggle.addEventListener('change', function () {
                renderMinOrder();
                scheduleQuote();
            });
        }
        if (minOverrideReason) {
            minOverrideReason.addEventListener('change', function () {
                renderMinOrder();
                scheduleQuote();
            });
        }
        if (minOverrideNote) { minOverrideNote.addEventListener('input', scheduleQuote); }
        if (minOverrideConfirm) {
            minOverrideConfirm.addEventListener('change', function () {
                renderMinOrder();
                scheduleQuote();
            });
        }
        function bindOfflineField(node, key) {
            if (!node) { return; }
            var sync = function () {
                renderPaySummary();
                updateActionButtons();
            };
            node.addEventListener('input', sync);
            node.addEventListener('change', function () {
                offlineTouched[key] = true;
                sync();
            });
            node.addEventListener('blur', function () {
                offlineTouched[key] = true;
                sync();
            });
        }
        bindOfflineField(offlineMethod, 'method');
        bindOfflineField(offlineAmount, 'amount');
        bindOfflineField(offlineRef, 'reference');
        bindOfflineField(offlinePayDate, 'date');
        bindOfflineField(offlinePayTime, 'time');
        bindOfflineField(offlineNote, 'note');
        bindOfflineField(offlineConfirm, 'confirm');
        if (refreshQuoteBtn) { refreshQuoteBtn.addEventListener('click', refreshQuote); }
        if (placeBtn) {
            placeBtn.addEventListener('click', function () {
                if (!lastQuote || !urlPlace || quoteStale || orderPlaced) { return; }
                if (currentPaymentMode() === 'offline_paid' && !isOfflineReady()) {
                    offlineShowAllErrors = true;
                    updateActionButtons();
                    return;
                }
                var body = buildQuoteBody();
                body.append('client_grand_total', String(lastQuote.grand_total));
                if (lastQuote.place_submission_token) {
                    body.append('place_submission_token', String(lastQuote.place_submission_token));
                }
                placeBtn.disabled = true;
                fetchJson(urlPlace, { method: 'POST', body: body }).then(function (j) {
                    if (!j.success) {
                        placeResult.hidden = false;
                        placeResult.innerHTML = '';
                        placeResult.appendChild(el('p', 'text-sm font-semibold text-red-700', (j.error && j.error.message) || 'Could not create order.'));
                        offlineShowAllErrors = currentPaymentMode() === 'offline_paid';
                        updateActionButtons();
                        return;
                    }
                    var d = j.data || {};
                    orderPlaced = true;
                    placedPaymentMode = d.payment_mode || currentPaymentMode();
                    updateStepNav();
                    renderPaySummary();
                    updateActionButtons();
                    placeResult.hidden = false;
                    placeResult.innerHTML = '';
                    var msg = d.payment_mode === 'offline_paid'
                        ? 'Order ' + (d.order_number || '') + ' created — Paid (offline — verified).'
                        : 'Order ' + (d.order_number || '') + ' created — Pending payment.';
                    placeResult.appendChild(el('p', 'text-sm font-semibold text-green-800', msg));
                    if (d.payment_url) {
                        var a = document.createElement('a');
                        a.href = d.payment_url;
                        a.className = 'underline text-sm';
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.textContent = 'Customer payment link';
                        placeResult.appendChild(a);
                    }
                    if (d.admin_order_url) {
                        var adminA = document.createElement('a');
                        adminA.href = d.admin_order_url;
                        adminA.className = 'underline text-sm block mt-1';
                        adminA.textContent = 'View in admin';
                        placeResult.appendChild(adminA);
                    }
                }).catch(function () {
                    placeResult.hidden = false;
                    placeResult.innerHTML = '';
                    placeResult.appendChild(el('p', 'text-sm font-semibold text-red-700', 'Network error while creating the order. Please try again.'));
                    updateActionButtons();
                });
            });
        }

        function revalidateLine(idx, qty) {
            var line = state.lines[idx];
            if (!line || qty < 1) {
                state.lines.splice(idx, 1);
                renderLines();
                return;
            }
            var body = new FormData();
            body.append(csrfField, csrf);
            body.append('variant_id', String(line.variant_id));
            body.append('quantity', String(qty));
            if (state.customer) { body.append('customer_id', String(state.customer.id)); }
            fetchJson(urlLine, { method: 'POST', body: body }).then(function (j) {
                if (!j.success) {
                    showLineFeedback((j.error && j.error.message) || 'Invalid quantity.', true);
                    renderLines();
                    return;
                }
                showLineFeedback('', false);
                var updated = j.data.line;
                var existing = state.lines.findIndex(function (l, i) {
                    return l.variant_id === updated.variant_id && i !== idx;
                });
                if (existing >= 0) {
                    state.lines.splice(existing, 1);
                    if (existing < idx) { idx -= 1; }
                }
                state.lines[idx] = updated;
                renderLines();
            });
        }

        addBtn.addEventListener('click', function () {
            if (!state.selectedVariant || !state.customer) { return; }
            var qty = parseInt(qtyInput.value, 10) || 1;
            var vid = state.selectedVariant.variant_id;
            var existingIx = state.lines.findIndex(function (l) { return l.variant_id === vid; });
            var merged = existingIx >= 0;
            if (merged) {
                qty = (parseInt(state.lines[existingIx].quantity, 10) || 0) + qty;
            }
            var body = new FormData();
            body.append(csrfField, csrf);
            body.append('variant_id', String(vid));
            body.append('quantity', String(qty));
            body.append('customer_id', String(state.customer.id));
            addBtn.disabled = true;
            fetchJson(urlLine, { method: 'POST', body: body }).then(function (j) {
                addBtn.disabled = false;
                updateAddButton();
                if (!j.success) {
                    showLineFeedback((j.error && j.error.message) || 'Could not add item.', true);
                    return;
                }
                var line = j.data.line;
                var ix = state.lines.findIndex(function (l) { return l.variant_id === line.variant_id; });
                if (ix >= 0) {
                    state.lines[ix] = line;
                } else {
                    state.lines.push(line);
                }
                clearVariantSelection();
                variantSearch.value = '';
                variantStatus.textContent = 'Type at least 2 characters.';
                variantStatus.className = 'text-xs at-muted mt-1';
                qtyInput.value = '1';
                showLineFeedback(
                    (merged ? 'Updated quantity: ' : 'Added: ')
                    + line.product_name + ' — ' + line.variant_name + ' (×' + line.quantity + ').',
                    false
                );
                renderLines();
            }).catch(function () {
                addBtn.disabled = false;
                updateAddButton();
                showLineFeedback('Network error while adding item.', true);
            });
        });

        addrModeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-at-aorder-addr-mode') || 'existing';
                setAddressMode(mode);
                clearAddressFieldErrors();
            });
        });
        if (addrSaveBtn) {
            addrSaveBtn.addEventListener('click', saveNewAddress);
        }
        if (addrCancelBtn) {
            addrCancelBtn.addEventListener('click', function () {
                setAddressMode('existing');
                clearAddressFieldErrors();
            });
        }
        if (addressForm) {
            addressForm.querySelectorAll('[data-at-aorder-addr-field]').forEach(function (field) {
                field.addEventListener('input', clearAddressFieldErrors);
            });
        }
        root.querySelectorAll('[data-at-aorder-addr-label-type]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (addrLabelCustomWrap) {
                    addrLabelCustomWrap.hidden = radio.value !== 'Other' || !radio.checked;
                }
            });
        });
        if (addrPinInput) {
            addrPinInput.addEventListener('input', function () {
                clearTimeout(pinPreviewTimer);
                pinPreviewTimer = setTimeout(function () {
                    var digits = addrPinInput.value.replace(/\D/g, '');
                    if (addrPinInput.value.trim() !== '' && digits.length !== 6) {
                        pinPreviewSeq += 1;
                        pinPreview.textContent = 'Enter a valid 6-digit pincode.';
                        pinPreview.className = 'text-xs mt-1 text-red-700';
                        pinPreview.hidden = false;
                        return;
                    }
                    previewServiceability(
                        addrPinInput.value,
                        addrLatInput ? addrLatInput.value : '',
                        addrLngInput ? addrLngInput.value : '',
                        pinPreview,
                        'pin'
                    );
                }, 450);
            });
        }

        /* ---------- 4-step wizard ---------- */

        function stepIsComplete(n) {
            if (n === 1) {
                return !!(state.customer && state.addressId);
            }
            if (n === 2) {
                return state.lines.length > 0;
            }
            if (n === 3) {
                return stepGateMessage(3) === '';
            }
            return true;
        }

        function canEnterStep(n) {
            for (var i = 1; i < n; i++) {
                if (!stepIsComplete(i)) { return false; }
            }
            return true;
        }

        function stepGateMessage(n) {
            if (n === 1) { return 'Select a customer and a delivery address to continue.'; }
            if (n === 2) { return 'Add at least one product to the order.'; }
            if (n === 3) {
                if (slotsEnabled && schedCustom && schedCustom.checked
                    && !(customDate && customDate.value !== ''
                        && customStart && customStart.value !== ''
                        && customEnd && customEnd.value !== ''
                        && customDeliveryReason && customDeliveryReason.value.trim() !== '')) {
                    return 'Enter delivery date, time window and reason for the custom schedule.';
                }
                if (applyDelivery && applyDelivery.checked && freeDelivery && freeDelivery.checked
                    && freeReason && freeReason.value.trim() === '') {
                    return 'Enter a reason for the free delivery override.';
                }
                return '';
            }
            return '';
        }

        function showStepError(msg) {
            var panel = root.querySelector('[data-at-aorder-step-panel="' + currentStep + '"]');
            var err = panel ? panel.querySelector('[data-at-aorder-step-error]') : null;
            if (err) {
                err.textContent = msg;
                err.hidden = false;
            }
        }

        function clearStepErrors() {
            root.querySelectorAll('[data-at-aorder-step-error]').forEach(function (node) {
                node.hidden = true;
                node.textContent = '';
            });
        }

        function updateStepNav() {
            stepNavBtns.forEach(function (btn) {
                var n = parseInt(btn.getAttribute('data-at-aorder-stepnav'), 10) || 0;
                var item = btn.closest('[data-at-aorder-stepnav-item]');
                var num = btn.querySelector('[data-at-aorder-stepnum]');
                // Only steps behind the current one can be completed — a future
                // step must never show a checkmark (step 4's gate is always true).
                var done = orderPlaced || (n < currentStep && stepIsComplete(n));
                var locked = !orderPlaced && n !== currentStep && !canEnterStep(n);
                if (item) {
                    item.classList.toggle('is-active', n === currentStep);
                    item.classList.toggle('is-done', done);
                    item.classList.toggle('is-locked', locked);
                }
                if (num) { num.textContent = done ? '✓' : String(n); }
                btn.disabled = locked;
                if (n === currentStep) {
                    btn.setAttribute('aria-current', 'step');
                } else {
                    btn.removeAttribute('aria-current');
                }
            });
        }

        function updateDeliveryWhen() {
            if (!deliveryWhen) { return; }
            var txt;
            if (slotsEnabled && schedCustom && schedCustom.checked) {
                txt = 'Custom delivery: '
                    + (customDate && customDate.value !== '' ? customDate.value : 'date not set')
                    + ' '
                    + (customStart && customStart.value !== '' ? customStart.value : '--:--')
                    + '–'
                    + (customEnd && customEnd.value !== '' ? customEnd.value : '--:--');
            } else if (slotsEnabled && slotSelect && slotSelect.tagName === 'SELECT') {
                var opt = slotSelect.options[slotSelect.selectedIndex];
                txt = 'Standard slot: ' + (opt ? opt.textContent.replace(/\s+/g, ' ').trim() : 'auto');
            } else {
                txt = 'Next available delivery slot will be used.';
            }
            deliveryWhen.textContent = txt;
        }

        function renderReview() {
            if (!reviewDl) { return; }
            reviewDl.innerHTML = '';
            function reviewRow(label, value) {
                var d = el('div', 'at-aorder-review__row');
                d.appendChild(el('dt', 'at-aorder-review__label', label));
                d.appendChild(el('dd', 'at-aorder-review__value', value));
                reviewDl.appendChild(d);
            }
            var c = state.customer || {};
            reviewRow('Customer', (c.name || 'Customer')
                + (c.mobile ? ' · ' + c.mobile : '')
                + (c.email ? ' · ' + c.email : ''));
            var a = state.selectedAddress || {};
            var addrBits = [
                (a.label || 'Address') + (a.is_default ? ' (Default)' : ''),
                a.receiver_name ? (a.receiver_name + (a.receiver_phone ? ' · ' + a.receiver_phone : '')) : '',
                a.full_address || ''
            ].filter(Boolean).join(' — ');
            reviewRow('Deliver to', addrBits !== '' ? addrBits : '—');
            reviewRow('Delivery', deliveryWhen ? deliveryWhen.textContent : '—');
            if (reviewItems) {
                reviewItems.innerHTML = '';
                function itemRow(label, value) {
                    var d = el('div', 'at-aorder-review__row');
                    d.appendChild(el('dt', 'at-aorder-review__label', label));
                    d.appendChild(el('dd', 'at-aorder-review__value', value));
                    reviewItems.appendChild(d);
                }
                var count = state.lines.reduce(function (s, l) { return s + (parseInt(l.quantity, 10) || 0); }, 0);
                itemRow('Items', count + ' item(s)');
                state.lines.forEach(function (l) {
                    itemRow(
                        l.product_name,
                        (l.variant_name ? l.variant_name + ' · ' : '') + '× ' + l.quantity
                    );
                });
            }
        }

        function goToStep(n) {
            if (n < 1 || n > stepPanels.length || n === currentStep) { return; }
            if (!canEnterStep(n)) { return; }
            currentStep = n;
            stepPanels.forEach(function (p) {
                var show = (parseInt(p.getAttribute('data-at-aorder-step-panel'), 10) || 0) === n;
                p.hidden = !show;
                p.classList.toggle('at-aorder-is-hidden', !show);
            });
            clearStepErrors();
            if (n === 3) { updateDeliveryWhen(); }
            if (n === stepPanels.length) {
                renderReview();
                renderPaySummary();
                updatePlaceButtonLabel();
                if (canQuote()) { refreshQuote(); }
            }
            updateStepNav();
            var wiz = root.querySelector('.at-aorder-wizard');
            if (wiz && wiz.scrollIntoView) {
                wiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function stepQty(delta) {
            var cur = parseInt(qtyInput.value, 10) || 1;
            var next = cur + delta;
            if (next < 1) { next = 1; }
            var avail = state.selectedVariant && state.selectedVariant.available_qty != null
                ? parseInt(state.selectedVariant.available_qty, 10) : 0;
            if (avail > 0 && next > avail) { next = avail; }
            qtyInput.value = String(next);
        }

        root.querySelectorAll('[data-at-aorder-next]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = parseInt(btn.getAttribute('data-at-aorder-next'), 10) || 0;
                if (!stepIsComplete(currentStep)) {
                    showStepError(stepGateMessage(currentStep));
                    return;
                }
                goToStep(target);
            });
        });
        root.querySelectorAll('[data-at-aorder-prev]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                goToStep(parseInt(btn.getAttribute('data-at-aorder-prev'), 10) || 1);
            });
        });
        stepNavBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                goToStep(parseInt(btn.getAttribute('data-at-aorder-stepnav'), 10) || 1);
            });
        });
        if (qtyDecBtn) { qtyDecBtn.addEventListener('click', function () { stepQty(-1); }); }
        if (qtyIncBtn) { qtyIncBtn.addEventListener('click', function () { stepQty(1); }); }
        if (qtyInput) {
            qtyInput.addEventListener('change', function () { stepQty(0); });
        }

        window.addEventListener('beforeunload', function (e) {
            if (orderPlaced) { return; }
            if (!state.customer && state.lines.length === 0) { return; }
            e.preventDefault();
            e.returnValue = '';
        });

        renderLines();
        toggleDeliveryModeFields();
        togglePaymentModeFields();
        toggleDiscountModeFields();
        updateDeliveryWhen();
        renderReview();
        renderPaySummary();
        updatePlaceButtonLabel();
        updateActionButtons();
        updateStepNav();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
