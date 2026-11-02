<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}

Csrf::requireValid();

if (!CustomerService::isLoggedIn()) {
    Ajax::error('Please log in.', 401);
}

$customerId = CustomerAuth::id();
if ($customerId === null) {
    Ajax::error('Please log in.', 401);
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
$subNumber = isset($_POST['subscription_number']) && is_string($_POST['subscription_number'])
    ? trim($_POST['subscription_number']) : '';

if ($subNumber === '') {
    Ajax::error('Invalid request.');
}

if ($action === 'accept') {
    $dayId = isset($_POST['day_id']) ? (int) $_POST['day_id'] : 0;
    if ($dayId <= 0) {
        Ajax::error('Invalid delivery day.');
    }
    $result = SubscriptionBasketService::acceptSuggested($customerId, $subNumber, $dayId);
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not accept basket.');
    }
    Ajax::success(['message' => 'Basket confirmed.']);
}

if ($action === 'save') {
    $confirm = !empty($_POST['confirm']);
    $daysRaw = $_POST['days'] ?? null;
    if (!is_array($daysRaw)) {
        Ajax::error('No delivery days submitted.');
    }
    $parsed = [];
    foreach ($daysRaw as $dayId => $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $lines = [];
        if (isset($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lines[] = [
                    'variant_id' => (int) ($line['variant_id'] ?? 0),
                    'qty' => (float) ($line['qty'] ?? 0),
                ];
            }
        }
        $parsed[(int) $dayId] = ['lines' => $lines];
    }
    if ($parsed === []) {
        Ajax::error('No valid days in request.');
    }
    $result = SubscriptionBasketService::saveCustomerDays($customerId, $subNumber, $parsed, $confirm);
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not save basket.');
    }
    Ajax::success(['message' => $confirm ? 'Baskets confirmed.' : 'Draft saved.']);
}

Ajax::error('Unknown action.');
