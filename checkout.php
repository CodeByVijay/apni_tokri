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

    $dbAddressMode = CommerceGateway::useDatabase() && CustomerAuth::check() && CustomerAuth::id() !== null;

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
            'payment_sim' => $_POST['payment_sim'] ?? 'success',
            'terms' => $_POST['terms'] ?? null,
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
$dbAddresses = CommerceGateway::useDatabase() && $loggedIn && CustomerAuth::id() !== null;
$addressRows = $dbAddresses ? CustomerAccountService::listAddresses((int) CustomerAuth::id()) : [];
$addresses = $dbAddresses ? array_map(static function (array $r): array {
    return ['id' => (string) $r['id']] + $r;
}, $addressRows) : CustomerService::addresses();
$addrFormErrors = $addrFormErrors ?? [];
$addrFormOpenMode = $addrFormOpenMode ?? '';
$addrFormOpenId = $addrFormOpenId ?? 0;
$addrProfile = $dbAddresses ? (CustomerAccountService::profile((int) CustomerAuth::id()) ?? []) : [];
$addrFormValues = isset($addrPostValues) ? $addrPostValues : AddressInput::blankFormValues($addrProfile);
if ($dbAddresses && $addrFormOpenMode === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
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
$checkoutSlots = DeliveryConfig::slotsEnabled() ? DeliverySlotService::availableForCheckout() : [];
$defaultSlotSlug = DeliveryConfig::slotsEnabled()
    ? ($checkoutSlots !== [] ? (string) ($checkoutSlots[0]['slug'] ?? '') : null)
    : 'auto';
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
$totals = CartService::totals($defaultAddressId, $defaultSlotSlug);
$paymentGwMeta = PaymentGatewayConfig::publicCheckoutConfig();
$paymentGatewayLabel = PaymentGatewayConfig::gatewayLabel((string) ($paymentGwMeta['gateway'] ?? 'simulate'));
$paymentUsesSimulate = PaymentGatewayConfig::useSimulatedCheckout();
$paymentLiveReady = PaymentGatewayConfig::assertLivePaymentsReady();
$paymentGatewayReady = $paymentUsesSimulate || !empty($paymentLiveReady['ok']);
$paymentGatewayBlockedMessage = $paymentGatewayReady
    ? ''
    : (string) ($paymentLiveReady['message'] ?? 'Payment gateway is not ready. Check Settings → Payment gateways.');

layout_public_start([
    'pageTitle' => 'Checkout — Apni Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
    'loadAddressMap' => $dbAddresses,
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

<div class="at-container py-6 md:py-8 grid lg:grid-cols-[1fr_24rem] gap-6 lg:gap-10 items-start">
    <div class="grid gap-4">
        <?php if ($error !== ''): ?>
            <div class="at-alert at-alert--error" role="alert"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($error) ?></span></div>
        <?php endif; ?>

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">1</span><h2 class="at-h3">Contact</h2></div>
            <?php if ($loggedIn): ?>
                <p class="flex items-center gap-3"><span class="at-avatar"><?= Security::escape(strtoupper(substr((string) (CustomerService::profile()['name'] ?? 'C'), 0, 1))) ?></span>
                    <span><strong><?= Security::escape(CustomerService::profile()['name'] ?? '') ?></strong><br><span class="at-muted text-sm"><?= Security::escape(CustomerService::profile()['mobile'] ?? '') ?></span></span></p>
            <?php else: ?>
                <p class="text-sm at-muted">We verify your mobile number with an OTP before you can place the order.</p>
                <a href="<?= Security::escape(url_base()) ?>/login.php" class="at-btn at-btn--primary at-focusable mt-3">Log in with mobile OTP</a>
            <?php endif; ?>
        </section>

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">2</span><h2 class="at-h3">Delivery address</h2></div>
            <p class="text-xs at-muted -mt-2 mb-3">Serviceability for Indore is checked before payment (Stage 8).<?php if ($loggedIn && CommerceGateway::useDatabase()): ?> <a href="<?= Security::escape(url_base()) ?>/my-addresses.php" class="underline">Manage saved addresses</a><?php endif; ?></p>
            <?php if ($loggedIn && $addresses === []): ?>
                <p class="text-sm at-muted">Add a delivery address below to continue.</p>
            <?php endif; ?>
            <?php if ($dbAddresses): require_once APNI_TOKRI_ROOT . '/includes/partials/address-summary.php'; ?>
                <?php if ($addresses !== []): ?>
                    <div class="grid gap-3" role="radiogroup" aria-label="Saved delivery addresses">
                        <?php foreach ($addressRows as $addr): $isSel = (string) $addr['id'] === $selectedAddressId; $pinOk = AddressInput::hasPin($addr); ?>
                            <label class="at-addr-card<?= $isSel ? ' is-selected' : '' ?>">
                                <input type="radio" name="address_id" value="<?= (int) $addr['id'] ?>" class="mt-1" form="checkout-form" <?= $isSel ? 'checked' : '' ?>>
                                <span class="at-addr-card__body">
                                    <?= at_address_summary_html($addr) ?>
                                    <?php if (!empty($addr['delivery_instructions'])): ?><span class="at-addr-card__meta block text-xs">Instructions: <?= Security::escape((string) $addr['delivery_instructions']) ?></span><?php endif; ?>
                                    <?php if ($pinOk): ?>
                                        <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable at-addr-card__view-loc mt-2" data-at-view-location
                                            data-lat="<?= Security::escape((string) $addr['latitude']) ?>" data-lng="<?= Security::escape((string) $addr['longitude']) ?>"
                                            data-title="<?= Security::escape((string) $addr['label']) ?>">View location</button>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="at-addr-card__actions">
                        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" data-checkout-edit href="<?= Security::escape(url_base()) ?>/checkout.php?edit_address=<?= (int) $selectedAddressId ?>#checkout-address-form">Edit selected address</a>
                        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" data-checkout-edit href="<?= Security::escape(url_base()) ?>/checkout.php?edit_address=<?= (int) $selectedAddressId ?>#checkout-address-form">Change delivery location</a>
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
                            require APNI_TOKRI_ROOT . '/includes/partials/address-form.php';
                            ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php else: ?>
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
            <?php if (DeliveryConfig::slotsEnabled()):
                ?>
                <p class="text-sm at-muted -mt-2 mb-3">Capacity is re-checked when you pay (<?= Security::escape(DeliveryConfig::timezone()) ?>).</p>
                <?php if ($checkoutSlots === []): ?>
                    <p class="at-alert at-alert--warning text-sm">No slots available right now. Try again later or contact support.</p>
                <?php else: ?>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <?php foreach ($checkoutSlots as $i => $s): ?>
                            <label class="at-option">
                                <input type="radio" name="delivery_slot" form="checkout-form" value="<?= Security::escape($s['slug']) ?>" class="mt-1" <?= $i === 0 ? 'checked' : '' ?>>
                                <span class="text-sm"><strong class="block"><?= Security::escape($s['label']) ?></strong><?= Security::escape($s['window']) ?><br><span class="at-muted text-xs"><?= (int) $s['remaining'] ?> spots left</span></span>
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
                        <span class="at-checkout-pay__value at-checkout-pay__amount"><?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></span>
                    </div>
                    <p class="at-checkout-pay__hint text-sm at-muted">You will pay on the next screen. Your order is confirmed only after the server verifies payment with <?= Security::escape($paymentGatewayLabel) ?>.</p>
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
            <label class="flex items-start gap-3 mt-4 text-sm">
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
            <?php if (!$loggedIn): ?><p class="at-alert at-alert--warning mb-3 text-sm"><?= at_icon('lock', 'w-5 h-5 flex-shrink-0') ?><span>Log in with your mobile number to enable payment.</span></p><?php endif; ?>
            <?php
            $canPay = $loggedIn && $addresses !== [];
            $slotsOk = !DeliveryConfig::slotsEnabled() || DeliverySlotService::availableForCheckout() !== [];
            ?>
            <div class="flex flex-col sm:flex-row gap-3">
                <?php if (CommerceGateway::useDatabase()): ?>
                    <button type="submit" id="checkout-proceed-pay" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" data-at-checkout-submit <?= ($canPay && $slotsOk && $paymentGatewayReady) ? '' : 'disabled' ?>><?= at_icon('lock', 'w-5 h-5') ?> Proceed to pay <?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></button>
                <?php else: ?>
                    <button type="submit" name="payment_sim" value="success" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" <?= $canPay ? '' : 'disabled' ?>><?= at_icon('lock', 'w-5 h-5') ?> Pay <?= Security::escape(catalogue_format_price((float) $totals['total'])) ?> (demo success)</button>
                    <button type="submit" name="payment_sim" value="fail" class="at-btn at-btn--secondary at-btn--lg at-focusable" <?= $canPay ? '' : 'disabled' ?>>Simulate payment failure</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <aside class="at-summary grid gap-4">
        <div class="at-card"><div class="at-card__body">
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
        <?php require APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php'; ?>
        <p class="text-xs at-muted flex gap-2"><?= at_icon('shield', 'w-4 h-4 flex-shrink-0') ?>Delivery fee uses your address pincode and eligible cart value — recalculated on the server at payment.</p>
    </aside>
</div>
<?php if ($dbAddresses): ?>
<script>
(function () {
    // Keep "Edit selected address" / "Change delivery location" pointed at the checked card.
    var radios = document.querySelectorAll('input[name="address_id"][form="checkout-form"]');
    var links = document.querySelectorAll('[data-checkout-edit]');
    function sync() {
        var sel = document.querySelector('input[name="address_id"][form="checkout-form"]:checked');
        if (!sel) { return; }
        for (var i = 0; i < links.length; i++) {
            links[i].href = links[i].href.replace(/edit_address=\d+/, 'edit_address=' + sel.value);
        }
    }
    for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', sync); }
    sync();
})();
</script>
<?php endif; ?>
<script>
(function () {
  var form = document.getElementById('checkout-form');
  var btn = document.getElementById('checkout-proceed-pay');
  var terms = form ? form.querySelector('input[name="terms"]') : null;
  function syncPayButton() {
    if (!btn || btn.hasAttribute('data-at-checkout-hard-disabled')) return;
    var gatewayOk = btn.getAttribute('data-at-gateway-ready') === '1';
    var termsOk = !terms || terms.checked;
    btn.disabled = !(gatewayOk && termsOk);
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
