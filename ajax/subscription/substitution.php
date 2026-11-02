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
$substitutionId = isset($_POST['substitution_id']) ? (int) $_POST['substitution_id'] : 0;

if ($substitutionId <= 0) {
    Ajax::error('Invalid substitution.');
}

if ($action === 'approve' || $action === 'reject') {
    $resolution = isset($_POST['reject_resolution']) && is_string($_POST['reject_resolution'])
        ? trim($_POST['reject_resolution']) : '';
    $result = SubscriptionSubstitutionService::customerDecision(
        $substitutionId,
        $customerId,
        $action === 'approve' ? 'approve' : 'reject',
        $resolution
    );
    if (!$result['ok']) {
        Ajax::error($result['message'] ?? 'Could not save decision.');
    }
    if (!empty($result['requires_payment'])) {
        Ajax::success([
            'requires_payment' => true,
            'checkout' => $result['checkout'] ?? [],
        ]);
    }
    Ajax::success(['message' => 'Saved.']);
}

Ajax::error('Unknown action.');
