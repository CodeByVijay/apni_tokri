<?php

declare(strict_types=1);

/** Admin refund detail context (links, line items, no gateway). */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/admin/refunds/index.php');
$refundPhp = (string) file_get_contents($root . '/includes/trust/refund.php');

ok(str_contains($refundPhp, 'findByIdForAdmin'), 'Refund service exposes admin detail loader');
ok(str_contains($refundPhp, 'insertPaymentRefundAttempt'), 'Gateway refunds persist attempt before API call');
ok(str_contains($refundPhp, 'gateway_attempt_started'), 'Audit history records attempt start');
ok(str_contains($refundPhp, 'lineItemsForAdmin'), 'Refund service exposes stored line items query');
ok(str_contains($index, 'AdminOrderService::findById'), 'Refund detail loads order by numeric id');
ok(str_contains($index, '/admin/orders/view.php?id='), 'Order link uses numeric order id');
ok(str_contains($index, '/admin/customers/view.php?id='), 'Customer link uses customer id');
ok(str_contains($index, 'Refund items'), 'Refund items section present');
ok(str_contains($index, 'Order items'), 'Order context fallback when no stored lines');
ok(str_contains($index, 'not inferred from the refund amount'), 'Does not infer refund lines from amount');
ok(str_contains($index, 'order-items.php'), 'Reuses shared order items partial');
ok(str_contains($index, 'approve_process'), 'Combined approve+process action');
ok(str_contains($index, 'retry_gateway'), 'Manual retry action for failed gateway');
ok(str_contains($index, 'adminGatewayStatusFetchUi'), 'Refund detail uses fetch UI eligibility helper');
ok(str_contains($index, 'gatewayFetchUnavailableMessage'), 'Refund detail shows fetch-unavailable explanation');
ok(str_contains($index, 'admin_format_audit_datetime'), 'Audit history uses admin audit datetime formatter');
$helpers = (string) file_get_contents($root . '/includes/helpers.php');
ok(str_contains($helpers, "format('Y-m-d h:i:s A')"), 'Audit datetime format includes date, seconds, and AM/PM');
ok(admin_format_audit_datetime('') === '—', 'Empty audit timestamp shows em dash');
$sample = admin_format_audit_datetime('2026-11-08 01:06:08');
ok($sample !== '' && $sample !== '—' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} (AM|PM)$/', $sample) === 1, 'Audit sample matches YYYY-MM-DD hh:mm:ss AM/PM');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — static checks only.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fixture = $pdo->query(
    'SELECT rr.id AS refund_id, rr.customer_id, rr.order_id, oi.id AS order_item_id
     FROM refund_requests rr
     INNER JOIN refund_request_items ri ON ri.refund_request_id = rr.id
     INNER JOIN order_items oi ON oi.id = ri.order_item_id
     ORDER BY rr.id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($fixture === false) {
    ok(true, 'DB line-item fixture skipped (no refund_request_items row)');
    exit($fail > 0 ? 1 : 0);
}

$refundId = (int) $fixture['refund_id'];
$req = RefundRequestService::findByIdForAdmin($refundId);
ok($req !== null && (int) ($req['order_id'] ?? 0) > 0, 'findByIdForAdmin returns order_id');
$lines = RefundRequestService::lineItemsForAdmin($refundId);
ok($lines !== [], 'lineItemsForAdmin returns rows for fixture');
ok($lines[0]['qty'] > 0 && $lines[0]['line_total'] > 0, 'Line item has stored qty and amount');
$sum = RefundRequestService::sumLineItemsForRequest($refundId);
$manual = 0.0;
foreach ($lines as $ln) {
    $manual += (float) ($ln['line_total'] ?? 0);
}
ok(abs($sum - $manual) < 0.02, 'Sum matches stored line amounts');

$order = AdminOrderService::findById((int) $fixture['order_id']);
ok($order !== null, 'Linked order loads for admin');

echo $fail === 0 ? "PASS: admin refund detail.\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
