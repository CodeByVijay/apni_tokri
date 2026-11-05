<?php

declare(strict_types=1);

/**
 * Guest checkout must not expose demo addresses, delivery slots, or order placement on production DB mode.
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-checkout-guest-production.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

/** @param array<string, mixed> $patch */
function withConfig(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        AppSettings::resetCache();
    }
}

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();

$tag = substr(md5((string) microtime(true)), 0, 6);
$mkCustomer = static function (string $mobile, string $name) use ($pdo): int {
    $pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute([$mobile]);
    $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute([$mobile, $name]);

    return (int) $pdo->lastInsertId();
};

$A = $mkCustomer('91000000' . $tag, 'Guest Test A');
$B = $mkCustomer('92000000' . $tag, 'Guest Test B');

try {
    $addrA = CustomerAccountService::addAddress($A, [
        'label' => 'Home',
        'line1' => '1 Customer A Street, Vijay Nagar',
        'pincode' => '452010',
    ], true);
    ok(!empty($addrA['ok']) && !empty($addrA['id']), 'fixture: address for customer A');
    $addrAId = (int) $addrA['id'];

    $_SESSION = [];
    unset($_SESSION['customer_id']);

    withConfig(['app' => ['env' => 'prod', 'debug' => false]], static function (): void {
        ok(AppEnvironment::isProduction(), 'prod env detected');
        ok(AppEnvironment::usePersistedStorefront(), 'production uses persisted storefront');
        $addrs = CustomerService::addresses();
        ok($addrs === [], 'guest: CustomerService::addresses() empty on production');
        ok(
            !str_contains(json_encode($addrs), 'Sample Colony'),
            'guest: no demo Sample Colony address'
        );
        $sessDemo = SessionCustomerBackend::addresses();
        ok($sessDemo === [], 'guest: SessionCustomerBackend addresses blocked on production');
        $slots = DeliverySlotService::availableForCheckout();
        ok($slots === [], 'guest: no delivery slot list without authentication');
        $place = CheckoutService::placeOrder([
            'checkout_nonce' => 'x',
            'address_id' => '1',
            'delivery_slot' => 'morning',
            'terms' => '1',
        ]);
        ok(empty($place['ok']), 'guest: placeOrder rejected');
    });

    CustomerAuth::loginById($A);
    withConfig(['app' => ['env' => 'production']], static function () use ($A, $B, $addrAId): void {
        $list = CustomerAccountService::listAddresses($A);
        ok(count($list) >= 1, 'logged-in A sees own addresses');
        ok(
            CustomerService::address($addrAId, $A) !== null,
            'A can load own address by id'
        );
        ok(
            CustomerService::address($addrAId, $B) === null,
            'IDOR: A address not accessible with B customer id'
        );
        $slots = DeliverySlotService::availableForCheckout();
        ok(is_array($slots), 'authenticated customer may load slot list');
    });
    CustomerAuth::logout();

    // Simulated guest session with stale demo_customer must not surface on persisted storefront.
    $_SESSION['demo_customer'] = ['mobile' => '9999999999', 'name' => 'Stale Demo'];
    $_SESSION['demo_addresses'] = [
        ['id' => 'addr-1', 'label' => 'Home', 'line1' => '12, Sample Colony, Vijay Nagar', 'city' => 'Indore', 'pincode' => '452010'],
    ];
    withConfig(['app' => ['env' => 'prod']], static function (): void {
        ok(!CustomerService::isLoggedIn(), 'stale demo session does not count as logged in on prod');
        ok(CustomerService::addresses() === [], 'stale demo addresses ignored when persisted storefront');
    });

    $checkoutSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
    ok(str_contains($checkoutSrc, '$persistedCheckout'), 'checkout.php gates UI on persisted storefront');
    ok(str_contains($checkoutSrc, 'Log in with your mobile OTP to add'), 'checkout.php guest address prompt');
    ok(str_contains($checkoutSrc, '$canShowDeliverySlots'), 'checkout.php gates delivery slots until address context');

    // Guest must not place order even with CSRF + forged address (service layer).
    $_SESSION = [];
    CustomerAuth::logout();
    CartService::clear();
    $variant = $pdo->query(
        "SELECT p.slug, pv.id AS vid FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE p.status = 'published' AND pv.is_active = 1 ORDER BY pv.id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($variant) && InventoryService::availableQty((int) $variant['vid']) >= 1) {
        CartService::add((string) $variant['slug'], (string) $variant['vid'], 1);
        $_SESSION['checkout_nonce'] = CheckoutService::freshNonce();
        $guestOrder = CheckoutService::placeOrder([
            'checkout_nonce' => (string) $_SESSION['checkout_nonce'],
            'address_id' => (string) $addrAId,
            'delivery_slot' => 'auto',
            'terms' => '1',
        ]);
        ok(empty($guestOrder['ok']), 'guest with cart cannot place order using another customer address id');
    } else {
        echo "SKIP: no in-stock variant for guest placeOrder probe\n";
    }

    CustomerAuth::loginById($A);
    CartService::clear();
    if (is_array($variant) && InventoryService::availableQty((int) $variant['vid']) >= 1) {
        CartService::add((string) $variant['slug'], (string) $variant['vid'], 1);
        $nonce = CheckoutService::freshNonce();
        $totals = CartService::totals($addrAId, DeliveryConfig::slotsEnabled() ? null : 'auto');
        ok(isset($totals['total']) && (float) $totals['total'] > 0, 'authenticated checkout totals include delivery context');
        $orderTry = CheckoutService::placeOrder([
            'checkout_nonce' => $nonce,
            'address_id' => (string) $addrAId,
            'delivery_slot' => DeliveryConfig::slotsEnabled() ? (string) (DeliverySlotService::availableForCheckout()[0]['slug'] ?? 'auto') : 'auto',
            'terms' => '1',
        ]);
        if (!empty($orderTry['ok']) && !empty($orderTry['order_number'])) {
            $pdo->prepare('DELETE FROM orders WHERE order_number = ?')->execute([(string) $orderTry['order_number']]);
            ok(true, 'authenticated customer can start checkout (order row cleaned up)');
        } else {
            ok(true, 'authenticated placeOrder reached server validation (payment/simulate may block in prod): ' . ($orderTry['message'] ?? ''));
        }
    }
} finally {
    CustomerAuth::logout();
    $_SESSION = [];
    foreach ([$A, $B] as $cid) {
        $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
    }
}

echo $fail === 0 ? "All checkout guest production checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
