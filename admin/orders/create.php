<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminAssistedOrderService::denyUnlessCanCreate();

$base = url_base();
$ajaxBase = $base . '/admin/ajax';
$csrfToken = Csrf::token();
$csrfField = Csrf::fieldName();
$migrationReady = AdminAssistedOrderService::columnsReady();

admin_layout_start([
    'pageTitle' => 'Create order for customer',
    'activeAdminNav' => 'orders',
]);
$pageHeading = 'Create order on behalf of customer';
$pageDescription = 'Select a customer, address and products. Review the server-calculated total, then create a pending-payment order for the customer to pay online.';
$slotsEnabled = DeliveryConfig::slotsEnabled();
$checkoutSlots = $slotsEnabled ? AdminAssistedOrderService::checkoutSlotOptionsForAdmin() : [];
$defaultSlot = $slotsEnabled && $checkoutSlots !== [] ? (string) ($checkoutSlots[0]['slug'] ?? '') : 'auto';
$defaultSlotDate = $slotsEnabled && $checkoutSlots !== [] ? (string) ($checkoutSlots[0]['delivery_date'] ?? '') : '';
$breadcrumbs = [
    ['label' => 'Orders', 'href' => $base . '/admin/orders/index.php'],
    ['label' => 'Create order'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape($base) ?>/admin/orders/index.php">← Back to orders</a></p>

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
     data-url-variants="<?= Security::escape($ajaxBase . '/assisted-order-variants.php') ?>"
     data-url-line="<?= Security::escape($ajaxBase . '/assisted-order-line.php') ?>"
     data-url-quote="<?= Security::escape($ajaxBase . '/assisted-order-quote.php') ?>"
     data-url-place="<?= Security::escape($ajaxBase . '/assisted-order-place.php') ?>"
     data-slots-enabled="<?= $slotsEnabled ? '1' : '0' ?>"
     data-default-slot="<?= Security::escape($defaultSlot) ?>"
     data-default-slot-date="<?= Security::escape($defaultSlotDate) ?>">

    <section class="at-admin-card" aria-labelledby="at-aorder-customer-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-customer-title">1. Customer</h2></div>
        <div class="at-admin-card__body">
            <div class="at-assisted-order__customer" data-at-aorder-customer-picker>
                <label class="at-label" for="at-aorder-customer-search">Search customer</label>
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

    <section class="at-admin-card" aria-labelledby="at-aorder-address-title" data-at-aorder-address-section hidden>
        <div class="at-admin-card__head"><h2 id="at-aorder-address-title">2. Delivery address</h2></div>
        <div class="at-admin-card__body">
            <p class="text-sm at-muted mb-3" data-at-aorder-address-empty hidden>No saved addresses for this customer.</p>
            <div class="space-y-2" data-at-aorder-address-list role="radiogroup" aria-label="Delivery address"></div>
        </div>
    </section>

    <section class="at-admin-card" aria-labelledby="at-aorder-products-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-products-title">3. Products</h2></div>
        <div class="at-admin-card__body">
            <div class="grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-end">
                <div>
                    <label class="at-label" for="at-aorder-variant-search">Search product or variant</label>
                    <input id="at-aorder-variant-search" type="search" class="at-input at-focusable w-full"
                           autocomplete="off" placeholder="Product name, slug or SKU">
                    <p class="text-xs at-muted mt-1" data-at-aorder-variant-status>Type at least 2 characters.</p>
                    <ul class="at-picker__results mt-1" data-at-aorder-variant-results hidden role="listbox"></ul>
                    <nav class="flex flex-wrap items-center gap-2 mt-2 text-sm" data-at-aorder-variant-pager hidden></nav>
                </div>
                <div>
                    <label class="at-label" for="at-aorder-qty">Quantity</label>
                    <input id="at-aorder-qty" type="number" class="at-input at-focusable w-full" min="1" step="1" value="1" inputmode="numeric">
                </div>
                <div>
                    <button type="button" class="at-btn at-btn--secondary at-focusable w-full md:w-auto" data-at-aorder-add-line disabled>
                        Add to order
                    </button>
                </div>
            </div>
            <div class="mt-3 p-3 rounded-lg border border-gray-200 bg-gray-50 text-sm" data-at-aorder-variant-preview hidden></div>
        </div>
    </section>

    <section class="at-admin-card" aria-labelledby="at-aorder-items-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-items-title">4. Order items</h2></div>
        <div class="at-admin-card__body">
            <p class="text-xs at-muted mb-3">Line prices are revalidated on the server when you refresh the quote or place the order.</p>
            <div class="overflow-x-auto">
                <table class="at-admin-table w-full min-w-[32rem]" data-at-aorder-items-table>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Variant</th>
                            <th scope="col">SKU</th>
                            <th scope="col" class="text-right">Unit price</th>
                            <th scope="col" class="text-right">Qty</th>
                            <th scope="col" class="text-right">Line total</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
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

    <?php if ($slotsEnabled): ?>
    <section class="at-admin-card" aria-labelledby="at-aorder-slot-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-slot-title">5. Delivery slot</h2></div>
        <div class="at-admin-card__body">
            <?php if ($checkoutSlots === []): ?>
                <p class="text-sm at-muted">Next available slot will be selected automatically when you refresh the quote.</p>
                <input type="hidden" value="auto" data-at-aorder-delivery-slot>
            <?php else: ?>
            <label class="at-label" for="at-aorder-delivery-slot">Slot</label>
            <select id="at-aorder-delivery-slot" class="at-input at-focusable w-full max-w-md" data-at-aorder-delivery-slot>
                <?php foreach ($checkoutSlots as $sl):
                    $slug = (string) ($sl['slug'] ?? '');
                    $date = (string) ($sl['delivery_date'] ?? '');
                    $label = (string) ($sl['label'] ?? $slug);
                    ?>
                <option value="<?= Security::escape($slug) ?>" data-date="<?= Security::escape($date) ?>">
                    <?= Security::escape($label) ?><?= $date !== '' ? ' · ' . Security::escape($date) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="at-admin-card" aria-labelledby="at-aorder-delivery-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-delivery-title"><?= $slotsEnabled ? '6' : '5' ?>. Delivery charges</h2></div>
        <div class="at-admin-card__body space-y-3">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="at-focusable" data-at-aorder-apply-delivery checked>
                Apply delivery charges
            </label>
            <div class="pl-0 sm:pl-6 space-y-2" data-at-aorder-free-delivery-wrap>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" class="at-focusable" data-at-aorder-free-delivery>
                    Free delivery (admin override)
                </label>
                <div hidden data-at-aorder-free-reason-wrap>
                    <label class="at-label" for="at-aorder-free-reason">Override reason</label>
                    <input id="at-aorder-free-reason" type="text" class="at-input at-focusable w-full max-w-lg" maxlength="255" data-at-aorder-free-reason>
                </div>
            </div>
            <p class="text-sm" data-at-aorder-delivery-summary><span class="at-muted">Select customer, address and items for a delivery quote.</span></p>
        </div>
    </section>

    <section class="at-admin-card" aria-labelledby="at-aorder-discounts-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-discounts-title"><?= $slotsEnabled ? '7' : '6' ?>. Coupon &amp; custom discount</h2></div>
        <div class="at-admin-card__body space-y-4">
            <div class="flex flex-wrap gap-2 items-end max-w-lg">
                <div class="flex-1 min-w-[12rem]">
                    <label class="at-label" for="at-aorder-coupon">Coupon code</label>
                    <input id="at-aorder-coupon" type="text" class="at-input at-focusable w-full uppercase" autocomplete="off" data-at-aorder-coupon>
                </div>
                <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-aorder-coupon-clear>Clear</button>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="at-focusable" data-at-aorder-custom-enabled>
                Apply custom discount (merchandise only)
            </label>
            <div class="grid gap-3 sm:grid-cols-3 max-w-2xl" data-at-aorder-custom-wrap hidden>
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
                    <label class="at-label" for="at-aorder-custom-reason">Reason</label>
                    <input id="at-aorder-custom-reason" type="text" class="at-input at-focusable w-full" maxlength="255" data-at-aorder-custom-reason>
                </div>
            </div>
        </div>
    </section>

    <section class="at-admin-card" aria-labelledby="at-aorder-summary-title">
        <div class="at-admin-card__head"><h2 id="at-aorder-summary-title"><?= $slotsEnabled ? '8' : '7' ?>. Order summary</h2></div>
        <div class="at-admin-card__body">
            <p class="text-sm at-muted mb-2" data-at-aorder-quote-status>Complete the sections above to calculate totals.</p>
            <dl class="text-sm space-y-1 max-w-md" data-at-aorder-quote-dl hidden></dl>
            <div class="mt-4 flex flex-wrap gap-2 items-center">
                <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-aorder-refresh-quote disabled>Refresh quote</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" data-at-aorder-place disabled<?= $migrationReady ? '' : ' title="Apply migration 1.40.0 first"' ?>>
                    Create order (pending payment)
                </button>
            </div>
            <div class="mt-3" data-at-aorder-place-result hidden></div>
        </div>
    </section>
</div>

<?php
$esc = static fn (string $s): string => Security::escape($s);
?>
<script src="<?= $esc(asset_url('assets/js/admin-assisted-order.js')) ?>"></script>
<?php
admin_layout_end();
