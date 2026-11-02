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

    if ($action === 'add_address') {
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

$addresses = CustomerService::addresses();
$loggedIn = CustomerService::isLoggedIn();
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

layout_public_start([
    'pageTitle' => 'Checkout — Apni Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
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

        <section class="at-checkout-block">
            <div class="at-checkout-block__head"><span class="at-checkout-block__num">4</span><h2 class="at-h3">Payment</h2></div>
            <div class="at-option !cursor-default">
                <span class="text-[var(--at-green)] mt-0.5"><?= at_icon('wallet', 'w-5 h-5') ?></span>
                <span class="text-sm"><strong>Pay online</strong><br><span class="at-muted"><?= CommerceGateway::useDatabase() ? 'You will complete payment on the next step. Orders are confirmed only after server verification (Razorpay, Cashfree, or simulate mode).' : 'UPI, cards and netbanking preview — use the demo buttons below.' ?></span></span>
            </div>
            <label class="flex items-start gap-3 mt-4 text-sm">
                <input type="checkbox" form="checkout-form" name="terms" value="1" required class="mt-0.5">
                <span>I agree to the <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=refund-policy">refund</a>, <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=shipping-policy">delivery</a> and <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=subscription-terms">subscription</a> terms.</span>
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
                    <button type="submit" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" <?= ($canPay && $slotsOk) ? '' : 'disabled' ?>><?= at_icon('lock', 'w-5 h-5') ?> Proceed to pay <?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></button>
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
<?php layout_public_end(); ?>
