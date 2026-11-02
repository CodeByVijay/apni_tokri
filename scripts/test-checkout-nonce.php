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

$_SESSION = [];
$formNonce = CheckoutService::freshNonce();
ok($formNonce === (string) ($_SESSION['checkout_nonce'] ?? ''), 'GET issues nonce stored in session');

// checkout.php must not call freshNonce() again before place_order validation on POST.
$sessionNonce = (string) ($_SESSION['checkout_nonce'] ?? '');
ok(Security::hashEquals($sessionNonce, $formNonce), 'POST place_order: form nonce matches session (fixed flow)');

CheckoutService::freshNonce();
ok(!Security::hashEquals((string) ($_SESSION['checkout_nonce'] ?? ''), $formNonce), 'Rotating session nonce invalidates stale form (expected)');

echo $fail === 0 ? "PASS: checkout nonce handling.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
