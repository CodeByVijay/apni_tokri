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
$demo = SessionCustomerBackend::saveEmailOptional('  buyer@example.com  ');
ok(!empty($demo['ok']), 'Demo session saves trimmed email');
ok((SessionCustomerBackend::profile()['email'] ?? '') === 'buyer@example.com', 'Demo profile exposes email');
$clear = SessionCustomerBackend::saveEmailOptional('');
ok(!empty($clear['ok']), 'Demo session allows clearing email');
$bad = SessionCustomerBackend::saveEmailOptional('bad');
ok(empty($bad['ok']), 'Demo session rejects invalid email');

$checkoutPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/checkout.php');
ok(str_contains($checkoutPhp, 'customer_email'), 'Checkout form passes customer_email');
ok(str_contains($checkoutPhp, 'Email address'), 'Checkout contact label');
ok(str_contains($checkoutPhp, 'For order confirmation and invoice updates.'), 'Checkout helper text');

$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/checkout.php');
ok(str_contains($svc, 'persistCheckoutContactEmail'), 'CheckoutService persists contact email');
ok(str_contains($svc, 'updateEmailOptional'), 'DB path uses CustomerAccountService');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $cid = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($cid > 0) {
        $r = CustomerAccountService::updateEmailOptional($cid, 'checkout-test-' . $cid . '@example.test');
        ok(!empty($r['ok']), 'DB updateEmailOptional accepts new email');
        $r2 = CustomerAccountService::updateEmailOptional($cid, '');
        ok(!empty($r2['ok']), 'DB updateEmailOptional allows blank email');
    } else {
        ok(true, 'DB email test skipped (no customer row)');
    }
} else {
    ok(true, 'DB email test skipped (schema not ready)');
}

echo $fail === 0 ? "PASS: checkout contact email.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
