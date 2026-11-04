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

if ($action === 'skip') {
    $dayId = isset($_POST['day_id']) ? (int) $_POST['day_id'] : 0;
    if ($dayId <= 0) {
        Ajax::error('Invalid delivery day.');
    }
    $result = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNumber, $dayId);
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not skip delivery.');
    }
    Ajax::success([
        'message' => 'Delivery skipped.',
        'carry_forward_date' => $result['carry_forward_date'] ?? '',
    ]);
}

if ($action === 'pause') {
    $note = isset($_POST['note']) && is_string($_POST['note']) ? trim($_POST['note']) : '';
    $pauseStart = isset($_POST['pause_start_date']) && is_string($_POST['pause_start_date'])
        ? trim($_POST['pause_start_date']) : '';
    $resumeOn = isset($_POST['resume_on_date']) && is_string($_POST['resume_on_date'])
        ? trim($_POST['resume_on_date']) : '';
    if ($pauseStart !== '' && $resumeOn !== '') {
        $result = SubscriptionLifecycleService::pauseWithDates($customerId, $subNumber, $pauseStart, $resumeOn, $note);
    } else {
        $result = SubscriptionLifecycleService::pause($customerId, $subNumber, $note);
    }
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not pause.');
    }
    Ajax::success(['message' => 'Pause scheduled.']);
}

if ($action === 'resume') {
    $result = SubscriptionLifecycleService::resume($customerId, $subNumber);
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not resume.');
    }
    Ajax::success(['message' => 'Subscription resumed.']);
}

Ajax::error('Unknown action.');
