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
        var urlVariants = root.getAttribute('data-url-variants') || '';
        var urlLine = root.getAttribute('data-url-line') || '';
        var urlQuote = root.getAttribute('data-url-quote') || '';
        var urlPlace = root.getAttribute('data-url-place') || '';
        var slotsEnabled = root.getAttribute('data-slots-enabled') === '1';

        var state = {
            customer: null,
            addressId: null,
            selectedVariant: null,
            lines: [],
            customerPage: 1,
            variantPage: 1,
            addressSubmissionKey: '',
            selectedAddress: null
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
        var customReason = root.querySelector('[data-at-aorder-custom-reason]');
        var payPending = root.querySelector('[data-at-aorder-pay-pending]');
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
        var customEnabled = root.querySelector('[data-at-aorder-custom-enabled]');
        var customWrap = root.querySelector('[data-at-aorder-custom-wrap]');
        var customType = root.querySelector('[data-at-aorder-custom-type]');
        var customValue = root.querySelector('[data-at-aorder-custom-value]');
        var customReason = root.querySelector('[data-at-aorder-custom-reason]');
        var quoteStatus = root.querySelector('[data-at-aorder-quote-status]');
        var quoteDl = root.querySelector('[data-at-aorder-quote-dl]');
        var refreshQuoteBtn = root.querySelector('[data-at-aorder-refresh-quote]');
        var placeBtn = root.querySelector('[data-at-aorder-place]');
        var placeResult = root.querySelector('[data-at-aorder-place-result]');
        var slotSelect = root.querySelector('[data-at-aorder-delivery-slot]');
        var quoteTimer = null;
        var lastQuote = null;

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
            customerIdInput.value = '';
            customerChip.innerHTML = '';
            customerChip.appendChild(el('span', 'text-sm at-muted', 'No customer selected.'));
            customerSummary.hidden = true;
            addressSection.hidden = true;
            addressList.innerHTML = '';
            if (addressSummary) { addressSummary.hidden = true; addressSummary.textContent = ''; }
            if (addressForm) { addressForm.hidden = true; }
            updateAddButton();
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
            addrModeButtons.forEach(function (btn) {
                var m = btn.getAttribute('data-at-aorder-addr-mode');
                var active = m === mode;
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                btn.classList.toggle('at-btn--secondary', active);
                btn.classList.toggle('at-btn--ghost', !active);
            });
            if (addressExistingPanel) { addressExistingPanel.hidden = isNew; }
            if (addressForm) {
                addressForm.hidden = !isNew;
                if (isNew && state.addressSubmissionKey === '') {
                    state.addressSubmissionKey = randomHex(16);
                }
            }
        }

        function updateAddressSummary(addr) {
            if (!addressSummary) { return; }
            if (!addr) {
                addressSummary.hidden = true;
                addressSummary.innerHTML = '';
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
            scheduleQuote();
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
            loadAddresses(c.id);
            updateAddButton();
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
            if (!variantResults.contains(e.target) && e.target !== variantSearch) {
                variantResults.hidden = true;
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
            return body;
        }

        function saveNewAddress() {
            if (!state.customer || !urlAddressCreate) { return; }
            clearAddressFieldErrors();
            if (addrSaveBtn) { addrSaveBtn.disabled = true; }
            fetchJson(urlAddressCreate, { method: 'POST', body: buildAddressCreateBody() }).then(function (j) {
                if (addrSaveBtn) { addrSaveBtn.disabled = false; }
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

        function showVariantPreview(v) {
            if (!v) {
                variantPreview.hidden = true;
                return;
            }
            variantPreview.hidden = false;
            variantPreview.innerHTML = '';
            if (v.image_url) {
                var img = document.createElement('img');
                img.src = v.image_url;
                img.alt = '';
                img.className = 'inline-block w-12 h-12 object-cover rounded mr-2 align-middle';
                variantPreview.appendChild(img);
            }
            variantPreview.appendChild(document.createTextNode(
                v.product_name + ' — ' + v.variant_name + ' · ' + fmtMoney(v.unit_price) +
                ' · Available: ' + (v.available_qty != null ? v.available_qty : v.stock_qty)
            ));
        }

        function runVariantSearch(page) {
            var q = variantSearch.value.trim();
            state.variantPage = page || 1;
            state.selectedVariant = null;
            showVariantPreview(null);
            updateAddButton();
            if (q.length < 2) {
                variantResults.hidden = true;
                variantStatus.textContent = 'Type at least 2 characters.';
                variantPager.hidden = true;
                return;
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
                variantStatus.textContent = items.length ? 'Select a variant to add.' : 'No products found.';
                variantResults.innerHTML = '';
                items.forEach(function (v) {
                    var li = el('li', 'at-picker__item' + (v.selectable ? '' : ' is-disabled'));
                    li.setAttribute('role', 'option');
                    var body = el('span', 'at-picker__body');
                    body.appendChild(el('strong', '', v.product_name + ' — ' + v.variant_name));
                    var sub = [
                        v.sku ? 'SKU ' + v.sku : '',
                        fmtMoney(v.unit_price),
                        'Stock ' + (v.available_qty != null ? v.available_qty : v.stock_qty),
                        v.unavailable_reason || ''
                    ].filter(Boolean).join(' · ');
                    body.appendChild(el('small', 'at-muted', sub));
                    li.appendChild(body);
                    if (v.selectable) {
                        li.tabIndex = 0;
                        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        li.addEventListener('click', function () {
                            state.selectedVariant = v;
                            variantSearch.value = v.product_name + ' — ' + v.variant_name;
                            variantResults.hidden = true;
                            showVariantPreview(v);
                            updateAddButton();
                        });
                    }
                    variantResults.appendChild(li);
                });
                variantResults.hidden = false;
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

        function updateAddButton() {
            var ok = state.selectedVariant && state.customer && state.customer.is_active !== false;
            addBtn.disabled = !ok;
        }

        function renderLines() {
            itemsBody.innerHTML = '';
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
                tr.appendChild(el('td', '', line.product_name));
                tr.appendChild(el('td', '', line.variant_name));
                tr.appendChild(el('td', '', line.sku || '—'));
                var up = el('td', 'text-right', fmtMoney(line.unit_price));
                tr.appendChild(up);
                var qTd = el('td', 'text-right');
                var qIn = el('input', 'at-input at-focusable w-16 text-right inline-block');
                qIn.type = 'number';
                qIn.min = '1';
                qIn.value = String(line.quantity);
                qIn.addEventListener('change', function () {
                    revalidateLine(idx, parseInt(qIn.value, 10) || 0);
                });
                qTd.appendChild(qIn);
                tr.appendChild(qTd);
                tr.appendChild(el('td', 'text-right', fmtMoney(line.line_total)));
                var act = el('td', '');
                var rm = el('button', 'at-btn at-btn--ghost at-btn--sm at-focusable', 'Remove');
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
            body.append('coupon_code', appliedCoupon || (couponInput ? couponInput.value.trim() : ''));
            body.append('delivery_schedule_mode', schedCustom && schedCustom.checked ? 'custom' : 'slot');
            if (schedCustom && schedCustom.checked) {
                if (customDate) { body.append('custom_delivery_date', customDate.value); }
                if (customStart) { body.append('custom_delivery_start', customStart.value); }
                if (customEnd) { body.append('custom_delivery_end', customEnd.value); }
                if (customReason) { body.append('custom_delivery_reason', customReason.value.trim()); }
            }
            body.append('payment_mode', payOffline && payOffline.checked ? 'offline_paid' : 'pending');
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
            body.append('custom_discount_enabled', customEnabled && customEnabled.checked ? '1' : '0');
            if (customType) { body.append('custom_discount_type', customType.value); }
            if (customValue) { body.append('custom_discount_value', customValue.value); }
            if (customReason) { body.append('custom_discount_reason', customReason.value.trim()); }
            if (slotSelect) {
                var opt = slotSelect.options[slotSelect.selectedIndex];
                body.append('delivery_slot', slotSelect.value);
                body.append('delivery_date', opt ? (opt.getAttribute('data-date') || '') : '');
            } else {
                body.append('delivery_slot', root.getAttribute('data-default-slot') || 'auto');
                body.append('delivery_date', root.getAttribute('data-default-slot-date') || '');
            }
            return body;
        }

        function canQuote() {
            return !!(state.customer && state.addressId && state.lines.length);
        }

        function updateActionButtons() {
            var ok = canQuote();
            if (refreshQuoteBtn) { refreshQuoteBtn.disabled = !ok; }
            if (placeBtn) { placeBtn.disabled = !ok || !lastQuote; }
        }

        function scheduleQuote() {
            clearTimeout(quoteTimer);
            quoteTimer = setTimeout(refreshQuote, 400);
            updateActionButtons();
        }

        function renderQuote(q) {
            if (!q) {
                quoteDl.hidden = true;
                quoteStatus.textContent = 'Complete the sections above to calculate totals.';
                lastQuote = null;
                updateActionButtons();
                return;
            }
            lastQuote = q;
            quoteDl.hidden = false;
            quoteStatus.textContent = '';
            quoteDl.innerHTML = '';
            function row(label, val, strong) {
                var d = el('div', 'flex justify-between gap-4');
                d.appendChild(el('dt', strong ? 'font-semibold' : '', label));
                d.appendChild(el('dd', '', val));
                quoteDl.appendChild(d);
            }
            row('Merchandise subtotal', fmtMoney(q.subtotal));
            if (q.offer_discount > 0) { row('Automatic offer', '− ' + fmtMoney(q.offer_discount)); }
            if (q.coupon_discount > 0) { row('Coupon' + (q.coupon_code ? ' (' + q.coupon_code + ')' : ''), '− ' + fmtMoney(q.coupon_discount)); }
            if (q.custom_discount > 0) { row('Custom admin discount', '− ' + fmtMoney(q.custom_discount)); }
            row('Delivery fee', fmtMoney(q.delivery_fee));
            if (q.delivery_zone) {
                deliverySummary.textContent = 'Zone: ' + q.delivery_zone
                    + (q.calculated_delivery_fee !== q.delivery_fee ? ' · Calculated: ' + fmtMoney(q.calculated_delivery_fee) : '');
            }
            row('Payable total', fmtMoney(q.grand_total), true);
            if (q.payment_mode === 'offline_paid') {
                row('Payment mode', 'Offline (admin verified)');
            } else {
                row('Payment mode', 'Pending online payment');
            }
            if (offlineAmount && payOffline && payOffline.checked) {
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
            updateActionButtons();
        }

        function updatePlaceButtonLabel() {
            if (!placeBtn) { return; }
            if (payOffline && payOffline.checked && canRecordOffline) {
                placeBtn.textContent = 'Create paid order (offline)';
            } else {
                placeBtn.textContent = 'Create order (pending payment)';
            }
        }

        function refreshQuote() {
            if (!canQuote() || !urlQuote) {
                renderQuote(null);
                return;
            }
            quoteStatus.textContent = 'Calculating…';
            fetchJson(urlQuote, { method: 'POST', body: buildQuoteBody() }).then(function (j) {
                if (!j.success) {
                    quoteStatus.textContent = (j.error && j.error.message) || 'Quote failed.';
                    renderQuote(null);
                    return;
                }
                renderQuote(j.data.quote);
            }).catch(function () {
                quoteStatus.textContent = 'Quote failed.';
                renderQuote(null);
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
                if (couponApply) {
                    couponApply.disabled = !canQuote() || couponApplyBusy || couponInput.value.trim() === '';
                }
                if (couponInput.value.trim() !== appliedCoupon) {
                    appliedCoupon = '';
                    if (couponStatus) { couponStatus.hidden = true; couponStatus.textContent = ''; }
                    if (couponClear) { couponClear.hidden = true; }
                }
            });
        }
        if (couponApply) {
            couponApply.addEventListener('click', function () {
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
        if (customEnabled) {
            customEnabled.addEventListener('change', function () {
                if (customWrap) { customWrap.hidden = !customEnabled.checked; }
                scheduleQuote();
            });
        }
        [customType, customValue, customReason].forEach(function (el) {
            if (el) { el.addEventListener('input', scheduleQuote); el.addEventListener('change', scheduleQuote); }
        });
        if (slotSelect) { slotSelect.addEventListener('change', scheduleQuote); }
        function syncSchedulePanels() {
            var custom = schedCustom && schedCustom.checked;
            if (slotPanel) { slotPanel.hidden = !!custom; }
            if (customDeliveryWrap) { customDeliveryWrap.hidden = !custom; }
            scheduleQuote();
        }
        if (schedSlot) { schedSlot.addEventListener('change', syncSchedulePanels); }
        if (schedCustom) { schedCustom.addEventListener('change', syncSchedulePanels); }
        [customDate, customStart, customEnd, customReason].forEach(function (el) {
            if (el) { el.addEventListener('change', scheduleQuote); el.addEventListener('input', scheduleQuote); }
        });
        function syncPaymentPanels() {
            var offline = payOffline && payOffline.checked && canRecordOffline;
            if (offlineWrap) { offlineWrap.hidden = !offline; }
            if (offline && lastQuote && offlineAmount) {
                offlineAmount.value = String(lastQuote.grand_total);
            }
            updatePlaceButtonLabel();
        }
        if (payPending) { payPending.addEventListener('change', syncPaymentPanels); }
        if (payOffline) { payOffline.addEventListener('change', syncPaymentPanels); }
        if (refreshQuoteBtn) { refreshQuoteBtn.addEventListener('click', refreshQuote); }
        if (placeBtn) {
            placeBtn.addEventListener('click', function () {
                if (!lastQuote || !urlPlace) { return; }
                var body = buildQuoteBody();
                body.append('client_grand_total', String(lastQuote.grand_total));
                if (lastQuote.place_submission_token) {
                    body.append('place_submission_token', String(lastQuote.place_submission_token));
                }
                placeBtn.disabled = true;
                fetchJson(urlPlace, { method: 'POST', body: body }).then(function (j) {
                    placeBtn.disabled = false;
                    if (!j.success) {
                        alert((j.error && j.error.message) || 'Could not create order.');
                        return;
                    }
                    var d = j.data || {};
                    placeResult.hidden = false;
                    placeResult.innerHTML = '';
                    var msg = d.payment_mode === 'offline_paid'
                        ? 'Order ' + (d.order_number || '') + ' created — paid (offline).'
                        : 'Order ' + (d.order_number || '') + ' created — pending payment.';
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
                    placeBtn.disabled = false;
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
                    alert((j.error && j.error.message) || 'Invalid quantity.');
                    renderLines();
                    return;
                }
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
            if (existingIx >= 0) {
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
                    alert((j.error && j.error.message) || 'Could not add item.');
                    return;
                }
                var line = j.data.line;
                var ix = state.lines.findIndex(function (l) { return l.variant_id === line.variant_id; });
                if (ix >= 0) {
                    state.lines[ix] = line;
                } else {
                    state.lines.push(line);
                }
                state.selectedVariant = null;
                variantSearch.value = '';
                showVariantPreview(null);
                qtyInput.value = '1';
                renderLines();
            }).catch(function () {
                addBtn.disabled = false;
                updateAddButton();
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

        renderLines();
        updateActionButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
