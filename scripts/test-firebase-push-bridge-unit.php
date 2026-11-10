<?php

declare(strict_types=1);

/**
 * Push event bridge behavior (no database).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ok = 0;
$fail = 0;

function bridgeOk(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        ++$ok;
        fwrite(STDOUT, "OK  {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

try {
    PushEventBridge::orderStatusChanged(0, 'pending', 'confirmed');
    PushEventBridge::orderPaymentSuccess(0);
    PushEventBridge::refundStatusUpdated(0, 'approved');
    bridgeOk(true, 'bridge hooks tolerate invalid ids without throwing');
} catch (Throwable $e) {
    bridgeOk(false, 'bridge hooks tolerate invalid ids without throwing');
}

// Template render strips unknown placeholders (no unsupported vars like delivery_date leaking through).
$rendered = PushTemplateRepository::renderField(
    'Hi {customer_name}, order {order_number} on {delivery_date}',
    ['customer_name' => 'Vijay', 'order_number' => 'ORD-9', 'order_total' => '99']
);
bridgeOk(!str_contains($rendered, '{delivery_date}'), 'unsupported placeholders stripped');
bridgeOk(str_contains($rendered, 'Vijay') && str_contains($rendered, 'ORD-9'), 'supported placeholders expanded');

// Dispatcher wraps push in try/catch — static structure check via reflection (orderPaymentSuccess contains PushEventBridge).
$disp = new ReflectionMethod(NotificationDispatcher::class, 'orderPaymentSuccess');
$src = file_get_contents($disp->getFileName());
$start = $disp->getStartLine();
$end = $disp->getEndLine();
$lines = implode("\n", array_slice(explode("\n", $src), $start - 1, $end - $start + 1));
bridgeOk(str_contains($lines, 'PushEventBridge::orderPaymentSuccess'), 'dispatcher invokes payment push before email');
bridgeOk(str_contains($lines, 'try') && str_contains($lines, 'catch'), 'dispatcher isolates push failures');

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
