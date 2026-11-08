<?php

declare(strict_types=1);

/**
 * Read-only refund gateway failure context (no API calls, no status changes).
 *
 * CLI: php scripts/diagnose-refund-gateway-failure.php 4
 *      php scripts/diagnose-refund-gateway-failure.php RF-261107-0004
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$arg = $argv[1] ?? '';
if ($arg === '' || !Install::isSchemaReady()) {
    fwrite(STDERR, "Usage: php scripts/diagnose-refund-gateway-failure.php <refund_request_id|request_number>\n");
    exit($arg === '' ? 2 : 1);
}

Install::ensureConnection();
$pdo = Database::pdo();

if (ctype_digit($arg)) {
    $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $arg]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM refund_requests WHERE request_number = ? LIMIT 1');
    $stmt->execute([$arg]);
}
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    fwrite(STDERR, "Refund request not found.\n");
    exit(1);
}

$requestId = (int) $req['id'];
$orderId = (int) $req['order_id'];

$mask = static function (string $s, int $keep = 4): string {
    $s = trim($s);
    if ($s === '') {
        return '(empty)';
    }
    if (strlen($s) <= $keep * 2) {
        return str_repeat('*', strlen($s));
    }

    return substr($s, 0, $keep) . '…' . substr($s, -$keep);
};

echo "=== Refund request ===\n";
echo 'id: ' . $requestId . "\n";
echo 'request_number: ' . (string) $req['request_number'] . "\n";
echo 'status: ' . (string) $req['status'] . "\n";
echo 'approved_amount_inr: ' . (string) ($req['approved_amount_inr'] ?? '') . "\n";
echo 'order_id: ' . $orderId . "\n";

$oStmt = $pdo->prepare('SELECT order_number, payment_status, grand_total, placed_at FROM orders WHERE id = ? LIMIT 1');
$oStmt->execute([$orderId]);
$order = $oStmt->fetch(PDO::FETCH_ASSOC) ?: [];
echo 'order_number: ' . (string) ($order['order_number'] ?? '') . "\n";
echo 'order payment_status: ' . (string) ($order['payment_status'] ?? '') . "\n";

echo "\n=== Refundable (app logic) ===\n";
echo 'captured_available: ' . number_format(RefundRequestService::capturedAmountForOrder($orderId), 2) . "\n";
echo 'completed_refunded: ' . number_format(RefundRequestService::completedRefundTotalForOrder($orderId), 2) . "\n";
echo 'pending_other_requests: ' . number_format(RefundRequestService::pendingRefundTotalForOrder($orderId), 2) . "\n";

echo "\n=== Payments for order (newest last) ===\n";
$payStmt = $pdo->prepare('SELECT id, gateway, status, amount, amount_refunded_inr, gateway_payment_id, gateway_order_id, created_at FROM payments WHERE order_id = ? ORDER BY id ASC');
$payStmt->execute([$orderId]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$latest = PaymentRepository::latestPaymentForOrder($orderId);
foreach ($payments as $p) {
    $marker = $latest !== null && (int) $p['id'] === (int) $latest['id'] ? ' [latestPaymentForOrder]' : '';
    echo sprintf(
        "- id=%d gateway=%s status=%s amount=%.2f refunded=%.2f gw_pay=%s gw_ord=%s%s\n",
        (int) $p['id'],
        (string) $p['gateway'],
        (string) $p['status'],
        (float) $p['amount'],
        (float) ($p['amount_refunded_inr'] ?? 0),
        $mask((string) ($p['gateway_payment_id'] ?? '')),
        $mask((string) ($p['gateway_order_id'] ?? '')),
        $marker
    );
}

$approved = (float) ($req['approved_amount_inr'] ?? 0);
if ($latest !== null) {
    $cap = (float) ($latest['amount'] ?? 0);
    $already = (float) ($latest['amount_refunded_inr'] ?? 0);
    $gw = (string) ($latest['gateway'] ?? '');
    echo "\n=== Gateway initiation preview (no API call) ===\n";
    echo 'would_use_payment_id: ' . (int) $latest['id'] . "\n";
    echo 'gateway: ' . $gw . "\n";
    echo 'approved_vs_payment_cap: ' . $approved . ' + ' . $already . ' <= ' . $cap . ' => ';
    echo ($approved + $already <= $cap + 0.01) ? "OK\n" : "EXCEEDS\n";
    echo 'cashfree_api_mode: ' . PaymentGatewayConfig::cashfreeApiHostLabel() . "\n";
    echo 'live_gateway_refunds_enabled: ' . (GatewayRefundService::liveGatewayRefundsEnabled() ? 'yes' : 'no') . "\n";
    $merchantOrderId = (string) ($order['order_number'] ?? '');
    if ($gw === 'cashfree' && $merchantOrderId !== '') {
        echo 'cashfree_endpoint: POST ' . CashfreeGateway::ordersRefundApiPath($merchantOrderId) . "\n";
        echo 'sanitized_body: refund_amount=' . round($approved, 2) . ', refund_id=refund_req_' . $requestId . " (or _attempt_N), refund_type=MERCHANT_INITIATED\n";
    }
    if ($gw === 'razorpay') {
        echo 'razorpay_endpoint: POST https://api.razorpay.com/v1/payments/' . $mask((string) ($latest['gateway_payment_id'] ?? '')) . "/refund\n";
    }
}

echo "\n=== payment_refunds rows (this request) ===\n";
$prStmt = $pdo->prepare(
    'SELECT id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, created_at
     FROM payment_refunds WHERE refund_request_id = ? ORDER BY id'
);
$prStmt->execute([$requestId]);
$prRows = $prStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($prRows === []) {
    echo "(none) — gateway call did not persist a payment_refunds row (typical when initiateGatewayRefund got gwResult ok=false).\n";
} else {
    foreach ($prRows as $pr) {
        echo sprintf(
            "- id=%d gateway=%s amount=%.2f status=%s idem=%s gw_refund_id=%s\n",
            (int) $pr['id'],
            (string) $pr['gateway'],
            (float) $pr['amount_inr'],
            (string) $pr['gateway_status'],
            (string) $pr['idempotency_key'],
            $mask((string) ($pr['gateway_refund_id'] ?? ''))
        );
        if ((string) $pr['gateway'] === 'cashfree') {
            $row = $pr;
            $row['order_number'] = (string) ($order['order_number'] ?? '');
            $lookup = GatewayRefundService::cashfreeMerchantRefundIdForStatusGet($row);
            if ($lookup !== '' && $row['order_number'] !== '') {
                echo '  cashfree_status_get: GET ' . CashfreeGateway::orderRefundStatusApiPath($row['order_number'], $lookup) . "\n";
                echo '  (merchant refund_id for GET; cf_refund_id is not the URL segment)' . "\n";
            }
        }
    }
}

echo "\n=== refund_request_history (gateway_*) ===\n";
$hist = RefundRequestService::history($requestId);
foreach ($hist as $h) {
    $action = (string) ($h['action'] ?? '');
    if (!in_array($action, ['gateway_initiated', 'gateway_failed', 'retry_requested', 'approved'], true)) {
        continue;
    }
    $payload = null;
    if (!empty($h['payload_json'])) {
        try {
            $payload = json_decode((string) $h['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $payload = ['_parse_error' => true];
        }
    }
    echo $h['created_at'] . ' | ' . $action;
    if (is_array($payload)) {
        echo ' | ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    echo "\n";
}

echo "\n=== Interpretation ===\n";
if ($prRows === [] && (string) $req['status'] === RefundStatus::REFUND_FAILED) {
    echo "- Application marked refund_failed without a payment_refunds row: Cashfree/Razorpay HTTP was not 2xx OR body was not valid JSON (see server error_log: 'Cashfree refund HTTP N' / 'Razorpay refund HTTP N').\n";
    echo "- Check Cashfree dashboard for order " . (string) ($order['order_number'] ?? '') . " for refunds with refund_id refund_req_{$requestId} before retrying.\n";
}
echo "Done (read-only).\n";
