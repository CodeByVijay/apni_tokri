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

if ($action === 'set_day_slot') {
    $dayId = isset($_POST['day_id']) ? (int) $_POST['day_id'] : 0;
    $slug = isset($_POST['delivery_slot']) && is_string($_POST['delivery_slot']) ? trim($_POST['delivery_slot']) : '';
    if ($dayId <= 0 || $slug === '') {
        Ajax::error('Invalid request.');
    }
    $result = SubscriptionSlotService::applyPreferredToDay($dayId, $slug, $customerId);
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not save slot.');
    }
    Ajax::success(['message' => 'Slot saved.']);
}

Ajax::error('Unknown action.');
