<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (DemoCart::items() === []) {
    header('Location: ' . url_base() . '/cart.php', true, 302);
    exit;
}

$error = '';
$selectedAddressId = '';
// Issue fresh nonce on page load only. Rotating before POST handling invalidates the hidden
// checkout_nonce submitted with place_order (same request).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['checkout_nonce'])) {
    $checkoutNonce = CheckoutService::freshNonce();
} else {
    $checkoutNonce = (string) $_SESSION['checkout_nonce'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    $persistedCheckout = AppEnvironment::usePersistedStorefront();
    $dbAddressMode = $persistedCheckout && CustomerAuth::check() && CustomerAuth::id() !== null;

    if (($action === 'add_address' || $action === 'update_address') && $dbAddressMode) {
        // Same validation and persistence as my-addresses.php (AddressInput via CustomerAccountService).
        // The customer id always comes from the session; any posted customer id is ignored.
        $addrPostValues = AddressInput::formValuesFromPost($_POST);
        $editAddressId = isset($_POST['address_id']) && is_string($_POST['address_id']) && ctype_digit($_POST['address_id']) ? (int) $_POST['address_id'] : 0;
        if ($action === 'add_address') {
            $res = CustomerAccountService::addAddress((int) CustomerAuth::id(), $_POST, false);
        } else {
            $res = CustomerAccountService::updateAddress((int) CustomerAuth::id(), $editAddressId, $_POST);
        }
        if (!empty($res['ok']) && !empty($res['id'])) {
            header('Location: ' . url_base() . '/checkout.php?address=' . (int) $res['id'], true, 303);
            exit;
        }
        $addrFormErrors = $res['errors'] ?? ['form' => 'Could not save address.'];
        $addrFormOpenMode = $action === 'update_address' ? 'update' : 'add';
        $addrFormOpenId = $editAddressId;
    } elseif ($action === 'add_address') {
        if (AppEnvironment::usePersistedStorefront()) {
            $_SESSION['checkout_return'] = url_base() . '/checkout.php';
            header('Location: ' . url_base() . '/login.php', true, 302);
            exit;
        }
        // Demo/session mode (no database): legacy single-line form.
        $line1 = isset($_POST['line1']) && is_string($_POST['line1']) ? trim($_POST['line1']) : '';
        $pin = isset($_POST['pincode']) && is_string($_POST['pincode']) ? trim($_POST['pincode']) : '';
        $result = CustomerService::addAddress($line1, $pin);
        if (!$result['ok']) {
            $error = $result['message'] ?? 'Could not save address.';
        } elseif (!empty($result['id'])) {
            $selectedAddressId = (string) $result['id'];
        }
    }

    if ($action === 'place_order') {
        if (!CustomerService::isLoggedIn()) {
            $_SESSION['checkout_return'] = url_base() . '/checkout.php';
            header('Location: ' . url_base() . '/login.php', true, 302);
            exit;
        }
        $result = CheckoutService::placeOrder([
            'checkout_nonce' => $_POST['checkout_nonce'] ?? '',
            'address_id' => $_POST['address_id'] ?? '',
            'delivery_slot' => $_POST['delivery_slot'] ?? '',
            'delivery_date' => $_POST['delivery_date'] ?? '',
            'payment_sim' => $_POST['payment_sim'] ?? 'success',
            'terms' => $_POST['terms'] ?? null,
            'customer_name' => $_POST['customer_name'] ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
        ]);
        if (!$result['ok']) {
            $error = $result['message'] ?? 'Checkout failed.';
            $checkoutNonce = CheckoutService::freshNonce();
        } elseif (!empty($result['redirect'])) {
            header('Location: ' . $result['redirect'], true, 302);
            exit;
        }
    }
}

$loggedIn = CustomerService::isLoggedIn();
$persistedCheckout = AppEnvironment::usePersistedStorefront();
$dbAddressMode = $persistedCheckout && $loggedIn && CustomerAuth::id() !== null;
$addressRows = $dbAddressMode ? CustomerAccountService::listAddresses((int) CustomerAuth::id()) : [];
if ($dbAddressMode) {
    $addresses = array_map(static function (array $r): array {
        return ['id' => (string) $r['id']] + $r;
    }, $addressRows);
} elseif ($persistedCheckout) {
    $addresses = [];
} else {
    $addresses = CustomerService::addresses();
}
$addrFormErrors = $addrFormErrors ?? [];
$addrFormOpenMode = $addrFormOpenMode ?? '';
$addrFormOpenId = $addrFormOpenId ?? 0;
$addrProfile = $dbAddressMode ? (CustomerAccountService::profile((int) CustomerAuth::id()) ?? []) : [];
$addrFormValues = isset($addrPostValues) ? $addrPostValues : AddressInput::blankFormValues($addrProfile);
$checkoutProfileForForm = CustomerService::profile();
$checkoutContactName = trim((string) ($checkoutProfileForForm['name'] ?? ''));
$checkoutContactEmail = trim((string) ($checkoutProfileForForm['email'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['customer_name']) && is_string($_POST['customer_name'])) {
        $checkoutContactName = trim($_POST['customer_name']);
    }
    if (isset($_POST['customer_email']) && is_string($_POST['customer_email'])) {
        $checkoutContactEmail = trim($_POST['customer_email']);
    }
}
if ($dbAddressMode && $addrFormOpenMode === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['address']) && is_string($_GET['address']) && ctype_digit($_GET['address'])) {
        $selectedAddressId = $_GET['address'];
    }
    if (isset($_GET['edit_address']) && is_string($_GET['edit_address']) && ctype_digit($_GET['edit_address'])) {
        $editRow = CustomerAccountService::findAddress((int) CustomerAuth::id(), (int) $_GET['edit_address']);
        if ($editRow !== null) {
            $addrFormOpenMode = 'update';
            $addrFormOpenId = (int) $editRow['id'];
            $addrFormValues = AddressInput::formValuesFromRow($editRow, $addrProfile);
            $selectedAddressId = (string) $editRow['id'];
        }
    }
}
$canShowDeliverySlots = $loggedIn && $addresses !== [];
$checkoutSlots = DeliveryConfig::slotsEnabled() && $canShowDeliverySlots
    ? DeliverySlotService::availableForCheckout()
    : [];
$defaultSlotSlug = DeliveryConfig::slotsEnabled()
    ? ($checkoutSlots !== [] ? (string) ($checkoutSlots[0]['slug'] ?? '') : null)
    : 'auto';
$defaultSlotDate = DeliveryConfig::slotsEnabled() && $checkoutSlots !== []
    ? (string) ($checkoutSlots[0]['delivery_date'] ?? '')
    : null;
// Addresses are ordered default-first; a just-added address (or one re-posted with the form) wins.
if ($selectedAddressId === '' && isset($_POST['address_id']) && is_string($_POST['address_id'])) {
    $selectedAddressId = $_POST['address_id'];
}
$validIds = array_map(static function (array $a): string {
    return (string) $a['id'];
}, $addresses);
if (!in_array($selectedAddressId, $validIds, true)) {
    $selectedAddressId = $addresses !== [] ? (string) ($addresses[0]['id'] ?? '') : '';
}
$defaultAddressId = $loggedIn && $selectedAddressId !== '' ? (int) $selectedAddressId : null;
$totals = CartService::totals($defaultAddressId, $defaultSlotSlug, $defaultSlotDate);
$paymentGwMeta = PaymentGatewayConfig::publicCheckoutConfig();
$paymentGatewayLabel = PaymentGatewayConfig::gatewayLabel((string) ($paymentGwMeta['gateway'] ?? 'simulate'));
$paymentUsesSimulate = PaymentGatewayConfig::useSimulatedCheckout();
$paymentLiveReady = PaymentGatewayConfig::assertLivePaymentsReady();
$paymentGatewayReady = $paymentUsesSimulate || !empty($paymentLiveReady['ok']);
$paymentGatewayBlockedMessage = $paymentGatewayReady
    ? ''
    : (string) ($paymentLiveReady['message'] ?? 'Payment gateway is not ready. Check Settings → Payment gateways.');
$slotsOkInitial = !DeliveryConfig::slotsEnabled() || ($canShowDeliverySlots && $checkoutSlots !== []);
$checkoutMinOrderMet = empty($totals['min_order_enabled']) || !empty($totals['min_order_met']);
$checkoutQuoteReady = $loggedIn
    && $addresses !== []
    && $slotsOkInitial
    && empty($totals['coupon_error'])
    && !empty($totals['delivery_quote_accurate'])
    && ($totals['delivery_serviceable'] ?? false)
    && trim((string) ($totals['delivery_quote_message'] ?? '')) === ''
    && $checkoutMinOrderMet;

/** Map-free address form whenever persisted checkout addresses are used; optional search when autocomplete is enabled in settings. */
$checkoutAddrMapFree = $dbAddressMode;

layout_public_start([
    'pageTitle' => 'Checkout — Veggie Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
    'loadAddressMap' => false,
    'loadAddressAutocompleteJs' => $checkoutAddrMapFree,
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
?>
<div class="at-pagehead">
    <div class="at-container pb-5">
        <nav class="at-breadcrumb" aria-label="Breadcrumb"><a href="<?= Security::escape(url_base()) ?>/cart.php">Cart</a><span class="sep">/</span><span aria-current="page">Checkout</span></nav>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="at-h1">Checkout</h1>
            <div class="at-steps-bar" aria-label="Checkout progress"><span class="is-done"><b>✓</b>Cart</span><i class="line"></i><span class="is-current"><b>2</b>Details</span><i class="line"></i><span><b>3</b>Payment</span></div>
        </div>
    </div>
</div>

<div class="at-container py-6 md:py-8 grid lg:grid-cols-[1fr_24rem] gap-6 lg:gap-10 items-start at-checkout-grid">
    <div class="grid gap-4 at-checkout-main">
        <?php if ($error !== ''): ?>
            <div class="at-alert at-alert--error" role="alert"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($error) ?></span></div>
        <?php endif; ?>

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">1</span><h2 class="at-h3">Contact</h2></div>
            <?php if ($loggedIn): ?>
                <?php
                $checkoutAvatarInitial = $checkoutContactName !== ''
                    ? strtoupper(mb_substr($checkoutContactName, 0, 1))
                    : strtoupper(mb_substr((string) ($checkoutProfileForForm['mobile'] ?? 'C'), 0, 1));
                ?>
                <p class="flex items-center gap-3">
                    <span class="at-avatar"><?= Security::escape($checkoutAvatarInitial) ?></span>
                    <span class="text-sm at-muted">Registered mobile<br><strong class="text-[var(--at-ink)] font-semibold"><?= Security::escape($checkoutProfileForForm['mobile'] ?? '') ?></strong></span>
                </p>
                <div class="at-checkout-contact-fields mt-4 grid gap-4">
                    <div>
                        <label class="at-label" for="checkout-customer-name">Full name</label>
                        <input id="checkout-customer-name" name="customer_name" type="text" autocomplete="name"
                               form="checkout-form" class="at-input at-focusable" maxlength="100"
                               placeholder="Enter your full name" value="<?= Security::escape($checkoutContactName) ?>">
                        <p class="text-xs at-muted mt-1.5">Your account name for orders and invoices — not the delivery receiver.</p>
                    </div>
                    <div>
                        <label class="at-label" for="checkout-customer-email">Email address <span class="at-muted font-normal">(optional)</span></label>
                        <input id="checkout-customer-email" name="customer_email" type="email" inputmode="email" autocomplete="email"
                               form="checkout-form" class="at-input at-focusable" maxlength="191"
                               placeholder="Enter your email address" value="<?= Security::escape($checkoutContactEmail) ?>">
                        <p class="text-xs at-muted mt-1.5">For order confirmation and invoice updates.</p>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-sm at-muted">We verify your mobile number with an OTP before you can place the order.</p>
                <a href="<?= Security::escape(url_base()) ?>/login.php" class="at-btn at-btn--primary at-focusable mt-3">Log in with mobile OTP</a>
            <?php endif; ?>
        </section>

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">2</span><h2 class="at-h3">Delivery address</h2></div>
            <p class="text-xs at-muted -mt-2 mb-3">Serviceability for Indore is checked before payment.<?php if ($loggedIn && $persistedCheckout): ?> <a href="<?= Security::escape(url_base()) ?>/my-addresses.php" class="underline">Manage saved addresses</a><?php endif; ?></p>
            <?php if ($persistedCheckout && !$loggedIn): ?>
                <p class="text-sm at-muted">Log in with your mobile OTP to add and choose a delivery address.</p>
            <?php elseif ($loggedIn && $addresses === []): ?>
                <p class="text-sm at-muted">Add a delivery address below to continue.</p>
            <?php endif; ?>
            <?php if ($dbAddressMode): require_once APNI_TOKRI_ROOT . '/includes/partials/address-summary.php'; ?>
                <?php if ($addresses !== []): ?>
                    <div class="grid gap-3" role="radiogroup" aria-label="Saved delivery addresses">
                        <?php foreach ($addressRows as $addr): $isSel = (string) $addr['id'] === $selectedAddressId; $pinOk = AddressInput::hasPin($addr); ?>
                            <label class="at-addr-card">
                                <input type="radio" name="address_id" value="<?= (int) $addr['id'] ?>" class="mt-1" form="checkout-form" <?= $isSel ? 'checked' : '' ?>>
                                <span class="at-addr-card__body">
                                    <?= at_address_summary_html($addr) ?>
                                    <?php if (!empty($addr['delivery_instructions'])): ?><span class="at-addr-card__meta block text-xs">Instructions: <?= Security::escape((string) $addr['delivery_instructions']) ?></span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="at-addr-card__actions">
                        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" data-checkout-edit href="<?= Security::escape(url_base()) ?>/checkout.php?edit_address=<?= (int) $selectedAddressId ?>#checkout-address-form">Edit selected address</a>
                    </div>
                <?php endif; ?>
                <?php if ($loggedIn): ?>
                    <details class="at-card at-card--flat mt-3" id="checkout-address-form" <?= ($addrFormOpenMode !== '' || $addresses === []) ? 'open' : '' ?>>
                        <summary class="at-card__body !py-3 cursor-pointer font-bold text-[var(--at-green)] flex items-center gap-2"><?= at_icon('plus', 'w-4 h-4') ?><?= $addrFormOpenMode === 'update' ? 'Edit delivery address' : 'Add a new address' ?></summary>
                        <div class="at-card__body !pt-0">
                            <?php
                            $addrFormAction = url_base() . '/checkout.php#checkout-address-form';
                            $addrFormMode = $addrFormOpenMode === 'update' ? 'update' : 'add';
                            $addrFormId = (int) $addrFormOpenId;
                            $addrFormErrors = $addrFormErrors;
                            $addrFormCancelUrl = url_base() . '/checkout.php' . ($selectedAddressId !== '' ? '?address=' . (int) $selectedAddressId : '');
                            $addrFormShowDefault = false;
                            $addrFormHidden = [];
                            $addrFormAddAction = 'add_address';
                            $addrFormUpdateAction = 'update_address';
                            $addrFormUid = 'co';
                            $addrAutocompleteClientConfig = AddressAutocompleteSettings::publicClientConfig();
                            require APNI_TOKRI_ROOT . '/includes/partials/address-form-checkout.php';
                            ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php elseif (!$persistedCheckout): ?>
            <form method="post" class="grid gap-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="add_address">
                <?php foreach ($addresses as $addr): ?>
                    <label class="at-option">
                        <input type="radio" name="address_id" value="<?= Security::escape($addr['id']) ?>" class="mt-1" form="checkout-form" <?= (string) $addr['id'] === $selectedAddressId ? 'checked' : '' ?>>
                        <span class="text-sm"><strong><?= Security::escape($addr['label']) ?></strong><?= ($addr['is_default'] ?? '0') === '1' ? ' <span class="at-badge at-badge--success ml-1">Default</span>' : '' ?><br><?= Security::escape($addr['line1']) ?>, <?= Security::escape($addr['city']) ?> — <?= Security::escape($addr['pincode']) ?></span>
                    </label>
                <?php endforeach; ?>
                <details class="at-card at-card--flat" <?= $error !== '' ? 'open' : '' ?>>
                    <summary class="at-card__body !py-3 cursor-pointer font-bold text-[var(--at-green)] flex items-center gap-2"><?= at_icon('plus', 'w-4 h-4') ?>Add a new address</summary>
                    <div class="at-card__body !pt-0 grid sm:grid-cols-2 gap-3">
                        <div class="sm:col-span-2"><label class="at-label" for="line1">Address</label><input id="line1" name="line1" class="at-input at-focusable" placeholder="House / flat, street, area"></div>
                        <div><label class="at-label" for="pincode">Pincode</label><input id="pincode" name="pincode" class="at-input at-focusable" placeholder="452001" inputmode="numeric" maxlength="6"></div>
                        <div class="flex items-end"><button type="submit" class="at-btn at-btn--secondary at-btn--block at-focusable">Save address</button></div>
                    </div>
                </details>
            </form>
            <?php endif; ?>
        </section>

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">3</span><h2 class="at-h3">Delivery slot</h2></div>
            <?php if ($persistedCheckout && !$loggedIn): ?>
                <p class="text-sm at-muted">Log in and choose a delivery address to see available slots.</p>
            <?php elseif (DeliveryConfig::slotsEnabled()):
                ?>
                <p class="text-sm at-muted -mt-2 mb-3">Capacity is re-checked when you pay (<?= Security::escape(DeliveryConfig::timezone()) ?>).</p>
                <?php if ($loggedIn && $addresses === []): ?>
                    <p class="text-sm at-muted">Add a delivery address above to choose a slot.</p>
                <?php endif; ?>
                <?php if ($canShowDeliverySlots && $checkoutSlots === []): ?>
                    <p class="at-alert at-alert--warning text-sm">No slots available right now. Try again later or contact support.</p>
                <?php elseif ($canShowDeliverySlots): ?>
                    <?php $initialSlotDate = (string) ($checkoutSlots[0]['delivery_date'] ?? ''); ?>
                    <div class="grid sm:grid-cols-3 gap-3" data-checkout-slot-grid>
                        <?php foreach ($checkoutSlots as $i => $s): ?>
                            <label class="at-option">
                                <input type="radio" name="delivery_slot" form="checkout-form" value="<?= Security::escape($s['slug']) ?>" class="mt-1"
                                       data-delivery-date="<?= Security::escape((string) ($s['delivery_date'] ?? '')) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                <span class="text-sm"><strong class="block"><?= Security::escape($s['label']) ?></strong><?= Security::escape((string) ($s['time_range'] ?? $s['window'] ?? '—')) ?>
                                    <br><span class="at-muted text-xs">Delivers <?= Security::escape((string) ($s['delivery_date'] ?? '')) ?> · <?= (int) $s['remaining'] ?> spots left</span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-sm"><?= Security::escape(DeliverySlotService::automaticEta()) ?></p>
                <input type="hidden" name="delivery_slot" form="checkout-form" value="auto">
            <?php endif; ?>
        </section>

        <section class="at-checkout-block at-checkout-pay">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">4</span><h2 class="at-h3">Payment</h2></div>
            <?php if (CommerceGateway::useDatabase()): ?>
                <div class="at-checkout-pay__card">
                    <div class="at-checkout-pay__row">
                        <span class="at-checkout-pay__label">Gateway</span>
                        <span class="at-checkout-pay__value"><?= Security::escape($paymentGatewayLabel) ?>
                            <?php if (!empty($paymentGwMeta['test_mode'])): ?><span class="at-badge">Test</span><?php endif; ?>
                            <?php if ($paymentUsesSimulate): ?><span class="at-badge at-badge--warning">Simulated</span><?php endif; ?>
                        </span>
                    </div>
                    <div class="at-checkout-pay__row">
                        <span class="at-checkout-pay__label">Amount payable</span>
                        <span class="at-checkout-pay__value at-checkout-pay__amount" data-checkout-pay-card-amount><?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></span>
                    </div>
                    <p class="at-checkout-pay__hint text-sm at-muted" data-checkout-pay-allowed<?= $checkoutMinOrderMet ? '' : ' hidden' ?>>You will pay on the next screen. Your order is confirmed only after the server verifies payment with <?= Security::escape($paymentGatewayLabel) ?>.</p>
                    <?php if (!$paymentGatewayReady): ?>
                        <p class="at-alert at-alert--warning text-sm mt-2" role="alert"><?= Security::escape($paymentGatewayBlockedMessage !== '' ? $paymentGatewayBlockedMessage : 'Online payment is temporarily unavailable. Ask the store admin to check gateway credentials under Settings → Payment gateways.') ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="at-option !cursor-default">
                <span class="text-[var(--at-green)] mt-0.5"><?= at_icon('wallet', 'w-5 h-5') ?></span>
                <span class="text-sm"><strong>Pay online</strong><br><span class="at-muted">UPI, cards and netbanking preview — use the demo buttons below.</span></span>
            </div>
            <?php endif; ?>
            <?php
            $checkoutSubTermsUrl = CmsPageService::publicPageUrl('subscription-terms');
            $checkoutRefundUrl = CmsPageService::publicPageUrl('refund-policy');
            $checkoutShippingUrl = CmsPageService::publicPageUrl('shipping-policy');
            ?>
            <label class="flex items-start gap-3 mt-4 text-sm" data-checkout-pay-allowed<?= $checkoutMinOrderMet ? '' : ' hidden' ?>>
                <input type="checkbox" form="checkout-form" name="terms" value="1" required class="mt-0.5">
                <span>I agree to the
                    <?php if ($checkoutRefundUrl !== null): ?><a class="underline font-semibold" href="<?= Security::escape($checkoutRefundUrl) ?>">refund</a><?php else: ?>refund<?php endif; ?>,
                    <?php if ($checkoutShippingUrl !== null): ?><a class="underline font-semibold" href="<?= Security::escape($checkoutShippingUrl) ?>">delivery</a><?php else: ?>delivery<?php endif; ?>
                    and <?php if ($checkoutSubTermsUrl !== null): ?><a class="underline font-semibold" href="<?= Security::escape($checkoutSubTermsUrl) ?>">subscription</a><?php else: ?>subscription<?php endif; ?> terms.</span>
            </label>
        </section>

        <form id="checkout-form" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="place_order">
            <input type="hidden" name="checkout_nonce" value="<?= Security::escape($checkoutNonce) ?>">
            <?php if (DeliveryConfig::slotsEnabled() && $canShowDeliverySlots && ($checkoutSlots ?? []) !== []): ?>
                <input type="hidden" name="delivery_date" form="checkout-form" id="checkout-delivery-date" value="<?= Security::escape($initialSlotDate ?? '') ?>">
            <?php endif; ?>
            <?php if (!$loggedIn): ?><p class="at-alert at-alert--warning mb-3 text-sm"><?= at_icon('lock', 'w-5 h-5 flex-shrink-0') ?><span>Log in with your mobile number to enable payment.</span></p><?php endif; ?>
            <?php
            $canPay = $loggedIn && $addresses !== [];
            $slotsOk = $slotsOkInitial;
            ?>
            <div class="at-alert at-alert--warning text-sm mb-3 hidden" data-checkout-quote-error role="alert"></div>
            <div class="at-checkout-pay__blocked px-3 py-3 rounded-lg bg-[var(--at-cream-2)] border border-[var(--at-line)] text-sm space-y-2<?= $checkoutMinOrderMet ? ' hidden' : '' ?>" data-checkout-pay-blocked role="status">
                <p class="font-semibold">Checkout is paused — minimum order not met.</p>
                <p class="at-muted">Add <span class="font-bold tabular-nums" data-checkout-pay-blocked-shortfall><?= Security::escape(catalogue_format_price((float) ($totals['min_order_shortfall'] ?? 0))) ?></span> more in products to unlock payment. Delivery fee is extra.</p>
                <a href="<?= Security::escape(url_base()) ?>/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Browse products</a>
            </div>
            <div class="flex flex-col sm:flex-row gap-3" data-checkout-pay-allowed<?= $checkoutMinOrderMet ? '' : ' hidden' ?>>
                <?php if (CommerceGateway::useDatabase()): ?>
                    <button type="submit" id="checkout-proceed-pay" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" data-at-checkout-submit data-at-quote-ready="<?= ($checkoutQuoteReady && $paymentGatewayReady) ? '1' : '0' ?>" <?= ($canPay && $slotsOk && $paymentGatewayReady && $checkoutQuoteReady) ? '' : 'disabled' ?>><?= at_icon('lock', 'w-5 h-5') ?> Proceed to pay <span data-checkout-pay-amount><?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></span></button>
                <?php else: ?>
                    <button type="submit" name="payment_sim" value="success" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" <?= $canPay ? '' : 'disabled' ?>><?= at_icon('lock', 'w-5 h-5') ?> Pay <?= Security::escape(catalogue_format_price((float) $totals['total'])) ?> (demo success)</button>
                    <button type="submit" name="payment_sim" value="fail" class="at-btn at-btn--secondary at-btn--lg at-focusable" <?= $canPay ? '' : 'disabled' ?>>Simulate payment failure</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <aside class="at-summary grid gap-4" data-checkout-summary>
        <div class="at-card at-checkout-items"><div class="at-card__body">
            <h2 class="at-card__title">Your items</h2>
            <ul class="mt-3 grid gap-3">
                <?php foreach (CartService::items() as $it): ?>
                    <li class="flex gap-3 items-center">
                        <span class="w-12 h-12 rounded-lg overflow-hidden bg-[var(--at-cream-2)] flex-shrink-0"><?php if (!empty($it['image'])): ?><img src="<?= Security::escape((string) $it['image']) ?>" alt="" class="w-full h-full object-cover"><?php endif; ?></span>
                        <span class="flex-1 min-w-0 text-sm"><strong class="block truncate"><?= Security::escape((string) $it['name']) ?></strong><span class="at-muted"><?= Security::escape((string) $it['label']) ?> × <?= (int) $it['qty'] ?></span></span>
                        <span class="font-bold text-sm"><?= Security::escape(catalogue_format_price((float) $it['unit_price'] * (int) $it['qty'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div></div>
        <div class="at-card at-checkout-coupon-card">
            <details class="at-checkout-coupon"<?= catalogue_cart_coupon_applied($totals) ? ' open' : '' ?>>
                <summary class="at-checkout-coupon__head at-focusable">
                    <span class="at-checkout-coupon__icon"><?= at_icon('tag', 'w-4 h-4') ?></span>
                    <span class="at-checkout-coupon__text">
                        <span class="at-checkout-coupon__title">Offers &amp; coupons</span>
                        <span class="at-checkout-coupon__sub<?= catalogue_cart_coupon_applied($totals) ? ' is-applied' : '' ?>" data-at-coupon-sub><?= catalogue_cart_coupon_applied($totals) ? Security::escape((string) $totals['coupon_code']) . ' applied · You save ' . Security::escape(catalogue_format_price((float) $totals['coupon_discount'])) : 'Apply a coupon and save on your order' ?></span>
                    </span>
                    <span class="at-checkout-coupon__chev" aria-hidden="true"><?= at_icon('chevron-down', 'w-4 h-4') ?></span>
                </summary>
                <div class="at-checkout-coupon__body">
                    <div data-at-offers-wrap>
                        <?php
                        try {
                            $offers = CouponOfferService::forCurrentCart($totals);
                        } catch (Throwable $e) {
                            error_log('checkout offers: ' . get_class($e));
                            $offers = [];
                        }
                        require APNI_TOKRI_ROOT . '/includes/partials/cart-offers.php';
                        ?>
                    </div>
                    <form id="at-coupon-form" class="mt-3"<?= catalogue_cart_coupon_applied($totals) ? ' data-applied-code="' . Security::escape((string) $totals['coupon_code']) . '"' : '' ?>>
                        <?= Csrf::field() ?>
                        <div class="at-coupon-form__head">
                            <label class="at-label" for="coupon_code"><?= at_icon('tag', 'w-4 h-4 text-[var(--at-green)]') ?><span data-at-coupon-head-label><?= catalogue_cart_coupon_applied($totals) ? 'Coupon applied' : 'Have a coupon?' ?></span></label>
                            <button type="button" id="at-coupon-remove" class="at-btn at-btn--ghost at-focusable<?= catalogue_cart_coupon_applied($totals) ? '' : ' hidden' ?>" data-at-coupon-remove>Remove</button>
                        </div>
                        <details class="at-coupon-another" data-at-coupon-another<?= catalogue_cart_coupon_applied($totals) ? '' : ' open' ?>>
                            <summary class="at-coupon-another__summary text-sm font-semibold text-[var(--at-green)] cursor-pointer at-focusable<?= catalogue_cart_coupon_applied($totals) ? '' : ' hidden' ?>" data-at-coupon-another-summary>Have another coupon?</summary>
                            <div class="at-coupon-form__row mt-2">
                                <input id="coupon_code" name="coupon_code" class="at-input at-focusable flex-1 min-w-[10rem]" placeholder="Enter your coupon code" value="<?= catalogue_cart_coupon_applied($totals) ? '' : Security::escape((string) ($totals['coupon_code'] ?? '')) ?>" autocomplete="off">
                                <button type="submit" id="at-coupon-apply" class="at-btn at-btn--secondary at-focusable" data-at-coupon-apply>Apply</button>
                            </div>
                            <p id="at-coupon-feedback" class="text-sm mt-2 hidden" data-at-coupon-feedback role="alert"></p>
                            <p class="at-field-hint mt-2">Eligibility is validated on the server. Discount appears in your order summary.</p>
                        </details>
                    </form>
                </div>
            </details>
        </div>
        <?php require APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php'; ?>
        <p class="text-xs at-muted flex gap-2"><?= at_icon('shield', 'w-4 h-4 flex-shrink-0') ?>Delivery fee uses your address pincode and eligible cart value — recalculated on the server at payment.</p>
    </aside>
</div>
<?php if ($dbAddressMode): ?>
<script>
(function () {
    var links = document.querySelectorAll('[data-checkout-edit]');
    function syncAddressSelection() {
        var cards = document.querySelectorAll('label.at-addr-card');
        var sel = document.querySelector('input[name="address_id"][form="checkout-form"]:checked');
        for (var i = 0; i < cards.length; i++) {
            var radio = cards[i].querySelector('input[type="radio"][name="address_id"]');
            cards[i].classList.toggle('is-selected', !!(radio && sel && radio === sel));
        }
        if (sel) {
            for (var j = 0; j < links.length; j++) {
                links[j].href = links[j].href.replace(/edit_address=\d+/, 'edit_address=' + sel.value);
            }
        }
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('input[name="address_id"][form="checkout-form"]')) {
            syncAddressSelection();
        }
    });
    window.addEventListener('pageshow', syncAddressSelection);
    syncAddressSelection();
})();
</script>
<?php endif; ?>
<?php if ($dbAddressMode && $loggedIn && DemoCart::items() !== []): ?>
<script>
window.AT_CHECKOUT = {
    quoteUrl: <?= json_encode(url_base() . '/ajax/checkout/quote.php', JSON_THROW_ON_ERROR) ?>,
    csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>
};
</script>
<?php endif; ?>
<?php if (DeliveryConfig::slotsEnabled() && ($canShowDeliverySlots ?? false) && ($checkoutSlots ?? []) !== []): ?>
<script>
(function () {
    function syncDeliveryDateFromSlot() {
        var checked = document.querySelector('input[name="delivery_slot"][form="checkout-form"]:checked');
        var hidden = document.getElementById('checkout-delivery-date');
        if (!hidden) return;
        hidden.value = checked && checked.dataset.deliveryDate ? checked.dataset.deliveryDate : '';
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('input[name="delivery_slot"][form="checkout-form"]')) {
            syncDeliveryDateFromSlot();
        }
    });
    syncDeliveryDateFromSlot();
})();
</script>
<?php endif; ?>
<script>
(function () {
  var form = document.getElementById('checkout-form');
  var btn = document.getElementById('checkout-proceed-pay');
  var terms = form ? form.querySelector('input[name="terms"]') : null;
  function syncPayButton() {
    if (typeof window.AT_CHECKOUT_SYNC_PAY === 'function') {
      window.AT_CHECKOUT_SYNC_PAY();
      return;
    }
    if (!btn || btn.hasAttribute('data-at-checkout-hard-disabled')) return;
    var gatewayOk = btn.getAttribute('data-at-gateway-ready') === '1';
    var quoteOk = btn.getAttribute('data-at-quote-ready') !== '0';
    var termsOk = !terms || terms.checked;
    btn.disabled = !(gatewayOk && quoteOk && termsOk);
  }
  if (btn) {
    btn.setAttribute('data-at-gateway-ready', <?= ($paymentGatewayReady && $loggedIn && $addresses !== [] && $slotsOk) ? "'1'" : "'0'" ?>);
    if (!<?= ($loggedIn && $addresses !== [] && $slotsOk && $paymentGatewayReady) ? 'true' : 'false' ?>) {
      btn.setAttribute('data-at-checkout-hard-disabled', '1');
    }
  }
  if (terms) {
    terms.addEventListener('change', syncPayButton);
    syncPayButton();
  }
  if (!form || !btn || btn.disabled) return;
  form.addEventListener('submit', function () {
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.classList.add('is-loading');
    var label = btn.querySelector('.at-btn__label') || btn;
    if (!btn.dataset.origText) btn.dataset.origText = btn.textContent.trim();
    btn.textContent = 'Starting payment…';
  });
})();
</script>
<?php layout_public_end(); ?>
