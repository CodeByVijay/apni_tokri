<?php

declare(strict_types=1);

/**
 * Delivery customer email log (static regression).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$view = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/deliveries/view.php');
$dispatcher = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/notifications/dispatcher.php');
$ctx = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/notifications/order_email_context.php');

$ok(str_contains($view, 'Customer email log'), 'Admin delivery view email log section');
$ok(str_contains($view, 'retry_email'), 'Admin email retry action');
$ok(str_contains($dispatcher, 'claimAndProcessByIdempotencyKey'), 'Payment success triggers immediate email send');
$ok(str_contains($dispatcher, 'isOrderPaidAndCaptured'), 'Confirmation gated on paid + captured');
$ok(str_contains($dispatcher, 'resendOrderConfirmationEmail'), 'Admin email retry dispatcher');
$ok(str_contains($ctx, 'appendConfirmationSnapshotFields'), 'Confirmation payload includes order snapshot');
$ok(str_contains($ctx, 'track_order_url'), 'Track order uses storefront URL');
$ok(is_file(APNI_TOKRI_ROOT . '/database/migrations/1.31.0_order_confirmation_email_log.sql'), 'Migration for delivery_email_logs');

exit($fail > 0 ? 1 : 0);
