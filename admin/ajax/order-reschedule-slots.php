<?php

declare(strict_types=1);

/**
 * Slots with capacity for an admin-chosen delivery date (order reschedule).
 * GET ?order_id=&delivery_date=YYYY-MM-DD
 */
require_once dirname(__DIR__) . '/_init.php';

if (!AdminDemoPerms::can('orders')) {
    Ajax::error('Forbidden.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$orderId = isset($_GET['order_id']) && ctype_digit((string) $_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$deliveryDate = isset($_GET['delivery_date']) && is_string($_GET['delivery_date']) ? trim($_GET['delivery_date']) : '';
if ($orderId <= 0 || $deliveryDate === '') {
    Ajax::error('Invalid request.');
}

$order = AdminOrderService::findById($orderId);
if ($order === null) {
    Ajax::error('Order not found.', 404);
}

$paymentRecovery = PaymentFulfilmentReconciliationService::adminRecoveryContext($orderId);
if (!AdminOrderDeliveryRescheduleService::canRescheduleOrder($order, $paymentRecovery)) {
    Ajax::error('Reschedule is not available for this order.', 400);
}

$slots = AdminOrderDeliveryRescheduleService::slotOptionsForDate($orderId, $deliveryDate);
Ajax::success(['slots' => $slots]);
