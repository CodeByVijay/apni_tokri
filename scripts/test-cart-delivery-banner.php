<?php

declare(strict_types=1);

/**
 * Cart delivery banner never claims free delivery without an accurate server quote.
 * CLI: php scripts/test-cart-delivery-banner.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function t(bool $ok, string $label): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

$guestLow = CartDeliveryBanner::fromTotals([
    'subtotal' => 40,
    'delivery_quote_accurate' => false,
    'amount_to_free_delivery' => 0,
    'delivery_fee' => 0,
    'free_delivery_eligible' => false,
]);
t(
    !str_contains($guestLow['message'], 'unlocked free delivery'),
    'guest without address does not show unlocked free delivery'
);
t(
    $guestLow['message'] === CartDeliveryBanner::MSG_SELECT_ADDRESS,
    'guest sees select address message'
);

$unlocked = CartDeliveryBanner::fromTotals([
    'delivery_quote_accurate' => true,
    'delivery_serviceable' => true,
    'delivery_fee' => 0,
    'free_delivery_eligible' => true,
    'amount_to_free_delivery' => 0,
]);
t(str_contains($unlocked['message'], 'unlocked free delivery'), 'accurate quote with free fee shows unlocked');

$progress = CartDeliveryBanner::fromTotals([
    'delivery_quote_accurate' => true,
    'delivery_serviceable' => true,
    'delivery_fee' => 40,
    'free_delivery_eligible' => true,
    'amount_to_free_delivery' => 459,
    'free_delivery_threshold' => 499,
]);
t(str_contains($progress['message'], 'Add'), 'shows add-more when below threshold');
t($progress['show_progress'] === true, 'shows progress bar');

$bad = CartDeliveryBanner::fromTotals([
    'delivery_quote_accurate' => true,
    'delivery_serviceable' => false,
    'delivery_fee' => 0,
    'amount_to_free_delivery' => 0,
]);
t(!str_contains($bad['message'], 'unlocked'), 'unserviceable never shows unlocked');

echo "Cart delivery banner tests passed.\n";
