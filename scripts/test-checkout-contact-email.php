<?php

declare(strict_types=1);

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

ok(Validation::email('buyer@example.com'), 'Validation accepts simple email');
ok(!Validation::email('not-an-email'), 'Validation rejects bad email');

SessionCustomerBackend::login('9876543210', 'Checkout Test');
$demo = SessionCustomerBackend::saveContactFromCheckout('  Vijay  Kumar ', '  buyer@example.com  ');
ok(!empty($demo['ok']), 'Demo session saves trimmed name and email');
$prof = SessionCustomerBackend::profile();
ok(($prof['name'] ?? '') === 'Vijay Kumar', 'Demo profile exposes normalised name');
ok(($prof['email'] ?? '') === 'buyer@example.com', 'Demo profile exposes email');
SessionCustomerBackend::saveContactFromCheckout('', 'still@example.com');
SessionCustomerBackend::saveContactFromCheckout('Name Only', '');
$prof2 = SessionCustomerBackend::profile();
ok(($prof2['name'] ?? '') === 'Name Only', 'Demo name update without clearing email');
ok(($prof2['email'] ?? '') === 'still@example.com', 'Empty email submit does not clear demo email');
$bad = SessionCustomerBackend::saveContactFromCheckout('', 'bad');
ok(empty($bad['ok']), 'Demo session rejects invalid email');

$checkoutPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
ok(str_contains($checkoutPhp, 'customer_email') && str_contains($checkoutPhp, 'customer_name'), 'Checkout passes contact name and email');
ok(str_contains($checkoutPhp, 'at-checkout-contact-fields'), 'Checkout contact fields wrapper');

$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/checkout.php');
ok(str_contains($svc, 'persistCheckoutContact'), 'CheckoutService persists contact');
ok(str_contains($svc, 'updateContactFromCheckout'), 'DB path uses merged contact update');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $cid = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($cid > 0) {
        $tag = 'checkout-' . $cid;
        $r = CustomerAccountService::updateContactFromCheckout($cid, 'Checkout User ' . $cid, $tag . '@example.test');
        ok(!empty($r['ok']), 'DB updateContactFromCheckout saves name and email');
        $p = CustomerAccountService::profile($cid);
        ok(trim((string) ($p['name'] ?? '')) !== '', 'DB profile has name after checkout save');
        ok((string) ($p['email'] ?? '') === $tag . '@example.test', 'DB profile has email after checkout save');
        $r2 = CustomerAccountService::updateContactFromCheckout($cid, '', '');
        $p2 = CustomerAccountService::profile($cid);
        ok((string) ($p2['email'] ?? '') === $tag . '@example.test', 'Empty checkout contact does not wipe email');
    } else {
        ok(true, 'DB contact test skipped (no customer row)');
    }
} else {
    ok(true, 'DB contact test skipped (schema not ready)');
}

echo $fail === 0 ? "PASS: checkout contact persistence.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
