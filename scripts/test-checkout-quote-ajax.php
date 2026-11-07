<?php

declare(strict_types=1);

/**
 * Checkout delivery quote refresh — server totals follow address + slot.
 * Run: php scripts/run-in-disposable-db.php scripts/test-checkout-quote-ajax.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$root = dirname(__DIR__);
$quotePhp = (string) file_get_contents($root . '/ajax/checkout/quote.php');
$checkoutPhp = (string) file_get_contents($root . '/checkout.php');
$cartJs = (string) file_get_contents($root . '/assets/js/cart.js');

ok(str_contains($quotePhp, 'CartService::totals($addressId'), 'Quote endpoint uses CartService totals with address id');
ok(str_contains($quotePhp, 'CustomerService::address'), 'Quote endpoint validates customer ownership');
ok(str_contains($quotePhp, 'DeliveryQuoteService') === false, 'Quote endpoint reuses PricingService quote path via CartService');
ok(str_contains($quotePhp, 'delivery_quote_accurate'), 'Quote endpoint computes pay eligibility from accurate quote');
ok(str_contains($checkoutPhp, 'AT_CHECKOUT'), 'Checkout exposes quote AJAX config');
ok(str_contains($checkoutPhp, 'data-at-quote-ready'), 'Proceed to pay gated on quote readiness');
ok(str_contains($cartJs, 'initCheckoutQuote'), 'Checkout quote listener registered');
ok(str_contains($cartJs, 'quoteSeq'), 'Stale quote responses ignored via sequence');
ok(!str_contains($checkoutPhp, 'at-addr-card<?= $isSel ? \' is-selected\''), 'Checkout address cards do not bake static is-selected');
ok(str_contains($checkoutPhp, 'syncAddressSelection'), 'Checkout syncs address card selection styling');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $cust = $pdo->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cust)) {
        $cid = (int) $cust['id'];
        $stmt = $pdo->prepare('SELECT id, pincode, latitude, longitude FROM customer_addresses WHERE customer_id = ? ORDER BY id ASC LIMIT 2');
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) >= 2) {
            $slot = DeliveryConfig::slotsEnabled() ? null : 'auto';
            if (DeliveryConfig::slotsEnabled()) {
                $slots = DeliverySlotService::availableForCheckout();
                $slot = $slots !== [] ? (string) ($slots[0]['slug'] ?? '') : null;
            }
            if ($slot !== null && $slot !== '') {
                $t1 = CartService::totals((int) $rows[0]['id'], $slot);
                $t2 = CartService::totals((int) $rows[1]['id'], $slot);
                ok(is_array($t1) && is_array($t2), 'Totals callable for two addresses');
                ok(
                    ($t1['delivery_fee'] ?? null) !== null && ($t2['delivery_fee'] ?? null) !== null,
                    'Both addresses return delivery_fee key'
                );
            }
        } else {
            ok(true, 'Skip two-address fee compare (need 2 saved addresses in DB)');
        }
    }
} else {
    ok(true, 'Skip DB quote integration (schema not ready)');
}

echo $fail === 0 ? "PASS: checkout quote ajax.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
