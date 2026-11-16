<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

// Two selection modes:
//  - saved_address_id: pick an existing saved address (logged-in customers only, ownership-checked)
//  - structured form fields: guest/manual/map selection validated server-side
$savedRaw = isset($_POST['saved_address_id']) && is_string($_POST['saved_address_id']) ? trim($_POST['saved_address_id']) : '';

if ($savedRaw !== '') {
    if (!CustomerService::isLoggedIn()) {
        Ajax::error('Please log in to use a saved address.', 403, 'auth_required');
    }
    if (ctype_digit($savedRaw)) {
        $row = CustomerService::address((int) $savedRaw);
        if ($row === null) {
            Ajax::error('That saved address is no longer available.', 404, 'not_found');
        }
        $snapshot = DeliverySessionAddress::snapshotFromSavedRow($row);
    } else {
        // Session-demo backend uses string ids (addr-1, addr-2, ...); returns [] when demo is off.
        $demo = null;
        foreach (SessionCustomerBackend::addresses() as $a) {
            if ((string) ($a['id'] ?? '') === $savedRaw) {
                $demo = $a;
                break;
            }
        }
        if ($demo === null) {
            Ajax::error('That saved address is no longer available.', 404, 'not_found');
        }
        $snapshot = DeliverySessionAddress::snapshotFromSavedRow([
            'label' => (string) ($demo['label'] ?? 'Home'),
            'street' => (string) ($demo['line1'] ?? ''),
            'line1' => (string) ($demo['line1'] ?? ''),
            'area' => (string) ($demo['city'] ?? ''),
            'city' => (string) ($demo['city'] ?? ''),
            'pincode' => (string) ($demo['pincode'] ?? ''),
        ]);
    }
} else {
    $result = DeliverySessionAddress::validate($_POST);
    if (!empty($result['errors'])) {
        $first = (string) reset($result['errors']);
        Ajax::error($first !== '' ? $first : 'Please review the highlighted fields.', 422, 'validation', $result['errors']);
    }
    $snapshot = $result['clean'];
}

$snapshot = DeliverySessionAddress::refreshServiceability($snapshot);
DeliverySessionAddress::store($snapshot);

$serviceable = $snapshot['serviceable'];
Ajax::success([
    'header' => [
        'title' => DeliverySessionAddress::title($snapshot),
        'subtitle' => DeliverySessionAddress::subtitle($snapshot),
    ],
    'address_line' => DeliverySessionAddress::readableLine($snapshot),
    'serviceable' => $serviceable,
    'service_checked' => $serviceable !== null,
    'service_zone' => $snapshot['service_zone'],
    'service_message' => $snapshot['service_message'],
    'has_coordinates' => $snapshot['latitude'] !== null && $snapshot['longitude'] !== null,
], (string) ($snapshot['service_message'] ?? 'Delivery address updated.'));
