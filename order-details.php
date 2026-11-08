<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$order = null;
if ($id !== '' && CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $custId = (int) CustomerAuth::id();
    $orderRow = PaymentRepository::orderByNumberForCustomer($id, $custId);
    $isRetryPaymentPost = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storefront_retry_payment']);
    $pendingAutolaunchGet = $_SERVER['REQUEST_METHOD'] === 'GET'
        && !empty($_SESSION['checkout_payment_autolaunch'][$id])
        && is_array($_SESSION['checkout_payment'][$id] ?? null);
    if (!$isRetryPaymentPost
        && !$pendingAutolaunchGet
        && $orderRow !== null
        && PaymentService::customerCanRetryStorefrontPayment($orderRow)
        && PaymentGatewayConfig::activeGateway() === 'cashfree') {
        try {
            PaymentService::syncCashfreeOrderPayment($id, $custId);
        } catch (Throwable $e) {
            error_log('order-details cashfree sync: ' . $e->getMessage());
        }
    }
    $order = OrderRepository::forCustomerDetail($id, $custId);
}
if ($order === null && $id !== '' && AppEnvironment::allowsSessionDemoCommerce()) {
    $order = DemoCustomer::order($id);
}

if ($order === null) {
    layout_public_start(['pageTitle' => 'Order — Veggie Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Order not found';
    $emptyMessage = 'Check the link or view all orders.';
    $emptyActionHref = url_base() . '/my-orders.php';
    $emptyActionLabel = 'My orders';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$refundMsg = '';
$refundErr = '';
$orderDelivered = false;
$refundItems = [];
$orderRefunds = [];
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $row = OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
    if ($row !== null) {
        $orderRefunds = RefundRequestService::enrichCustomerOrderRefunds(
            RefundRequestService::listForOrderCustomer((int) $row['id'], CustomerAuth::id()),
            CustomerAuth::id()
        );
        $orderDelivered = OrderFulfillmentService::isOrderDelivered((int) $row['id']);
        if ($orderDelivered) {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT oi.id, oi.product_name, oi.variant_name, oi.quantity, oi.unit_price
                 FROM order_items oi WHERE oi.order_id = ?'
            );
            $stmt->execute([(int) $row['id']]);
            $refundItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
}

$cancelSuccess = '';
$cancelError = '';
$cancelEligible = false;
$cancelMessage = null;
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null && $id !== '') {
    $cancelState = OrderCancellationService::customerCancelState((int) CustomerAuth::id(), $id);
    $cancelEligible = !empty($cancelState['eligible']);
    $cancelMessage = $cancelState['message'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    Csrf::requireValid();
    if (!CommerceGateway::useDatabase() || CustomerAuth::id() === null) {
        $cancelError = 'Cancellation requires a live account order.';
    } else {
        $result = OrderCancellationService::cancelByCustomer(
            (int) CustomerAuth::id(),
            $id,
            (string) ($_POST['reason_code'] ?? ''),
            (string) ($_POST['reason_detail'] ?? ''),
            (string) ($_POST['idempotency_key'] ?? '')
        );
        if (!empty($result['ok'])) {
            $cancelSuccess = (string) ($result['message'] ?? 'Order cancelled.');
            $order = OrderRepository::forCustomerDetail($id, CustomerAuth::id());
            $row = OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
            if ($row !== null) {
                $orderRefunds = RefundRequestService::enrichCustomerOrderRefunds(
            RefundRequestService::listForOrderCustomer((int) $row['id'], CustomerAuth::id()),
            CustomerAuth::id()
        );
            }
            $cancelEligible = false;
        } else {
            $cancelError = (string) ($result['message'] ?? 'Could not cancel order.');
        }
    }
}

$retryPaymentError = '';
$retryPaymentInfo = '';
$retryPaymentAutoLaunch = false;
$retryPaymentCheckout = null;
$retryPaymentCashfreeSandbox = PaymentGatewayConfig::usesSandboxPayments();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storefront_retry_payment'])) {
    Csrf::requireValid();
    if (!CommerceGateway::useDatabase() || CustomerAuth::id() === null || $id === '') {
        $retryPaymentError = 'Payment retry requires a live account order.';
    } else {
        $custId = (int) CustomerAuth::id();
        $retryRow = OrderFulfillmentService::orderRowForCustomer($id, $custId);
        if ($retryRow === null || !PaymentService::customerCanRetryStorefrontPayment($retryRow)) {
            $retryPaymentError = 'This order cannot accept payment right now.';
        } else {
            $orderNumber = (string) ($retryRow['order_number'] ?? $id);
            try {
                $prep = PaymentService::prepareStorefrontOrderPayment($orderNumber, $custId, true);
            } catch (Throwable $e) {
                error_log('storefront_retry_payment: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $prep = ['ok' => false, 'error' => 'Could not start payment. Please try again in a moment.'];
            }
            if (!empty($prep['redirect'])) {
                header('Location: ' . $prep['redirect'], true, 302);
                exit;
            }
            if (empty($prep['ok'])) {
                $retryPaymentError = (string) ($prep['error'] ?? 'Could not start payment.');
                $retryPaymentInfo = (string) ($prep['info'] ?? '');
            } else {
                $launchMode = (string) (($prep['checkout'] ?? [])['mode'] ?? '');
                $sessionReady = ($launchMode === 'cashfree'
                        && trim((string) (($prep['checkout'] ?? [])['payment_session_id'] ?? '')) !== '')
                    || ($launchMode === 'razorpay'
                        && trim((string) (($prep['checkout'] ?? [])['gateway_order_id'] ?? '')) !== '')
                    || $launchMode === 'simulate';
                if (!in_array($launchMode, ['cashfree', 'razorpay', 'simulate'], true) || !$sessionReady) {
                    unset($_SESSION['checkout_payment_autolaunch'][$orderNumber]);
                    unset($_SESSION['checkout_payment'][$orderNumber]);
                    if (in_array($launchMode, ['cashfree', 'razorpay'], true) && !$sessionReady) {
                        $retryPaymentError = 'Could not open secure checkout. Please try again.';
                    }
                } else {
                    header('Location: ' . url_to('/order-details?id=' . rawurlencode($id)), true, 303);
                    exit;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_request'])) {
    Csrf::requireValid();
    if (!CommerceGateway::useDatabase() || CustomerAuth::id() === null) {
        $refundErr = 'Refund requests require a live account order.';
    } else {
        $itemIds = isset($_POST['order_item_id']) && is_array($_POST['order_item_id'])
            ? array_map('intval', $_POST['order_item_id']) : [];
        $qtys = [];
        foreach ($itemIds as $iid) {
            $qtys[$iid] = (int) ($_POST['qty'][$iid] ?? 1);
        }
        $files = isset($_FILES['evidence']) ? $_FILES['evidence'] : null;
        $result = RefundRequestService::create(
            CustomerAuth::id(),
            $id,
            (string) ($_POST['reason_code'] ?? 'other'),
            (string) ($_POST['notes'] ?? ''),
            (string) ($_POST['preferred_resolution'] ?? 'refund'),
            $itemIds,
            $qtys,
            $files
        );
        if ($result['ok']) {
            $refundMsg = 'Refund request ' . ($result['request_number'] ?? '') . ' submitted. We will review it shortly.';
            $liveOrder = OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
            if ($liveOrder !== null && CustomerAuth::id() !== null) {
                $orderRefunds = RefundRequestService::enrichCustomerOrderRefunds(
                    RefundRequestService::listForOrderCustomer((int) $liveOrder['id'], CustomerAuth::id()),
                    CustomerAuth::id()
                );
            }
        } else {
            $refundErr = $result['message'] ?? 'Could not submit request.';
        }
    }
}

$invoiceSync = null;
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null && isset($row) && is_array($row)) {
    $invoiceSync = InvoiceService::syncForOrder((int) $row['id']);
}

$overview = $order['overview'] ?? null;
$totals = $order['price'] ?? $order['totals'] ?? [];
$timeline = $order['timeline'] ?? [
    ['status' => 'Confirmed', 'at' => $order['created_at'] ?? '', 'done' => true],
];
$payments = $order['payments'] ?? [];
$paySummary = $order['payment_summary'] ?? null;
$address = $order['address'] ?? null;
$delivery = $order['delivery'] ?? null;
$meta = $order['meta'] ?? [];

$displayId = (string) ($overview['order_number'] ?? $order['id'] ?? $id);
$placedAt = (string) ($overview['placed_at'] ?? $order['created_at'] ?? '');
$currentStatus = is_array($order['current_status'] ?? null) ? $order['current_status'] : [];
$orderStatus = (string) ($currentStatus['status'] ?? $overview['status_public'] ?? $order['status'] ?? '');
$paymentStatusLabel = (string) ($overview['payment_status_label'] ?? '');

$showRetryPayment = false;
$retryPaymentAmount = (float) ($totals['grand_total'] ?? $totals['total'] ?? 0);
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null && $id !== '') {
    $orderRowForRetry = isset($row) && is_array($row)
        ? $row
        : OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
    if ($orderRowForRetry !== null && PaymentService::customerCanRetryStorefrontPayment($orderRowForRetry)) {
        $showRetryPayment = true;
        $retryPaymentAmount = (float) ($orderRowForRetry['grand_total'] ?? $retryPaymentAmount);
        if ($paySummary !== null && isset($paySummary['amount']) && (float) $paySummary['amount'] > 0) {
            $retryPaymentAmount = (float) $paySummary['amount'];
        }
        $pendingCheckout = $_SESSION['checkout_payment'][$displayId] ?? null;
        if (!empty($_SESSION['checkout_payment_autolaunch'][$displayId]) && is_array($pendingCheckout)) {
            $launchMode = (string) ($pendingCheckout['mode'] ?? '');
            $launchReady = ($launchMode === 'cashfree' && trim((string) ($pendingCheckout['payment_session_id'] ?? '')) !== '')
                || ($launchMode === 'razorpay' && trim((string) ($pendingCheckout['gateway_order_id'] ?? '')) !== '');
            if ($launchReady) {
                $retryPaymentAutoLaunch = true;
                $retryPaymentCheckout = $pendingCheckout;
                unset($_SESSION['checkout_payment_autolaunch'][$displayId]);
            }
        }
    }
}

layout_public_start(['pageTitle' => 'Order ' . $displayId . ' — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'orders';
$accountTitle = 'Order ' . $displayId;
$accountSub = $placedAt !== '' ? 'Placed ' . customer_format_datetime($placedAt) : null;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';

$firstNotDone = null;
foreach ($timeline as $i => $step) {
    if (empty($step['done'])) {
        $firstNotDone = $i;
        break;
    }
}

$formatDeliveryDay = static function (?string $d): string {
    if ($d === null || trim($d) === '') {
        return '—';
    }
    $ts = strtotime($d);

    return $ts !== false ? date('j M Y', $ts) : $d;
};
?>
<header class="at-order-detail__header">
    <div class="at-order-detail__head-main">
        <p class="at-order-detail__id">Order <span><?= Security::escape($displayId) ?></span></p>
        <?php if ($placedAt !== ''): ?>
            <p class="at-order-detail__meta text-sm at-muted"><?= Security::escape(customer_format_datetime($placedAt)) ?></p>
        <?php endif; ?>
        <div class="at-order-detail__badges at-order-detail__badges--header">
            <?= customer_status_badge($orderStatus, ucwords(str_replace('_', ' ', $orderStatus)), 'order') ?>
            <?php if ($paymentStatusLabel !== '' && $paymentStatusLabel !== '—'): ?>
                <span class="at-order-detail__pay-badge"><?= Security::escape($paymentStatusLabel) ?></span>
            <?php endif; ?>
            <?php if (!empty($overview['order_type'])): ?>
                <span class="at-order-detail__type"><?= Security::escape((string) $overview['order_type']) ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($overview['expected_delivery'])): ?>
            <p class="text-sm mt-2"><strong>Expected delivery:</strong> <?= Security::escape($formatDeliveryDay((string) $overview['expected_delivery'])) ?></p>
        <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2 items-center">
        <?php if ($cancelEligible): ?>
            <a href="#at-open-cancel-dialog" class="at-btn at-btn--ghost at-btn--sm at-focusable" onclick="document.getElementById('at-open-cancel-dialog')?.click();return false;">Cancel order</a>
        <?php endif; ?>
        <a href="<?= Security::escape(url_base()) ?>/support.php?order=<?= rawurlencode($displayId) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable"><?= at_icon('chat', 'w-4 h-4') ?><?= Security::escape(__('need_help')) ?></a>
    </div>
</header>

<?php
$cancelOrderNumber = $displayId;
require APNI_TOKRI_ROOT . '/includes/partials/customer-order-cancel.php';
?>

<?php require APNI_TOKRI_ROOT . '/includes/partials/order-current-status.php'; ?>
<?php require APNI_TOKRI_ROOT . '/includes/partials/order-retry-payment-cta.php'; ?>

<section class="at-card at-order-detail__timeline">
    <div class="at-card__body">
        <h2 class="at-h3">Order tracking</h2>
        <div class="mt-4">
            <?php $resolvedOrderStatus = $orderStatus; require APNI_TOKRI_ROOT . '/includes/partials/order-stepper.php'; ?>
        </div>
    </div>
</section>

<div class="at-order-detail__grid">
    <div class="at-order-detail__col at-order-detail__col--main">
        <section class="at-card">
            <div class="at-card__body">
                <h2 class="at-h3">Items</h2>
                <?php $orderItems = $order['items'] ?? []; require APNI_TOKRI_ROOT . '/includes/partials/order-items.php'; ?>
            </div>
        </section>

        <section class="at-card">
            <div class="at-card__body">
                <h2 class="at-h3">Delivery address</h2>
                <?php if ($address === null): ?>
                    <p class="text-sm at-muted mt-2">Delivery address is not available for this order.</p>
                <?php else: ?>
                    <div class="at-order-detail__addr mt-3">
                        <?php if ($address['receiver_name'] !== '' || $address['receiver_phone'] !== ''): ?>
                            <p class="font-semibold"><?= Security::escape((string) $address['receiver_name']) ?>
                                <?php if ($address['receiver_phone'] !== ''): ?>
                                    <span class="font-normal at-muted"> · <?= Security::escape((string) $address['receiver_phone']) ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <?php foreach ($address['lines'] ?? [] as $line): ?>
                            <p class="text-sm"><?= Security::escape((string) $line) ?></p>
                        <?php endforeach; ?>
                        <?php if (!empty($address['state'])): ?>
                            <p class="text-sm"><?= Security::escape((string) $address['state']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($address['landmark'])): ?>
                            <p class="text-sm at-muted">Landmark: <?= Security::escape((string) $address['landmark']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($address['delivery_instructions'])): ?>
                            <p class="text-sm mt-2"><strong>Instructions:</strong> <?= Security::escape((string) $address['delivery_instructions']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($address['map_link'])): ?>
                            <p class="mt-2">
                                <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape((string) $address['map_link']) ?>" target="_blank" rel="noopener noreferrer">View on map</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="at-card">
            <div class="at-card__body">
                <h2 class="at-h3">Delivery slot</h2>
                <?php if ($delivery === null || (empty($delivery['has_slot']) && empty($delivery['delivery_status']))): ?>
                    <p class="text-sm at-muted mt-2">No delivery slot was recorded for this order.</p>
                <?php else: ?>
                    <dl class="at-order-detail__dl mt-3">
                        <?php if (!empty($delivery['delivery_date'])): ?>
                            <div><dt>Delivery date</dt><dd><?= Security::escape($formatDeliveryDay((string) $delivery['delivery_date'])) ?></dd></div>
                        <?php elseif (!empty($delivery['delivery_eta'])): ?>
                            <div><dt>Estimated</dt><dd><?= Security::escape($formatDeliveryDay((string) $delivery['delivery_eta'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['slot_label'])): ?>
                            <div><dt>Slot</dt><dd><?= Security::escape((string) $delivery['slot_label']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['window_label'])): ?>
                            <div><dt>Window</dt><dd><?= Security::escape((string) $delivery['window_label']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['time_range'])): ?>
                            <div><dt>Time</dt><dd><?= Security::escape((string) $delivery['time_range']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['delivery_status'])): ?>
                            <div><dt>Delivery status</dt><dd><?= Security::escape((string) $delivery['delivery_status']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['delivery_contact_phone'])): ?>
                            <div><dt>Delivery contact</dt><dd><a href="tel:<?= Security::escape(preg_replace('/\D+/', '', (string) $delivery['delivery_contact_phone']) ?? '') ?>"><?= Security::escape((string) $delivery['delivery_contact_phone']) ?></a></dd></div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($meta['cancellation_reason'])): ?>
            <section class="at-card">
                <div class="at-card__body">
                    <h2 class="at-h3">Cancellation</h2>
                    <p class="text-sm mt-2"><?= Security::escape((string) $meta['cancellation_reason']) ?></p>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <aside class="at-order-detail__col at-order-detail__col--side">
        <section class="at-card">
            <div class="at-card__body">
                <h2 class="at-h3">Payment details</h2>
                <?php if ($paySummary !== null && !empty($paySummary['has_record'])): ?>
                    <dl class="at-order-detail__dl mt-3">
                        <div><dt>Gateway</dt><dd><?= Security::escape((string) $paySummary['gateway_label']) ?></dd></div>
                        <div><dt>Status</dt><dd><?= Security::escape((string) $paySummary['status_label']) ?></dd></div>
                        <div><dt>Amount</dt><dd><?= Security::escape(catalogue_format_price((float) ($paySummary['amount'] ?? 0))) ?></dd></div>
                        <?php if (!empty($paySummary['paid_at'])): ?>
                            <div><dt>Paid on</dt><dd><?= Security::escape(customer_format_datetime((string) $paySummary['paid_at'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($paySummary['payment_method'])): ?>
                            <div><dt>Method</dt><dd><?= Security::escape((string) $paySummary['payment_method']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($paySummary['gateway_payment_id'])): ?>
                            <div><dt>Payment ID</dt><dd class="at-order-detail__mono"><?= Security::escape((string) $paySummary['gateway_payment_id']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($paySummary['gateway_order_id'])): ?>
                            <div><dt>Gateway order</dt><dd class="at-order-detail__mono"><?= Security::escape((string) $paySummary['gateway_order_id']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ((float) ($paySummary['amount_refunded'] ?? 0) > 0): ?>
                            <div><dt>Refunded</dt><dd><?= Security::escape(catalogue_format_price((float) $paySummary['amount_refunded'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if (count($payments) > 1): ?>
                        <p class="text-xs at-muted mt-3"><?= (int) count($payments) ?> payment attempts on this order.</p>
                    <?php endif; ?>
                    <?php if ($showRetryPayment): ?>
                        <p class="text-xs at-muted mt-3">Use the <strong>Retry Payment</strong> button at the top of this page to pay securely.</p>
                    <?php endif; ?>
                <?php elseif ($paySummary !== null): ?>
                    <dl class="at-order-detail__dl mt-3">
                        <?php if (!empty($paySummary['gateway_label'])): ?>
                            <div><dt>Method</dt><dd><?= Security::escape((string) $paySummary['gateway_label']) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Status</dt><dd><?= Security::escape((string) ($paySummary['status_label'] ?? '—')) ?></dd></div>
                    </dl>
                    <?php if (!empty($paySummary['message'])): ?>
                        <p class="text-sm at-muted mt-2"><?= Security::escape((string) $paySummary['message']) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-sm at-muted mt-2">Payment information is not available.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="at-card">
            <div class="at-card__body">
                <h2 class="at-h3">Price summary</h2>
                <dl class="at-order-totals mt-3">
                    <div><dt>Subtotal</dt><dd><?= Security::escape(catalogue_format_price((float) ($totals['subtotal'] ?? 0))) ?></dd></div>
                    <?php if ((float) ($totals['discount'] ?? $totals['coupon_discount'] ?? 0) > 0): ?>
                        <div class="is-saving"><dt>Discount<?= !empty($totals['coupon_code']) ? ' (' . Security::escape((string) $totals['coupon_code']) . ')' : '' ?></dt><dd>−<?= Security::escape(catalogue_format_price((float) ($totals['discount'] ?? $totals['coupon_discount'] ?? 0))) ?></dd></div>
                    <?php endif; ?>
                    <div><dt>Delivery fee</dt><dd><?= (float) ($totals['delivery_fee'] ?? 0) === 0.0 ? 'Free' : Security::escape(catalogue_format_price((float) ($totals['delivery_fee'] ?? 0))) ?></dd></div>
                    <div class="is-grand"><dt>Total</dt><dd><?= Security::escape(catalogue_format_price((float) ($totals['grand_total'] ?? $totals['total'] ?? 0))) ?></dd></div>
                    <?php if (isset($totals['amount_paid']) && (float) $totals['amount_paid'] > 0): ?>
                        <div><dt>Amount paid</dt><dd><?= Security::escape(catalogue_format_price((float) $totals['amount_paid'])) ?></dd></div>
                    <?php endif; ?>
                    <?php if (isset($totals['amount_refunded']) && (float) $totals['amount_refunded'] > 0): ?>
                        <div class="is-saving"><dt>Refunded</dt><dd><?= Security::escape(catalogue_format_price((float) $totals['amount_refunded'])) ?></dd></div>
                    <?php endif; ?>
                    <?php if (isset($totals['outstanding']) && (float) $totals['outstanding'] > 0): ?>
                        <div><dt>Outstanding</dt><dd><?= Security::escape(catalogue_format_price((float) $totals['outstanding'])) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <?php if ($orderRefunds !== []): ?>
            <?php
            $refundTotal = 0.0;
            foreach ($orderRefunds as $or) {
                if ((string) ($or['status'] ?? '') === RefundStatus::REFUNDED) {
                    $refundTotal += (float) ($or['display_amount_inr'] ?? 0);
                }
            }
            ?>
            <section class="at-card">
                <div class="at-card__body">
                    <h2 class="at-h3">Refund tracking</h2>
                    <?php if ($refundTotal > 0): ?>
                        <p class="text-sm at-muted mt-1">Total refunded on this order: <strong><?= Security::escape(catalogue_format_price($refundTotal)) ?></strong></p>
                    <?php endif; ?>
                    <ul class="at-order-detail__refund-list mt-3 space-y-4">
                        <?php foreach ($orderRefunds as $or): ?>
                            <li class="border-b border-[var(--at-line)] pb-3 last:border-0">
                                <p>
                                    <a class="font-semibold underline" href="<?= Security::escape(url_base()) ?>/refund-details.php?refund=<?= rawurlencode((string) $or['request_number']) ?>"><?= Security::escape((string) $or['request_number']) ?></a>
                                    <span class="at-muted"> — <?= Security::escape((string) ($or['status_label'] ?? '')) ?></span>
                                </p>
                                <?php if ((float) ($or['display_amount_inr'] ?? 0) > 0): ?>
                                    <p class="text-sm mt-1">Amount: <strong><?= Security::escape(catalogue_format_price((float) $or['display_amount_inr'])) ?></strong></p>
                                <?php endif; ?>
                                <?php if (!empty($or['gateway_name'])): ?>
                                    <p class="text-xs at-muted">Gateway: <?= Security::escape((string) $or['gateway_name']) ?><?php if (!empty($or['gateway_status_label'])): ?> · <?= Security::escape((string) $or['gateway_status_label']) ?><?php endif; ?></p>
                                <?php endif; ?>
                                <?php if (!empty($or['initiated_at'])): ?>
                                    <p class="text-xs at-muted">Initiated: <?= Security::escape(customer_format_datetime((string) $or['initiated_at'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($or['completed_at'])): ?>
                                    <p class="text-xs at-muted">Completed: <?= Security::escape(customer_format_datetime((string) $or['completed_at'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($or['customer_message'])): ?>
                                    <p class="text-sm mt-2"><?= Security::escape((string) $or['customer_message']) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-2"><a class="text-sm underline" href="<?= Security::escape(url_base()) ?>/my-refunds.php">All refund requests</a></p>
                </div>
            </section>
        <?php endif; ?>

        <section class="at-card" id="invoice">
            <div class="at-card__body text-sm">
                <h2 class="at-h3">Invoice</h2>
                <?php
                $invoicePortalOpen = isset($row) && is_array($row) && InvoiceService::customerPortalReleased((int) $row['id']);
                if (!empty($invoiceSync['invoice']) && $invoicePortalOpen):
                    $inv = $invoiceSync['invoice'];
                    ?>
                    <p class="mt-2"><strong><?= Security::escape((string) $inv['invoice_number']) ?></strong></p>
                    <p class="at-muted">Retail invoice · <?= Security::escape(InvoiceFormat::date((string) $inv['invoice_date'])) ?></p>
                    <p class="mt-3 flex flex-wrap gap-2">
                        <a class="at-btn at-btn--primary at-btn--sm at-focusable" href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode((string) $inv['public_ref']) ?>">Download PDF</a>
                        <a class="at-btn at-btn--secondary at-btn--sm at-focusable" target="_blank" rel="noopener" href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode((string) $inv['public_ref']) ?>&amp;inline=1">View</a>
                    </p>
                    <?php foreach ($invoiceSync['credit_notes'] as $cn): ?>
                        <div class="mt-4 pt-3 border-t border-[var(--at-line)]">
                            <p><strong><?= Security::escape((string) $cn['invoice_number']) ?></strong> <span class="at-muted">· Credit note · <?= Security::escape(catalogue_format_price((float) $cn['grand_total'])) ?></span></p>
                            <p class="mt-2 flex flex-wrap gap-2">
                                <a class="at-btn at-btn--secondary at-btn--sm at-focusable" href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode((string) $cn['public_ref']) ?>">Download</a>
                                <a class="at-btn at-btn--secondary at-btn--sm at-focusable" target="_blank" rel="noopener" href="<?= Security::escape(url_base()) ?>/invoice-download.php?ref=<?= rawurlencode((string) $cn['public_ref']) ?>&amp;inline=1">View</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (!empty($invoiceSync['invoice']) && !$invoicePortalOpen): ?>
                    <p class="at-muted mt-2">Your invoice will be available once your order is delivered.</p>
                <?php elseif ($invoiceSync !== null && !empty($invoiceSync['eligible'])): ?>
                    <p class="at-muted mt-2">Your invoice is being prepared. Please check back in a moment.</p>
                <?php else: ?>
                    <p class="at-muted mt-2">Your invoice will be available once payment is confirmed.</p>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<?php if ($orderDelivered): ?>
    <section class="at-card at-card--tint">
        <div class="at-card__body flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="at-h3">How did we do?</h2><p class="text-sm at-muted mt-1">Reviews are for delivered orders and appear after approval.</p></div>
            <a href="<?= Security::escape(url_base()) ?>/submit-review.php?order=<?= rawurlencode($displayId) ?>" class="at-btn at-btn--secondary at-focusable">Write a review</a>
        </div>
    </section>
<?php endif; ?>

<section class="at-card">
    <div class="at-card__body">
        <h2 class="at-h3">Refund or quality issue</h2>
        <p class="text-sm at-muted mt-1">Tell us what went wrong. We review every request against our <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=refund-policy">refund policy</a>.</p>
        <?php if ($orderRefunds !== [] && empty($order['overview'])): ?>
            <div class="mt-3 p-3 rounded-lg bg-[var(--at-cream-2)] text-sm">
                <p class="font-semibold">Your refund requests for this order</p>
                <ul class="mt-2 space-y-1">
                    <?php foreach ($orderRefunds as $or): ?>
                        <li>
                            <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/refund-details.php?refund=<?= rawurlencode((string) $or['request_number']) ?>"><?= Security::escape((string) $or['request_number']) ?></a>
                            — <?= Security::escape(RefundRequestService::publicStatusLabel((string) $or['status'])) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($refundMsg !== ''): ?>
            <div class="at-alert at-alert--success mt-3"><?= at_icon('check', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($refundMsg) ?></span></div>
        <?php endif; ?>
        <?php if ($refundErr !== ''): ?>
            <div class="at-alert at-alert--error mt-3"><span><?= Security::escape($refundErr) ?></span></div>
        <?php endif; ?>
        <?php if (!$orderDelivered && CommerceGateway::useDatabase()): ?>
            <p class="text-sm mt-3 at-muted">Refund requests open after your order is marked delivered.</p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-4 max-w-xl">
            <?= Csrf::field() ?>
            <input type="hidden" name="refund_request" value="1">
            <div>
                <label class="at-label" for="reason_code">Reason</label>
                <select id="reason_code" name="reason_code" class="at-select at-focusable">
                    <?php foreach (RefundRequestService::reasonLabels() as $code => $label): ?>
                        <option value="<?= Security::escape($code) ?>"><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($refundItems !== []): ?>
                <fieldset class="grid gap-2">
                    <legend class="at-label">Affected items</legend>
                    <?php foreach ($refundItems as $ri): ?>
                        <label class="flex gap-2 text-sm items-center">
                            <input type="checkbox" name="order_item_id[]" value="<?= (int) $ri['id'] ?>">
                            <?= Security::escape((string) $ri['product_name']) ?> (max <?= (int) $ri['quantity'] ?>)
                            <input type="number" name="qty[<?= (int) $ri['id'] ?>]" min="1" max="<?= (int) $ri['quantity'] ?>" value="<?= (int) $ri['quantity'] ?>" class="at-input w-20">
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <div>
                <label class="at-label" for="preferred_resolution">Preferred resolution</label>
                <select id="preferred_resolution" name="preferred_resolution" class="at-select at-focusable">
                    <?php foreach (RefundRequestService::resolutionOptions() as $opt): ?>
                        <option value="<?= Security::escape($opt) ?>"><?= Security::escape(ucwords(str_replace('_', ' ', $opt))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="at-label" for="refund_notes">Details</label>
                <textarea id="refund_notes" name="notes" class="at-textarea at-focusable" rows="3" required placeholder="What happened?"></textarea>
            </div>
            <div>
                <label class="at-label" for="evidence">Photos (optional)</label>
                <input id="evidence" name="evidence[]" type="file" accept="image/*" multiple class="at-input at-focusable !py-2">
            </div>
            <div><button type="submit" class="at-btn at-btn--primary at-focusable">Submit refund request</button></div>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
require APNI_TOKRI_ROOT . '/includes/partials/order-retry-payment-launch.php';
layout_public_end();
