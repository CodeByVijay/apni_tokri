<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

if (!CustomerAuth::check() || CustomerAuth::id() === null) {
    Ajax::error('Please log in to continue.', 401);
}
if (CartService::items() === []) {
    Ajax::error('Your cart is empty.');
}

$addressId = isset($_POST['address_id']) && is_string($_POST['address_id']) && ctype_digit($_POST['address_id'])
    ? (int) $_POST['address_id']
    : 0;
if ($addressId < 1) {
    Ajax::error('Select a delivery address.');
}

$customerId = (int) CustomerAuth::id();
$address = CustomerService::address($addressId, $customerId);
if ($address === null) {
    Ajax::error('Select a valid delivery address.');
}

$slot = isset($_POST['delivery_slot']) && is_string($_POST['delivery_slot']) ? trim($_POST['delivery_slot']) : '';
if (!DeliveryConfig::slotsEnabled()) {
    $slot = $slot === '' ? 'auto' : $slot;
} elseif ($slot === '') {
    Ajax::error('Choose a delivery slot.');
} elseif (!DeliveryService::validateSlot($slot)) {
    Ajax::error('Choose a valid delivery slot.');
}

$totals = CartService::totals($addressId, $slot);
if (!empty($totals['coupon_error'])) {
    Ajax::error((string) $totals['coupon_error']);
}

$canPay = !empty($totals['delivery_quote_accurate'])
    && ($totals['delivery_serviceable'] ?? false)
    && trim((string) ($totals['delivery_quote_message'] ?? '')) === '';

$payload = CouponOfferService::cartAjaxPayload($totals);
$payload['checkout'] = [
    'can_pay' => $canPay,
    'address_id' => $addressId,
    'delivery_slot' => $slot,
    'quote_message' => trim((string) ($totals['delivery_quote_message'] ?? '')),
];

Ajax::success($payload);
