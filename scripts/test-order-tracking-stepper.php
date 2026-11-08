<?php

declare(strict_types=1);

/** Renders the order stepper partial for several status scenarios (no DB, no network). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okS(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function renderStepper(array $timeline, string $orderStatus): string
{
    ob_start();
    require dirname(__DIR__) . '/includes/partials/order-stepper.php';

    return (string) ob_get_clean();
}

$t = '2026-11-05 10:00:00';
$full = [
    ['status' => 'Order placed', 'at' => $t, 'done' => true],
    ['status' => 'Payment received', 'at' => $t, 'done' => true],
    ['status' => 'Order confirmed', 'at' => $t, 'done' => true],
    ['status' => 'Preparing', 'at' => $t, 'done' => true],
    ['status' => 'Ready', 'at' => '', 'done' => false],
    ['status' => 'Out for delivery', 'at' => '', 'done' => false],
    ['status' => 'Delivered', 'at' => '', 'done' => false],
];

$h = renderStepper($full, 'preparing');
okS(substr_count($h, 'at-stepper__step ') === 7, 'all 7 steps rendered');
okS(substr_count($h, 'is-done') === 4, 'four completed steps');
okS(substr_count($h, 'is-current') === 1 && strpos($h, 'aria-current="step"') !== false, 'one current step');
okS(substr_count($h, 'is-upcoming') === 2, 'two upcoming steps');
okS(strpos($h, 'Step 5 of 7') !== false, 'progress count');
okS(strpos($h, 'width: 57%') !== false, 'progress bar reflects 4/7');
okS(strpos($h, 'is-bad') === false, 'no failure styling on healthy order');
okS(strpos($h, 'at-stepper__scroll') !== false, 'tracker has own scroll container');
okS(strpos($h, 'at-stepper__content') !== false, 'step content wrapper for layout');
okS(strpos($h, 'data-at-order-tracking-toggle') !== false && strpos($h, 'aria-controls') !== false, 'mobile tracking toggle a11y');
okS(strpos($h, 'at-stepper__collapse-bar') !== false, 'mobile collapse summary bar');
okS(preg_match('/\d{1,2} Nov 2026, \d{1,2}:\d{2} (AM|PM)/', $h) === 1, 'milestones use 12-hour IST-style timestamps');

$delivered = $full;
foreach ($delivered as &$s) {
    $s['done'] = true;
    $s['at'] = $t;
}
unset($s);
$h = renderStepper($delivered, 'delivered');
okS(strpos($h, 'is-bad') === false && substr_count($h, 'is-done') >= 7, 'delivered order is fully green');
okS(strpos($h, 'Step 7 of 7') !== false, 'delivered count 7 of 7');

$cancelled = [
    ['status' => 'Order placed', 'at' => $t, 'done' => true],
    ['status' => 'Order confirmed', 'at' => $t, 'done' => true],
    ['status' => 'Cancelled', 'at' => $t, 'done' => true],
];
$h = renderStepper($cancelled, 'cancelled');
okS(substr_count($h, 'at-stepper__step is-bad') === 1, 'cancelled step is danger styled');
okS(strpos($h, 'Delivered') === false, 'cancelled order shows no delivery progress');
okS(strpos($h, 'at-stepper--cancelled') !== false, 'cancelled order uses vertical cancelled modifier');

$failed = [
    ['status' => 'Order placed', 'at' => $t, 'done' => true],
    ['status' => 'Payment failed', 'at' => $t, 'done' => true],
];
$h = renderStepper($failed, 'payment_failed');
okS(strpos($h, 'at-stepper__step is-bad') !== false, 'payment failed is danger styled');

$pending = [
    ['status' => 'Order placed', 'at' => $t, 'done' => true],
    ['status' => 'Payment pending', 'at' => '', 'done' => false],
];
$h = renderStepper($pending, 'pending_payment');
okS(strpos($h, 'at-stepper__step is-current') !== false && strpos($h, 'is-bad') === false, 'pending payment is current, not success/failure');

$long = [['status' => '<b>Very long status label that wraps ' . str_repeat('word ', 20) . '</b>', 'at' => $t, 'done' => true]];
$h = renderStepper($long, 'confirmed');
okS(strpos($h, '<b>') === false && strpos($h, '&lt;b&gt;') !== false, 'labels are escaped');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/apni-tokri.css');
okS(strpos($css, '.at-stepper__scroll') !== false && strpos($css, 'overflow-x: auto') !== false, 'css has scoped horizontal scroll');
okS(strpos($css, '@media (max-width: 767px)') !== false, 'css has mobile vertical timeline rules');

exit($fail > 0 ? 1 : 0);
