<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        $fail++;
    } else {
        echo "PASS: $msg\n";
    }
};

$payment = (string) file_get_contents(APNI_TOKRI_ROOT . '/payment.php');
$service = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/service.php');
$verify = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/payment/verify.php');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/apni-tokri.js');

$ok(str_contains($service, 'storefrontFulfilmentReconciliationContext'), 'reconciliation context helper');
$ok(str_contains($service, 'clearStorefrontCheckoutSession'), 'clears stale checkout on reconcile');
$ok(str_contains($payment, 'fulfilmentReconciliation'), 'payment page reconciliation mode');
$ok(str_contains($payment, 'at-payment-reconciliation-check'), 'check status data hook');
$ok(str_contains($payment, 'Payment received — order confirmation pending'), 'customer pending copy');
$ok(str_contains($payment, 'Check payment status'), 'reconciliation check status action');
$ok(str_contains($payment, 'showGatewayCheckout'), 'gateway checkout gated off reconciliation');
$ok(str_contains($verify, "'reconciliation'"), 'verify returns reconciliation pending');
$ok(str_contains($js, 'at-payment-reconciliation-check'), 'refresh uses reconciliation check node');
$ok(str_contains($js, 'fulfilmentReconciliation'), 'blocks autolaunch during reconciliation');
$ok(str_contains($service, 'storefrontFulfilmentReconciliationContext($orderId)'), 'retry blocked when reconciliation open');
$recovery = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/payment/fulfilment_reconciliation.php');
$adminView = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/orders/view.php');
$ok(str_contains($recovery, 'adminReassignSlotAndCompleteFulfilment'), 'admin slot recovery service');
$ok(str_contains($adminView, 'recover_payment_fulfilment'), 'admin order recovery form');
$ok(str_contains($adminView, 'recover_payment_fulfilment_custom'), 'admin custom delivery recovery form');
$ok(str_contains($adminView, 'custom_customer_confirmed'), 'admin custom customer confirmation field');
$ok(str_contains($recovery, 'adminCustomDeliveryAndCompleteFulfilment'), 'admin custom delivery recovery service');
$ok(str_contains($service, 'paymentAttemptStatusLabel'), 'payment captured label helper');

exit($fail > 0 ? 1 : 0);
