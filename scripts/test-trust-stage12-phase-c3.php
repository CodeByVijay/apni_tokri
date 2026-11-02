<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage12_phase_c3_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply database/migrations/1.8.0_stage12_c3_webhook_lease.sql (expected '
        . Install::SCHEMA_VERSION . ', got ' . (Install::installedSchemaVersion() ?? 'null') . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

stage12_c3_cleanup($pdo);

$secret = 'c3_whsec_' . bin2hex(random_bytes(4));
AppSettings::set('cashfree.webhook_secret', $secret, true);
AppSettings::set('cashfree.secret_key', 'c3_client_secret', true);
AppSettings::set('cashfree.enabled', '1');
$ts = (string) time();

$fixture = stage12_c3_seed_order_refund($pdo, 'A');
$payloadSuccess = stage12_c3_refund_payload($fixture, 'SUCCESS');
$rawSuccess = json_encode($payloadSuccess, JSON_UNESCAPED_UNICODE);
$sigOk = stage12_c3_sign($rawSuccess, $secret, $ts);

ok(CashfreeGateway::verifyWebhookSignature($rawSuccess, $sigOk, $ts), 'Valid Cashfree signature');
ok(!CashfreeGateway::verifyWebhookSignature($rawSuccess, 'bad', $ts), 'Invalid signature rejected');
ok(!CashfreeGateway::verifyWebhookSignature($rawSuccess, $sigOk, ''), 'Missing timestamp rejected');
ok($sigOk !== '' && !CashfreeGateway::verifyWebhookSignature($rawSuccess, '', $ts), 'Missing signature rejected');

$eventId = CashfreeRefundWebhook::buildEventId($payloadSuccess);
ok(strpos($eventId, 'cashfree:refund:') === 0, 'Refund event id uses stable refund identity');
ok(strpos($eventId, 'cf_ref_c3_A') !== false, 'Event id includes cf_refund_id');

$pendingPayload = stage12_c3_refund_payload($fixture, 'PENDING', ['event_time' => '2026-11-01T12:01:00+05:30']);
ok(
    CashfreeRefundWebhook::buildEventId($pendingPayload) !== $eventId,
    'Pending update has distinct event id from success'
);

$resSuccess = RefundWebhookService::handleCashfree($payloadSuccess, $eventId);
ok($resSuccess['ok'], 'Successful refund event completes');
$st = $pdo->prepare('SELECT gateway_status FROM payment_refunds WHERE id = ?');
$st->execute([$fixture['payment_refund_id']]);
ok(RefundCompletionService::isTerminalSuccess((string) $st->fetchColumn()), 'Refund row terminal after success');

$fixtureB = stage12_c3_seed_order_refund($pdo, 'B');
$failPayload = stage12_c3_refund_payload($fixtureB, 'FAILED');
$resFail = RefundWebhookService::handleCashfree($failPayload, CashfreeRefundWebhook::buildEventId($failPayload));
ok($resFail['ok'], 'Failed refund event handled');
$st->execute([$fixtureB['payment_refund_id']]);
ok((string) $st->fetchColumn() === 'failed', 'Failed status stored');

$fixtureC = stage12_c3_seed_order_refund($pdo, 'C');
$cancelPayload = stage12_c3_refund_payload($fixtureC, 'CANCELLED');
RefundWebhookService::handleCashfree($cancelPayload, CashfreeRefundWebhook::buildEventId($cancelPayload));
$st->execute([$fixtureC['payment_refund_id']]);
ok((string) $st->fetchColumn() === 'failed', 'Cancelled refund treated as terminal failure');

$fixtureD = stage12_c3_seed_order_refund($pdo, 'D');
$pendPayload = stage12_c3_refund_payload($fixtureD, 'PENDING');
RefundWebhookService::handleCashfree($pendPayload, CashfreeRefundWebhook::buildEventId($pendPayload));
$st->execute([$fixtureD['payment_refund_id']]);
ok((string) $st->fetchColumn() === 'pending', 'Pending does not complete refund');

$fixtureE = stage12_c3_seed_order_refund($pdo, 'E');
$unkPayload = stage12_c3_refund_payload($fixtureE, 'WEIRD_STATUS_X');
RefundWebhookService::handleCashfree($unkPayload, CashfreeRefundWebhook::buildEventId($unkPayload));
$st->execute([$fixtureE['payment_refund_id']]);
ok((string) $st->fetchColumn() === 'unknown', 'Unknown status does not complete refund');

$unknownPayload = stage12_c3_refund_payload($fixture, 'SUCCESS', [
    'refund' => ['cf_refund_id' => 'cf_ref_unknown_c3', 'refund_id' => 'unknown_merchant_ref_c3'],
]);
$unknown = RefundWebhookService::handleCashfree($unknownPayload, CashfreeRefundWebhook::buildEventId($unknownPayload));
ok($unknown['ok'] === false && !empty($unknown['retryable']), 'Unknown refund id retryable');

$fixtureF = stage12_c3_seed_order_refund($pdo, 'F');
$badMerchant = stage12_c3_refund_payload($fixtureF, 'SUCCESS', ['refund' => ['refund_id' => 'wrong_merchant_id']]);
$badM = RefundWebhookService::handleCashfree($badMerchant, CashfreeRefundWebhook::buildEventId($badMerchant));
ok($badM['ok'] === false && empty($badM['retryable']), 'Incorrect merchant refund id rejected');

$fixtureG = stage12_c3_seed_order_refund($pdo, 'G');
$badOrder = stage12_c3_refund_payload($fixtureG, 'SUCCESS', ['refund' => ['order_id' => 'AT-C3-WRONG']]);
$badO = RefundWebhookService::handleCashfree($badOrder, CashfreeRefundWebhook::buildEventId($badOrder));
ok($badO['ok'] === false, 'Incorrect merchant order id rejected');

$fixtureH = stage12_c3_seed_order_refund($pdo, 'H');
$badAmt = stage12_c3_refund_payload($fixtureH, 'SUCCESS', ['refund' => ['refund_amount' => 999.0]]);
$badA = RefundWebhookService::handleCashfree($badAmt, CashfreeRefundWebhook::buildEventId($badAmt));
ok($badA['ok'] === false, 'Amount mismatch rejected');

$fixtureI = stage12_c3_seed_order_refund($pdo, 'I');
$badCur = stage12_c3_refund_payload($fixtureI, 'SUCCESS', ['refund' => ['refund_currency' => 'USD']]);
$badC = RefundWebhookService::handleCashfree($badCur, CashfreeRefundWebhook::buildEventId($badCur));
ok($badC['ok'] === false, 'Currency mismatch rejected');

$fixtureJ = stage12_c3_seed_order_refund($pdo, 'J');
$badPay = stage12_c3_refund_payload($fixtureJ, 'SUCCESS', ['refund' => ['cf_payment_id' => 'wrong_pay']]);
$badP = RefundWebhookService::handleCashfree($badPay, CashfreeRefundWebhook::buildEventId($badPay));
ok($badP['ok'] === false, 'Incorrect payment mapping rejected');

$retryEvent = 'cashfree:c3:retry_' . Security::randomToken(3);
$begin1 = PaymentService::beginWebhookEvent('cashfree', $retryEvent, $rawSuccess);
ok($begin1['action'] === 'process', 'Webhook event insert for retry test');
PaymentService::finishWebhookEvent('cashfree', $retryEvent, false, 'simulated failure');
$begin2 = PaymentService::beginWebhookEvent('cashfree', $retryEvent, $rawSuccess);
ok($begin2['action'] === 'process', 'Failed webhook event eligible for retry');
PaymentService::finishWebhookEvent('cashfree', $retryEvent, true, null);
$begin3 = PaymentService::beginWebhookEvent('cashfree', $retryEvent, $rawSuccess);
ok($begin3['action'] === 'duplicate_success', 'Processed webhook not re-run');

$dupEvent = 'cashfree:c3:dup_' . Security::randomToken(3);
ok(PaymentService::beginWebhookEvent('cashfree', $dupEvent, $rawSuccess)['action'] === 'process', 'First duplicate test insert');
PaymentService::finishWebhookEvent('cashfree', $dupEvent, true, null);
ok(PaymentService::beginWebhookEvent('cashfree', $dupEvent, $rawSuccess)['action'] === 'duplicate_success', 'Exact duplicate delivery skipped');

$fixtureK = stage12_c3_seed_order_refund($pdo, 'K');
$payloadK = stage12_c3_refund_payload($fixtureK, 'SUCCESS', ['event_time' => '2026-11-01T13:00:00+05:30']);
RefundWebhookService::handleCashfree($payloadK, CashfreeRefundWebhook::buildEventId($payloadK));
$st->execute([$fixtureK['payment_refund_id']]);
$firstStatus = (string) $st->fetchColumn();
RefundWebhookService::handleCashfree($payloadK, CashfreeRefundWebhook::buildEventId($payloadK));
$st->execute([$fixtureK['payment_refund_id']]);
ok($firstStatus === (string) $st->fetchColumn(), 'Refund completion idempotent on repeat success');

$path = CashfreeGateway::ordersRefundApiPath($fixture['order_number']);
ok(strpos($path, '/orders/' . rawurlencode($fixture['order_number']) . '/refunds') !== false, 'Order refund API uses merchant order_number');

$rgSrc = (string) file_get_contents(dirname(__DIR__) . '/includes/payment/refund_gateway.php');
ok(strpos($rgSrc, 'SELECT order_number FROM orders') !== false, 'Order refund initiation resolves merchant order_number');
ok(strpos($rgSrc, 'subscription_number') !== false, 'Subscription Cashfree refund uses subscription_number path');

$rzRaw = '{"event":"refund.processed","payload":{"refund":{"entity":{"id":"rf_c3_1","amount":10000,"status":"processed","notes":{"idempotency_key":"missing_c3"}}}}}';
ok(RefundWebhookService::handleRazorpay(json_decode($rzRaw, true) ?: [], 'c3_rz')['ok'] === false, 'Razorpay regression unknown refund safe');

try {
    json_decode('{not json', true, 512, JSON_THROW_ON_ERROR);
    ok(false, 'Malformed JSON should throw');
} catch (Throwable $e) {
    ok(true, 'Malformed JSON throws JSON exception');
}

$inFlight = 'cashfree:c3:inflight_' . Security::randomToken(2);
PaymentService::beginWebhookEvent('cashfree', $inFlight, $rawSuccess);
$inFlightToken = PaymentService::markWebhookEventProcessing('cashfree', $inFlight);
ok($inFlightToken !== null, 'Processing claim returns worker token');
$flight = PaymentService::beginWebhookEvent('cashfree', $inFlight, $rawSuccess);
ok($flight['action'] === 'in_flight', 'Concurrent duplicate while processing returns in_flight');
ok(
    PaymentService::markWebhookEventProcessing('cashfree', $inFlight) === null,
    'Second worker cannot claim active processing event'
);
ok(PaymentService::finishWebhookEvent('cashfree', $inFlight, true, null, $inFlightToken), 'Owner finishes in-flight event');

$leaseEvent = 'cashfree:c3:lease_active_' . Security::randomToken(2);
PaymentService::beginWebhookEvent('cashfree', $leaseEvent, $rawSuccess);
$leaseToken = PaymentService::markWebhookEventProcessing('cashfree', $leaseEvent);
ok($leaseToken !== null, 'Active lease claim');
$leaseRetry = PaymentService::beginWebhookEvent('cashfree', $leaseEvent, $rawSuccess);
ok($leaseRetry['action'] === 'in_flight', 'Active processing lease cannot be reclaimed');
PaymentService::finishWebhookEvent('cashfree', $leaseEvent, true, null, $leaseToken);

$expiredEvent = 'cashfree:c3:lease_exp_' . Security::randomToken(2);
PaymentService::beginWebhookEvent('cashfree', $expiredEvent, $rawSuccess);
$staleToken = PaymentService::markWebhookEventProcessing('cashfree', $expiredEvent);
ok($staleToken !== null, 'Expired lease setup claim');
$pdo->prepare(
    'UPDATE payment_webhook_events SET processing_started_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE)
     WHERE gateway = ? AND event_id = ?'
)->execute(['cashfree', $expiredEvent]);
$reclaim = PaymentService::beginWebhookEvent('cashfree', $expiredEvent, $rawSuccess);
ok($reclaim['action'] === 'process', 'Expired processing lease can be reclaimed');
$newToken = PaymentService::markWebhookEventProcessing('cashfree', $expiredEvent);
ok($newToken !== null && $newToken !== $staleToken, 'New worker receives new token after reclaim');
ok(
    !PaymentService::finishWebhookEvent('cashfree', $expiredEvent, true, null, $staleToken),
    'Stale worker cannot finish reclaimed event'
);
ok(PaymentService::finishWebhookEvent('cashfree', $expiredEvent, true, null, $newToken), 'New owner can finish event');

$processedLock = 'cashfree:c3:proc_lock_' . Security::randomToken(2);
PaymentService::beginWebhookEvent('cashfree', $processedLock, $rawSuccess);
$procTok = PaymentService::markWebhookEventProcessing('cashfree', $processedLock);
PaymentService::finishWebhookEvent('cashfree', $processedLock, true, null, $procTok);
ok(
    PaymentService::beginWebhookEvent('cashfree', $processedLock, $rawSuccess)['action'] === 'duplicate_success',
    'Processed event cannot be reclaimed'
);

$fixtureRej = stage12_c3_seed_order_refund($pdo, 'REJ');
$rejPayload = stage12_c3_refund_payload($fixtureRej, 'REJECTED');
RefundWebhookService::handleCashfree($rejPayload, CashfreeRefundWebhook::buildEventId($rejPayload));
$st->execute([$fixtureRej['payment_refund_id']]);
ok((string) $st->fetchColumn() === 'failed', 'REJECTED triggers failure workflow');

$fixturePa = stage12_c3_seed_order_refund($pdo, 'PA');
$paPayload = stage12_c3_refund_payload($fixturePa, 'PENDING_APPROVAL');
RefundWebhookService::handleCashfree($paPayload, CashfreeRefundWebhook::buildEventId($paPayload));
$st->execute([$fixturePa['payment_refund_id']]);
$paStatus = (string) $st->fetchColumn();
ok($paStatus === 'pending_approval' && !RefundCompletionService::isTerminalSuccess($paStatus), 'PENDING_APPROVAL remains non-terminal');

$fixtureOh = stage12_c3_seed_order_refund($pdo, 'OH');
$ohPayload = stage12_c3_refund_payload($fixtureOh, 'ONHOLD');
RefundWebhookService::handleCashfree($ohPayload, CashfreeRefundWebhook::buildEventId($ohPayload));
$st->execute([$fixtureOh['payment_refund_id']]);
$ohStatus = (string) $st->fetchColumn();
ok($ohStatus === 'onhold' && !RefundCompletionService::isTerminalSuccess($ohStatus), 'ONHOLD remains non-terminal');

foreach (['PROCESSED', 'COMPLETED', 'REFUNDED'] as $aliasStatus) {
    $fx = stage12_c3_seed_order_refund($pdo, substr($aliasStatus, 0, 3));
    $aliasPayload = stage12_c3_refund_payload($fx, $aliasStatus, [
        'event_time' => '2026-11-01T14:' . substr(md5($aliasStatus), 0, 2) . ':00+05:30',
    ]);
    RefundWebhookService::handleCashfree($aliasPayload, CashfreeRefundWebhook::buildEventId($aliasPayload));
    $st->execute([$fx['payment_refund_id']]);
    $gw = (string) $st->fetchColumn();
    ok($gw === 'unknown' && !RefundCompletionService::isTerminalSuccess($gw), $aliasStatus . ' cannot complete refund');
}

$fixtureSuccOnly = stage12_c3_seed_order_refund($pdo, 'SU');
$succOnlyPayload = stage12_c3_refund_payload($fixtureSuccOnly, 'SUCCESS', ['event_time' => '2026-11-01T15:00:00+05:30']);
RefundWebhookService::handleCashfree($succOnlyPayload, CashfreeRefundWebhook::buildEventId($succOnlyPayload));
$st->execute([$fixtureSuccOnly['payment_refund_id']]);
ok(RefundCompletionService::isTerminalSuccess((string) $st->fetchColumn()), 'SUCCESS remains documented terminal success');

$longId = str_repeat('x', 220);
$longFixture = stage12_c3_seed_order_refund($pdo, 'LG');
$longPayload = stage12_c3_refund_payload($longFixture, 'PENDING', [
    'refund' => ['cf_refund_id' => $longId],
    'event_time' => '2026-11-01T16:00:00+05:30',
]);
$longEventId = CashfreeRefundWebhook::buildEventId($longPayload);
ok(strlen($longEventId) <= 191, 'Event IDs remain within 191 characters');
ok(strpos($longEventId, 'cashfree:ev:') === 0, 'Long event IDs use deterministic full hash');
$longPayload2 = stage12_c3_refund_payload($longFixture, 'PENDING', [
    'refund' => ['cf_refund_id' => $longId],
    'event_time' => '2026-11-01T16:00:00+05:30',
]);
ok(
    CashfreeRefundWebhook::buildEventId($longPayload2) === $longEventId,
    'Exact duplicate long event IDs are identical'
);
$longPayload3 = stage12_c3_refund_payload($longFixture, 'SUCCESS', [
    'refund' => ['cf_refund_id' => $longId],
    'event_time' => '2026-11-01T16:00:00+05:30',
]);
ok(
    CashfreeRefundWebhook::buildEventId($longPayload3) !== $longEventId,
    'Different refund statuses produce different IDs for long keys'
);

stage12_c3_cleanup($pdo);

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 12 Phase C.3 Cashfree refund webhook hardening.\n";
exit(0);
