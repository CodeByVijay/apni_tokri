<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminAssistedOrderService::denyUnlessCanCreate();

$base = url_base();
$ajaxBase = $base . '/admin/ajax';
$csrfToken = Csrf::token();
$csrfField = Csrf::fieldName();
$migrationReady = AdminAssistedOrderService::columnsReady();
$ordersIndexUrl = $base . '/admin/orders/index.php';

admin_layout_start([
    'pageTitle' => 'Create order for customer',
    'activeAdminNav' => 'orders',
]);
$pageHeading = 'Create order on behalf of customer';
$pageDescription = 'Guided order creation: pick a customer, add products, choose delivery, then confirm payment.';
$slotsEnabled = DeliveryConfig::slotsEnabled();
$checkoutSlots = $slotsEnabled ? AdminAssistedOrderService::checkoutSlotOptionsForAdmin() : [];
$canRecordOffline = AdminAssistedOrderService::canRecordOfflinePayment();
$defaultSlot = $slotsEnabled && $checkoutSlots !== [] ? (string) ($checkoutSlots[0]['slug'] ?? '') : 'auto';
$defaultSlotDate = $slotsEnabled && $checkoutSlots !== [] ? (string) ($checkoutSlots[0]['delivery_date'] ?? '') : '';
$wizardSteps = [
    1 => 'Customer',
    2 => 'Products',
    3 => 'Delivery',
    4 => 'Payment',
];
$breadcrumbs = [
    ['label' => 'Orders', 'href' => $ordersIndexUrl],
    ['label' => 'Create order'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<?php if (!Install::isSchemaReady()): ?>
    <div class="at-alert at-alert--error">Database schema is not ready.</div>
<?php elseif (!$migrationReady): ?>
    <div class="at-alert at-alert--warning" role="status">
        The assisted-order schema migration (<code>1.40.0_admin_assisted_orders.sql</code>) has not been applied on this database yet.
        You can still build the order draft below; applying the migration is required before orders can be placed in a later phase.
    </div>
<?php endif; ?>

<div id="at-assisted-order"
     class="at-assisted-order space-y-4"
     data-csrf="<?= Security::escape($csrfToken) ?>"
     data-csrf-field="<?= Security::escape($csrfField) ?>"
     data-url-customers="<?= Security::escape($ajaxBase . '/assisted-order-customers.php') ?>"
     data-url-addresses="<?= Security::escape($ajaxBase . '/assisted-order-addresses.php') ?>"
     data-url-address-create="<?= Security::escape($ajaxBase . '/assisted-order-address-create.php') ?>"
     data-url-address-preview="<?= Security::escape($ajaxBase . '/assisted-order-address-preview.php') ?>"
     data-url-variants="<?= Security::escape($ajaxBase . '/assisted-order-variants.php') ?>"
     data-url-line="<?= Security::escape($ajaxBase . '/assisted-order-line.php') ?>"
     data-url-quote="<?= Security::escape($ajaxBase . '/assisted-order-quote.php') ?>"
     data-url-place="<?= Security::escape($ajaxBase . '/assisted-order-place.php') ?>"
     data-slots-enabled="<?= $slotsEnabled ? '1' : '0' ?>"
     data-default-slot="<?= Security::escape($defaultSlot) ?>"
     data-default-slot-date="<?= Security::escape($defaultSlotDate) ?>"
     data-can-record-offline="<?= $canRecordOffline ? '1' : '0' ?>">

    <nav class="at-aorder-wizard" aria-label="Order creation steps">
        <ol class="at-aorder-steps">
            <?php foreach ($wizardSteps as $stepNo => $stepLabel): ?>
            <li class="at-aorder-steps__item<?= $stepNo === 1 ? ' is-active' : '' ?>" data-at-aorder-stepnav-item="<?= $stepNo ?>">
                <button type="button"
                        class="at-aorder-steps__btn at-focusable"
                        data-at-aorder-stepnav="<?= $stepNo ?>"
                        <?= $stepNo === 1 ? 'aria-current="step"' : 'disabled' ?>>
                    <span class="at-aorder-steps__num" data-at-aorder-stepnum><?= $stepNo ?></span>
                    <span class="at-aorder-steps__label"><?= Security::escape($stepLabel) ?></span>
                </button>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <!-- STEP 1 — Customer -->
    <div data-at-aorder-step-panel="1" role="group" aria-label="Step 1 of 4: Customer">
        <section class="at-admin-card" aria-labelledby="at-aorder-customer-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-customer-title">Find customer</h2></div>
            <div class="at-admin-card__body">
                <div class="at-assisted-order__customer" data-at-aorder-customer-picker>
                    <label class="at-label" for="at-aorder-customer-search">Search by name, mobile or email</label>
                    <div class="at-picker at-picker--single">
                        <div class="at-assisted-order__selection at-picker__chips" data-at-aorder-customer-chip aria-live="polite">
                            <span class="text-sm at-muted">No customer selected.</span>
                        </div>
                        <div class="at-picker__head">
                            <input id="at-aorder-customer-search" type="search" class="at-input at-focusable w-full"
                                   autocomplete="off" placeholder="Name, mobile or email (min. 2 characters)">
                        </div>
                        <p class="text-xs at-muted mt-1" data-at-aorder-customer-status>Type at least 2 characters to search.</p>
                        <ul class="at-picker__results" data-at-aorder-customer-results hidden role="listbox"></ul>
                        <nav class="flex flex-wrap items-center gap-2 mt-2 text-sm" data-at-aorder-customer-pager hidden aria-label="Customer search pages"></nav>
                        <input type="hidden" name="customer_id" value="" data-at-aorder-customer-id>
                    </div>
                </div>
                <div class="mt-3 text-sm" data-at-aorder-customer-summary hidden></div>
            </div>
        </section>

        <section class="at-admin-card mt-4" aria-labelledby="at-aorder-address-title" data-at-aorder-address-section hidden>
            <div class="at-admin-card__head"><h2 id="at-aorder-address-title">Delivery address</h2></div>
            <div class="at-admin-card__body space-y-4">
                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Address source">
                    <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-aorder-addr-mode="existing" aria-pressed="true">
                        Select existing address
                    </button>
                    <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-aorder-addr-mode="new" aria-pressed="false">
                        + Add new address
                    </button>
                </div>
                <p class="text-sm at-muted" data-at-aorder-address-empty hidden>No saved addresses for this customer. Add a new address below.</p>
                <div data-at-aorder-address-existing-panel>
                    <div class="space-y-2" data-at-aorder-address-list role="radiogroup" aria-label="Delivery address"></div>
                </div>
                <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/assisted-order-address-form.php'; ?>
                <div class="p-3 rounded-lg border border-gray-200 bg-gray-50 text-sm" data-at-aorder-address-summary hidden aria-live="polite"></div>
                <p class="text-sm" data-at-aorder-addr-preview role="status" aria-live="polite" hidden></p>
            </div>
        </section>

        <div class="at-aorder-step-footer">
            <p class="at-aorder-step-footer__error" data-at-aorder-step-error role="alert" hidden></p>
            <div class="at-aorder-step-footer__actions">
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($ordersIndexUrl) ?>">Cancel</a>
                <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-next="2">Continue to Products</button>
            </div>
        </div>
    </div>

    <!-- STEP 2 — Products -->
    <div data-at-aorder-step-panel="2" role="group" aria-label="Step 2 of 4: Products" hidden class="at-aorder-is-hidden">
        <section class="at-admin-card" aria-labelledby="at-aorder-products-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-products-title">Add products</h2></div>
            <div class="at-admin-card__body overflow-visible">
                <div class="at-picker at-assisted-order__variant-picker min-w-0">
                    <label class="at-label" for="at-aorder-variant-search">Search product or variant</label>
                    <input id="at-aorder-variant-search" type="search" class="at-input at-focusable w-full"
                           autocomplete="off" placeholder="Product name, slug or SKU" aria-autocomplete="list"
                           aria-controls="at-aorder-variant-results" aria-expanded="false">
                    <p class="text-xs at-muted mt-1" data-at-aorder-variant-status>Type at least 2 characters.</p>
                    <ul id="at-aorder-variant-results" class="at-picker__results" data-at-aorder-variant-results hidden role="listbox" aria-label="Product variants"></ul>
                    <nav class="flex flex-wrap items-center gap-2 mt-2 text-sm" data-at-aorder-variant-pager hidden></nav>
                </div>
                <div class="at-aorder-addpanel" data-at-aorder-add-panel hidden>
                    <div class="at-aorder-addpanel__info" data-at-aorder-variant-preview></div>
                    <div class="at-aorder-addpanel__actions">
                        <div class="at-aorder-addpanel__qty">
                            <label class="at-label" for="at-aorder-qty">Quantity</label>
                            <div class="at-qty-stepper">
                                <button type="button" class="at-qty-stepper__btn at-focusable" data-at-aorder-qty-dec aria-label="Decrease quantity">&minus;</button>
                                <input id="at-aorder-qty" type="number" class="at-input at-focusable at-qty-stepper__input" min="1" step="1" value="1" inputmode="numeric">
                                <button type="button" class="at-qty-stepper__btn at-focusable" data-at-aorder-qty-inc aria-label="Increase quantity">+</button>
                            </div>
                        </div>
                        <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-add-line disabled>
                            Add to order
                        </button>
                    </div>
                </div>
                <p class="text-xs mt-2" data-at-aorder-line-feedback role="status" aria-live="polite" hidden></p>
            </div>
        </section>

        <section class="at-admin-card mt-4" aria-labelledby="at-aorder-items-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-items-title">Order items</h2></div>
            <div class="at-admin-card__body">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <p class="text-sm font-semibold" data-at-aorder-items-meta>No items added yet.</p>
                </div>
                <p class="at-aorder-empty" data-at-aorder-items-emptystate>
                    No products added yet.<br>
                    <span class="at-aorder-empty__hint">Search for a product above to start building this order.</span>
                </p>
                <div class="at-admin-table-wrap" data-at-aorder-items-wrap hidden>
                    <table class="at-admin-table at-admin-table--cards at-aorder-items-table w-full" data-at-aorder-items-table>
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">Variant</th>
                                <th scope="col">SKU</th>
                                <th scope="col" class="is-num">Unit price</th>
                                <th scope="col" class="is-num">Qty</th>
                                <th scope="col" class="is-num">Line total</th>
                                <th scope="col" class="is-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody data-at-aorder-items-body>
                            <tr data-at-aorder-items-empty>
                                <td colspan="7" class="text-sm at-muted py-4 text-center">No items yet. Search and add products above.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="at-aorder-step-footer">
            <p class="at-aorder-step-footer__error" data-at-aorder-step-error role="alert" hidden></p>
            <div class="at-aorder-step-footer__actions">
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($ordersIndexUrl) ?>">Cancel</a>
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-aorder-prev="1">Back</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-next="3">Continue to Delivery</button>
            </div>
        </div>
    </div>

    <!-- STEP 3 — Delivery -->
    <div data-at-aorder-step-panel="3" role="group" aria-label="Step 3 of 4: Delivery" hidden class="at-aorder-is-hidden">
        <?php if ($slotsEnabled): ?>
        <section class="at-admin-card" aria-labelledby="at-aorder-slot-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-slot-title">Delivery slot</h2></div>
            <div class="at-admin-card__body space-y-4">
                <fieldset class="space-y-2">
                    <legend class="sr-only">Delivery scheduling</legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="at_aorder_sched_mode" class="at-focusable" value="slot" data-at-aorder-sched-slot checked>
                        Use a standard delivery slot
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="at_aorder_sched_mode" class="at-focusable" value="custom" data-at-aorder-sched-custom>
                        Custom delivery date/time
                    </label>
                </fieldset>
                <div data-at-aorder-slot-panel data-at-aorder-panel>
                    <?php if ($checkoutSlots === []): ?>
                        <p class="text-sm at-muted">Next available slot will be selected automatically when you refresh the quote.</p>
                        <input type="hidden" value="auto" data-at-aorder-delivery-slot>
                    <?php else: ?>
                    <label class="at-label" for="at-aorder-delivery-slot">Slot</label>
                    <select id="at-aorder-delivery-slot" class="at-input at-focusable w-full max-w-lg" data-at-aorder-delivery-slot>
                        <?php foreach ($checkoutSlots as $sl):
                            $slug = (string) ($sl['slug'] ?? '');
                            $date = (string) ($sl['delivery_date'] ?? '');
                            $optionLabel = DeliverySlotDisplay::formatAdminAssistedOption($sl);
                            ?>
                        <option value="<?= Security::escape($slug) ?>" data-date="<?= Security::escape($date) ?>">
                            <?= Security::escape($optionLabel) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <!-- Panel wrapper must not use Tailwind display utilities (.grid); those override [hidden]. -->
                <div data-at-aorder-custom-delivery-wrap data-at-aorder-panel hidden class="at-aorder-is-hidden">
                    <div class="grid gap-3 sm:grid-cols-2 max-w-2xl">
                        <div>
                            <label class="at-label" for="at-aorder-custom-date">Delivery date</label>
                            <input id="at-aorder-custom-date" type="date" class="at-input at-focusable w-full" data-at-aorder-custom-date>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-custom-start">Start time</label>
                            <input id="at-aorder-custom-start" type="time" class="at-input at-focusable w-full" data-at-aorder-custom-start>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-custom-end">End time</label>
                            <input id="at-aorder-custom-end" type="time" class="at-input at-focusable w-full" data-at-aorder-custom-end>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="at-label" for="at-aorder-custom-delivery-reason">Reason for custom schedule</label>
                            <input id="at-aorder-custom-delivery-reason" type="text" class="at-input at-focusable w-full" maxlength="255" data-at-aorder-custom-delivery-reason>
                        </div>
                    </div>
                </div>
                <p class="text-sm" data-at-aorder-delivery-when aria-live="polite"><span class="at-muted">A standard delivery slot will be used.</span></p>
            </div>
        </section>
        <?php endif; ?>

        <section class="at-admin-card <?= $slotsEnabled ? 'mt-4' : '' ?>" aria-labelledby="at-aorder-delivery-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-delivery-title">Delivery charges</h2></div>
            <div class="at-admin-card__body space-y-3">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" class="at-focusable" data-at-aorder-apply-delivery checked>
                    Apply delivery charges
                </label>
                <details class="at-aorder-advanced" data-at-aorder-delivery-advanced>
                    <summary class="at-focusable">Advanced delivery options</summary>
                    <div class="pt-3 space-y-2" data-at-aorder-free-delivery-wrap>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" class="at-focusable" data-at-aorder-free-delivery>
                            Free delivery (admin override)
                        </label>
                        <div hidden data-at-aorder-free-reason-wrap>
                            <label class="at-label" for="at-aorder-free-reason">Override reason (required)</label>
                            <input id="at-aorder-free-reason" type="text" class="at-input at-focusable w-full max-w-lg" maxlength="255" data-at-aorder-free-reason>
                        </div>
                    </div>
                </details>
                <p class="text-sm" data-at-aorder-delivery-summary><span class="at-muted">Delivery is calculated from the selected address and items.</span></p>
                <?php if (!$slotsEnabled): ?>
                <p class="text-sm" data-at-aorder-delivery-when aria-live="polite"><span class="at-muted">Next available delivery slot will be used.</span></p>
                <?php endif; ?>
            </div>
        </section>

        <div class="at-aorder-step-footer">
            <p class="at-aorder-step-footer__error" data-at-aorder-step-error role="alert" hidden></p>
            <div class="at-aorder-step-footer__actions">
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($ordersIndexUrl) ?>">Cancel</a>
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-aorder-prev="2">Back</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-next="4">Continue to Payment</button>
            </div>
        </div>
    </div>

    <!-- STEP 4 — Payment & confirmation -->
    <div data-at-aorder-step-panel="4" role="group" aria-label="Step 4 of 4: Payment and confirmation" hidden class="at-aorder-is-hidden">
        <section class="at-admin-card" aria-labelledby="at-aorder-discounts-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-discounts-title">Discounts</h2></div>
            <div class="at-admin-card__body space-y-4">
                <fieldset class="space-y-3 max-w-2xl">
                    <legend class="sr-only">Discount type</legend>
                    <label class="flex gap-2 text-sm items-start">
                        <input type="radio" name="at_aorder_discount_mode" class="at-focusable mt-1" value="none" data-at-aorder-discount-none checked>
                        <span><strong>No additional discount</strong><br><span class="at-muted text-xs">No coupon or custom discount. Automatic promotions still apply.</span></span>
                    </label>
                    <label class="flex gap-2 text-sm items-start">
                        <input type="radio" name="at_aorder_discount_mode" class="at-focusable mt-1" value="coupon" data-at-aorder-discount-coupon-mode>
                        <span><strong>Apply coupon</strong><br><span class="at-muted text-xs">Customer has a valid promotional coupon code.</span></span>
                    </label>
                    <label class="flex gap-2 text-sm items-start">
                        <input type="radio" name="at_aorder_discount_mode" class="at-focusable mt-1" value="custom" data-at-aorder-discount-custom-mode>
                        <span><strong>Admin custom discount</strong><br><span class="at-muted text-xs">Grant a special discount manually (merchandise only).</span></span>
                    </label>
                </fieldset>
                <div data-at-aorder-discount-coupon-panel data-at-aorder-panel hidden class="at-aorder-is-hidden">
                    <div class="max-w-lg space-y-2">
                    <div class="flex flex-wrap gap-2 items-end">
                        <div class="flex-1 min-w-[12rem]">
                            <label class="at-label" for="at-aorder-coupon">Coupon code</label>
                            <input id="at-aorder-coupon" type="text" class="at-input at-focusable w-full uppercase" autocomplete="off" data-at-aorder-coupon>
                        </div>
                        <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-aorder-coupon-apply disabled>Apply coupon</button>
                        <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-aorder-coupon-clear hidden>Remove coupon</button>
                    </div>
                    <p class="text-sm" data-at-aorder-coupon-status hidden aria-live="polite"></p>
                    </div>
                </div>
                <div data-at-aorder-discount-custom-panel data-at-aorder-panel hidden class="at-aorder-is-hidden">
                    <div class="grid gap-3 sm:grid-cols-3 max-w-2xl">
                        <div>
                            <label class="at-label" for="at-aorder-custom-type">Type</label>
                            <select id="at-aorder-custom-type" class="at-input at-focusable w-full" data-at-aorder-custom-type>
                                <option value="flat">Flat amount (₹)</option>
                                <option value="percent">Percentage (%)</option>
                            </select>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-custom-value">Value</label>
                            <input id="at-aorder-custom-value" type="number" class="at-input at-focusable w-full" min="0" step="0.01" data-at-aorder-custom-value>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="at-label" for="at-aorder-custom-discount-reason">Reason</label>
                            <input id="at-aorder-custom-discount-reason" type="text" class="at-input at-focusable w-full" maxlength="255" data-at-aorder-custom-discount-reason>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="at-admin-card mt-4" aria-labelledby="at-aorder-payment-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-payment-title">Payment</h2></div>
            <div class="at-admin-card__body space-y-4">
                <fieldset class="space-y-2">
                    <legend class="sr-only">Payment mode</legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="at_aorder_pay_mode" class="at-focusable" value="pending" data-at-aorder-pay-pending checked>
                        Pending payment — customer pays online later
                    </label>
                    <?php if ($canRecordOffline): ?>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="at_aorder_pay_mode" class="at-focusable" value="offline_paid" data-at-aorder-pay-offline>
                        Already paid — offline payment (UPI / bank transfer)
                    </label>
                    <?php endif; ?>
                </fieldset>
                <p class="text-sm at-muted max-w-2xl" data-at-aorder-pending-pay-hint>The customer will receive the usual pending-payment email and can pay online via Razorpay/Cashfree.</p>
                <div data-at-aorder-offline-wrap data-at-aorder-panel hidden class="at-aorder-is-hidden">
                    <div class="grid gap-3 sm:grid-cols-2 max-w-2xl">
                        <div>
                            <label class="at-label" for="at-aorder-offline-method">Payment method *</label>
                            <select id="at-aorder-offline-method" class="at-input at-focusable w-full" data-at-aorder-offline-method required>
                                <option value="upi">UPI</option>
                                <option value="bank_transfer">Bank transfer</option>
                            </select>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="method" hidden></p>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-offline-amount">Amount received (₹) *</label>
                            <input id="at-aorder-offline-amount" type="number" class="at-input at-focusable w-full" min="0" step="0.01" readonly data-at-aorder-offline-amount required>
                            <p class="text-xs at-muted mt-1">Full payable amount — filled from the latest quote.</p>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="amount" hidden></p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="at-label" for="at-aorder-offline-ref">Transaction / reference number *</label>
                            <input id="at-aorder-offline-ref" type="text" class="at-input at-focusable w-full" maxlength="191" placeholder="UTR / UPI transaction ID / bank reference" data-at-aorder-offline-ref required>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="reference" hidden></p>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-offline-pay-date">Payment date *</label>
                            <input id="at-aorder-offline-pay-date" type="date" class="at-input at-focusable w-full" data-at-aorder-offline-pay-date required>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="date" hidden></p>
                        </div>
                        <div>
                            <label class="at-label" for="at-aorder-offline-pay-time">Payment time *</label>
                            <input id="at-aorder-offline-pay-time" type="time" class="at-input at-focusable w-full" data-at-aorder-offline-pay-time required>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="time" hidden></p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="at-label" for="at-aorder-offline-note">Verification note *</label>
                            <textarea id="at-aorder-offline-note" class="at-input at-focusable w-full" rows="2" maxlength="500" placeholder="How was this payment verified? (e.g. checked bank statement / UPI app)" data-at-aorder-offline-note required></textarea>
                            <p class="text-xs text-red-700 mt-1" data-at-aorder-offline-err="note" hidden></p>
                        </div>
                        <details class="at-aorder-advanced sm:col-span-2">
                            <summary class="at-focusable">Additional details (optional)</summary>
                            <div class="pt-3">
                                <label class="at-label" for="at-aorder-offline-provider">Provider / bank name (optional)</label>
                                <input id="at-aorder-offline-provider" type="text" class="at-input at-focusable w-full" maxlength="128" placeholder="e.g. PhonePe, GPay, HDFC Bank" data-at-aorder-offline-provider>
                            </div>
                        </details>
                        <div class="sm:col-span-2 at-aorder-verify">
                            <label class="flex items-start gap-2 text-sm font-semibold">
                                <input type="checkbox" class="at-focusable mt-1" data-at-aorder-offline-confirm value="1">
                                <span>I confirm that the payment has been received and verified. *</span>
                            </label>
                            <p class="text-xs at-muted mt-1 mb-0">Required to record this order as paid. The server re-verifies method, reference, date/time, note and amount before marking the order paid.</p>
                            <p class="text-xs text-red-700 mt-1 mb-0" data-at-aorder-offline-err="confirm" hidden></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="at-admin-card mt-4" aria-labelledby="at-aorder-summary-title">
            <div class="at-admin-card__head"><h2 id="at-aorder-summary-title">Review &amp; confirm</h2></div>
            <div class="at-admin-card__body">
                <div class="at-aorder-review-grid">
                    <section class="at-aorder-review-group" aria-label="Customer and delivery">
                        <h3 class="at-aorder-review-group__title">Customer &amp; delivery</h3>
                        <dl class="at-aorder-review text-sm" data-at-aorder-review></dl>
                    </section>
                    <section class="at-aorder-review-group" aria-label="Order items and totals">
                        <h3 class="at-aorder-review-group__title">Items &amp; totals</h3>
                        <dl class="at-aorder-review text-sm" data-at-aorder-review-items></dl>
                        <p class="text-sm at-muted" data-at-aorder-quote-status>Totals will be calculated when the order is ready.</p>
                        <dl class="at-aorder-totals text-sm" data-at-aorder-quote-dl hidden></dl>
                    </section>
                    <section class="at-aorder-review-group" aria-label="Payment summary">
                        <h3 class="at-aorder-review-group__title">Payment</h3>
                        <dl class="at-aorder-review text-sm" data-at-aorder-pay-summary></dl>
                    </section>
                </div>
                <div class="mt-4 flex flex-wrap gap-2 items-center">
                    <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-aorder-refresh-quote disabled>Refresh totals</button>
                    <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-place disabled<?= $migrationReady ? '' : ' title="Apply migration 1.40.0 first"' ?>>
                        Create order — Pending payment
                    </button>
                </div>
                <p class="text-xs text-amber-800 mt-2" data-at-aorder-offline-gate-hint hidden role="status"></p>
                <div class="mt-3" data-at-aorder-place-result hidden></div>
            </div>
        </section>

        <div class="at-aorder-step-footer">
            <p class="at-aorder-step-footer__error" data-at-aorder-step-error role="alert" hidden></p>
            <div class="at-aorder-step-footer__actions">
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($ordersIndexUrl) ?>">Cancel</a>
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-aorder-prev="3">Back</button>
            </div>
        </div>
    </div>
</div>

<?php
$esc = static fn (string $s): string => Security::escape($s);
?>
<script>window.AT_ADDRESS_AUTOCOMPLETE = <?= json_encode(AddressAutocompleteSettings::publicClientConfig(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= $esc(asset_url('assets/js/address-autocomplete.js')) ?>"></script>
<script src="<?= $esc(asset_url('assets/js/admin-assisted-order.js')) ?>"></script>
<?php
admin_layout_end();
