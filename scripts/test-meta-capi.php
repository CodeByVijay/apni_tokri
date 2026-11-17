<?php

declare(strict_types=1);

/**
 * Phase 3 Meta CAPI test suite — local dev only.
 *
 * Uses real completePayment() calls against disposable orders and a mocked
 * PaymentHttp handler. No request ever reaches Meta.
 *
 * Run: php scripts/test-meta-capi.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$failures = [];
$count = 0;
function check(bool $cond, string $label): void
{
    global $failures, $count;
    $count++;
    if (!$cond) {
        $failures[] = $label;
        echo "FAIL {$label}\n";
    }
}

$pdo = Database::pdo();

/* ---------- helpers ---------- */

function consentCookie(bool $marketing, ?int $t = null): string
{
    return rtrim(strtr(base64_encode(json_encode([
        'v' => MetaConsent::POLICY_VERSION,
        't' => $t ?? time(),
        'a' => false,
        'm' => $marketing,
    ])), '+/', '-_'), '=');
}

/** Insert a minimal storefront order + items + payment row for completion.
 *  Uses a real customer/address/variant so delivery revalidation passes. */
function makeOrder(PDO $pdo, string $consent, string $orderSource = 'storefront', string $ip = '49.36.1.23'): array
{
    static $seq = 0;
    $seq++;
    $orderNumber = 'TC' . substr((string) (time() % 100000), -5) . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $customerId = 259;           // real customer with valid address 381
    $addressId = 381;            // verified deliverable (zone 1)
    $metaJson = json_encode(['meta_tracking' => [
        'marketing_consent' => $consent,
        'consent_version' => MetaConsent::POLICY_VERSION,
        'captured_at' => date('c'),
        'client_user_agent' => 'Mozilla/5.0 (Test)',
        'client_ip' => $ip,
    ]]);
    $pdo->prepare(
        "INSERT INTO orders (order_number, order_source, customer_id, customer_address_id, status,
         subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot,
         delivery_zone_id, admin_meta_json, placed_at)
         VALUES (?, ?, ?, ?, 'pending_payment', 48, 0, 0, 48, 'pending', 'tomorrow', 1, ?, NULL)"
    )->execute([$orderNumber, $orderSource, $customerId, $addressId, $metaJson]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, sku, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, 3, 'Green Capsicum', '500 g', 'VEG-CAP-002-1', 1, 48.0, 48.0]);
    $idem = 'tidem_' . $orderNumber;
    $pdo->prepare(
        "INSERT INTO payments (order_id, gateway, gateway_order_id, idempotency_key, amount, currency, status)
         VALUES (?, 'cashfree', ?, ?, 48, 'INR', 'pending')"
    )->execute([$orderId, 'cf_ord_' . $orderNumber, $idem]);

    return ['id' => $orderId, 'order_number' => $orderNumber, 'customer_id' => $customerId, 'idempotency' => $idem];
}

function capiRows(PDO $pdo, int $orderId): array
{
    $s = $pdo->prepare('SELECT * FROM meta_capi_events WHERE order_id = ?');
    $s->execute([$orderId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function complete(string $orderNumber, string $gateway, string $idem, float $amount = 48.0): array
{
    // gateway_order_id must match the stored payments row (cf_ord_<num>).
    return PaymentService::completePayment($orderNumber, $gateway, 'pay_' . $orderNumber, 'cf_ord_' . $orderNumber, $amount, 'INR', $idem, 'evt_' . $orderNumber);
}

/* ---------- setup: enable CAPI with test dataset + token ---------- */

$stockBefore = (int) $pdo->query('SELECT stock_qty FROM product_variants WHERE id = 3')->fetchColumn();
// Revalidation zone requires per-variant weight; tests also decrement stock —
// set both temporarily, restored in cleanup.
$pdo->exec('UPDATE product_variants SET weight_kg = 0.5, stock_qty = 100 WHERE id = 3');
AppSettings::set('meta.capi_enabled', '1');
AppSettings::set('meta.dataset_id', '999000111222333');
AppSettings::set('meta.test_event_code', 'TEST99999');
AppSettings::set(MetaAdsSettings::KEY_CAPI_ACCESS_TOKEN, 'TEST_TOKEN_abc123', true);
AppSettings::resetCache();

check(MetaCapi::tableReady(), 'outbox table installed');
check(MetaAdsSettings::capiReady(), 'capiReady with dataset+token');

/* env gate: local dev + veggietokri base → sendable() false; payload/send paths
   still exercised via direct transmit/processDue which gate per-call. */

/* ---------- 1. eligible storefront payment → exactly one outbox event ---------- */
$o1 = makeOrder($pdo, 'granted');
$r = complete($o1['order_number'], 'cashfree', $o1['idempotency']);
check(!empty($r['ok']), 'completePayment ok (eligible)');
$rows = capiRows($pdo, $o1['id']);
check(count($rows) === 1, 'exactly one outbox row for eligible order');
if ($rows) {
    check($rows[0]['status'] === 'pending' || $rows[0]['status'] === 'failed' || $rows[0]['status'] === 'sent',
        'row has valid post-send status');
    check($rows[0]['event_id'] === MetaPixel::purchaseEventId($o1['order_number']),
        'event_id matches browser purchaseEventId (test 19)');
    $payload = json_decode((string) $rows[0]['payload_json'], true);
    check(is_array($payload) && ($payload['event_name'] ?? '') === 'Purchase', 'payload event_name=Purchase');
    check(($payload['action_source'] ?? '') === 'website', 'action_source=website');
    check(($payload['event_id'] ?? '') === MetaPixel::purchaseEventId($o1['order_number']), 'payload event_id stable');
    check(str_contains((string) ($payload['event_source_url'] ?? ''), '/order.php?id='), 'event_source_url present');
    $cd = $payload['custom_data'] ?? [];
    check(($cd['currency'] ?? '') === 'INR' && ($cd['value'] ?? 0) == 48.0, 'custom_data currency+value');
    check(($cd['order_id'] ?? '') === $o1['order_number'], 'custom_data order_id');
    check(in_array('VEG-CAP-002-1', $cd['content_ids'] ?? [], true), 'content_ids has SKU');
    check(($cd['num_items'] ?? 0) === 1, 'num_items correct');
    $ud = $payload['user_data'] ?? [];
    check(isset($ud['ph'][0]) && preg_match('/^[a-f0-9]{64}$/', (string) $ud['ph'][0]) === 1, 'ph hashed sha256');
    $custEmail = $pdo->query('SELECT email FROM customers WHERE id = 259')->fetchColumn();
    $emOk = $custEmail !== false && $custEmail !== null && $custEmail !== ''
        ? (isset($ud['em'][0]) && preg_match('/^[a-f0-9]{64}$/', (string) $ud['em'][0]) === 1)
        : !isset($ud['em']);
    check($emOk, 'em hashed sha256 (or correctly absent when email is NULL)');
    check(($ud['client_ip_address'] ?? '') === '49.36.1.23', 'public client_ip captured');
    check(($ud['client_user_agent'] ?? '') === 'Mozilla/5.0 (Test)', 'client_user_agent captured');
    $payloadStr = (string) $rows[0]['payload_json'];
    $custMobile = (string) $pdo->query('SELECT mobile FROM customers WHERE id = 259')->fetchColumn();
    check(!str_contains($payloadStr, $custMobile) && !str_contains($payloadStr, '@'),
        'no raw mobile/email in payload (test 21)');
    check(!str_contains($payloadStr, 'TEST_TOKEN'), 'no token in payload (test 20)');
}

/* ---------- private/reserved IP is never sent (review item 6) ---------- */
$oIp = makeOrder($pdo, 'granted', 'storefront', '10.0.0.5');
complete($oIp['order_number'], 'cashfree', $oIp['idempotency']);
$rowIp = capiRows($pdo, $oIp['id'])[0] ?? null;
$udIp = json_decode((string) ($rowIp['payload_json'] ?? ''), true)['user_data'] ?? [];
check(!isset($udIp['client_ip_address']), 'private client_ip omitted from payload (item 6)');
// capture side: REMOTE_ADDR is unset in CLI → snapshot must carry null, not a guess
$snapT = json_decode(MetaCapi::checkoutTrackingSnapshot(), true);
check(array_key_exists('client_ip', $snapT['meta_tracking'] ?? [])
    && $snapT['meta_tracking']['client_ip'] === null,
    'untrusted REMOTE_ADDR → client_ip null');

/* ---------- 2. second completePayment → no duplicate ---------- */
$r2 = complete($o1['order_number'], 'cashfree', $o1['idempotency']);
check(!empty($r2['ok']) && !empty($r2['idempotent']), 'second call idempotent');
check(count(capiRows($pdo, $o1['id'])) === 1, 'no duplicate event after idempotent re-entry (tests 2,4,5)');

/* ---------- 6/7/8. pending / failed / cancelled → no event ---------- */
$oP = makeOrder($pdo, 'granted');
check(count(capiRows($pdo, $oP['id'])) === 0, 'pending order → no event (test 6)');
$oidC = (int) $pdo->query("SELECT id FROM orders WHERE order_number='{$oP['order_number']}'")->fetchColumn();
$pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oidC]);
$rc = complete($oP['order_number'], 'cashfree', $oP['idempotency']);
check(empty($rc['ok']), 'cancelled order → completion rejected');
check(count(capiRows($pdo, $oidC)) === 0, 'cancelled order → no event (test 8)');

$oF = makeOrder($pdo, 'granted');
PaymentService::markPaymentFailed($oF['order_number'], $oF['idempotency'], 'test failure');
check(count(capiRows($pdo, $oF['id'])) === 0, 'failed payment → no event (test 7)');

/* ---------- 9/10/11. offline / simulate / subscription ---------- */
$oOff = makeOrder($pdo, 'granted');
$rOff = complete($oOff['order_number'], 'offline', $oOff['idempotency']);
check(!empty($rOff['ok']), 'offline completion succeeds');
check(count(capiRows($pdo, $oOff['id'])) === 0, 'offline gateway → no event (test 9)');

$oSim = makeOrder($pdo, 'granted');
$rSim = complete($oSim['order_number'], 'simulate', $oSim['idempotency']);
check(!empty($rSim['ok']), 'simulate completion succeeds');
check(count(capiRows($pdo, $oSim['id'])) === 0, 'simulate gateway → no event (test 10)');

$oSub = makeOrder($pdo, 'granted', 'subscription');
$rSub = complete($oSub['order_number'], 'cashfree', $oSub['idempotency']);
check(!empty($rSub['ok']), 'subscription-source completion succeeds');
check(count(capiRows($pdo, $oSub['id'])) === 0, 'subscription order → no event (test 11)');

/* ---------- 12/13. consent denied / absent ---------- */
$oDen = makeOrder($pdo, 'denied');
complete($oDen['order_number'], 'cashfree', $oDen['idempotency']);
check(count(capiRows($pdo, $oDen['id'])) === 0, 'consent denied → no event (test 12)');

$oAbs = makeOrder($pdo, 'absent');
complete($oAbs['order_number'], 'cashfree', $oAbs['idempotency']);
check(count(capiRows($pdo, $oAbs['id'])) === 0, 'consent absent → no event (test 13)');

/* ---------- 15. withdrawal suppression via observeVisitorConsent ---------- */
/* The exact review scenario, simulated as one session:
   A) session start, consent not yet answered  → observer sweeps, stores sig
   B) customer grants marketing consent         → early return (nothing to do)
   C) purchase completes                        → pending outbox event
   D) customer withdraws in the SAME session    → new state signature → sweep
   E) re-grant → another purchase → withdraw    → must suppress AGAIN
   Under the old once-per-session flag, step D was silently missed. */
$_SESSION['customer_id'] = 259;
unset($_SESSION['meta_capi_suppress_sig'], $_SESSION['meta_capi_suppress_swept']);

// A) absent consent observed first
unset($_COOKIE['at_consent']);
MetaCapi::observeVisitorConsent();
check(!empty($_SESSION['meta_capi_suppress_sig']), 'absent consent observed, signature stored');

// B) grant → observer early-returns (allowed), signature unchanged
$_COOKIE['at_consent'] = consentCookie(true);
MetaCapi::observeVisitorConsent();

// C) purchase → pending event
$oW = makeOrder($pdo, 'granted');
complete($oW['order_number'], 'cashfree', $oW['idempotency']);
$rowW = capiRows($pdo, $oW['id'])[0] ?? null;
check($rowW && $rowW['status'] === 'pending', 'pre-withdrawal event pending');

// D) same-session withdrawal — a NEW signature, must still be swept
$_COOKIE['at_consent'] = consentCookie(false, time() - 300);
check(MetaConsent::marketingAllowed() === false, 'withdrawn state reads as denied');
MetaCapi::observeVisitorConsent();
$rowW = capiRows($pdo, $oW['id'])[0];
check($rowW['status'] === 'suppressed', 'same-session withdrawal suppresses pending event (test 15)');

// suppressed payload is scrubbed — hashed ids/IP/UA gone, audit stub remains
$pW = json_decode((string) $rowW['payload_json'], true);
check(isset($pW['scrubbed']) && !isset($pW['user_data']), 'suppressed payload scrubbed to audit stub (item 3)');

// E) re-grant → second purchase → withdraw again → suppressed again
$_COOKIE['at_consent'] = consentCookie(true);
MetaCapi::observeVisitorConsent();
$oW2 = makeOrder($pdo, 'granted');
complete($oW2['order_number'], 'cashfree', $oW2['idempotency']);
check((capiRows($pdo, $oW2['id'])[0]['status'] ?? '') === 'pending', 'second purchase pending after re-grant');
$_COOKIE['at_consent'] = consentCookie(false); // new recorded_at → new signature
MetaCapi::observeVisitorConsent();
check((capiRows($pdo, $oW2['id'])[0]['status'] ?? '') === 'suppressed', 'repeat withdrawal suppresses again');
unset($_SESSION['customer_id'], $_COOKIE['at_consent'], $_SESSION['meta_capi_suppress_sig']);

/* ---------- 16/17/18/22/23. send path via mock ---------- */
$oS = makeOrder($pdo, 'granted');
complete($oS['order_number'], 'cashfree', $oS['idempotency']);
$rowS = capiRows($pdo, $oS['id'])[0] ?? null;
check($rowS !== null && $rowS['status'] === 'pending', 'event pending before send');

// CAPI disabled → no send (test 23): capiReady false blocks processDue caller;
// simulate by disabling and confirming sendable gate via stats/flag
AppSettings::set('meta.capi_enabled', '0');
AppSettings::resetCache();
check(!MetaAdsSettings::capiReady(), 'capi disabled → not ready (test 23)');
AppSettings::set('meta.capi_enabled', '1');
AppSettings::resetCache();

// Meta timeout → payment already succeeded; event retryable (test 16/17)
PaymentHttp::$testHandler = function () {
    return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'timeout'];
};
$res = MetaCapi::processDue(5);
$rowS2 = capiRows($pdo, $oS['id'])[0];
check($rowS2['status'] === 'failed' && (int) $rowS2['attempts'] === 1, 'timeout → failed, attempt counted (test 16)');
check($rowS2['next_attempt_at'] !== null, 'retryable failure schedules backoff');
check($rowS2['last_error'] !== null && !str_contains((string) $rowS2['last_error'], 'TEST_TOKEN'), 'error sanitized (test 20)');

// lease exclusivity: claim same row twice → second denied (test 18)
$rp = new ReflectionClass('MetaCapi');
$m = $rp->getMethod('claim');
$m->setAccessible(true);
$t1 = $m->invoke(null, $pdo, (int) $rowS2['id'], true);
$t2 = $m->invoke(null, $pdo, (int) $rowS2['id'], true);
check($t1 !== null && $t2 === null, 'second concurrent claim denied (test 18)');
// release lease back to failed for further tests
$pdo->prepare("UPDATE meta_capi_events SET status='failed', worker_token=NULL, processing_started_at=NULL WHERE id=?")
    ->execute([(int) $rowS2['id']]);

// Meta API error (400) → non-retryable terminal; requeue first (backoff was set)
$pdo->prepare('UPDATE meta_capi_events SET next_attempt_at = NULL WHERE id = ?')->execute([(int) $rowS2['id']]);
PaymentHttp::$testHandler = function () {
    return ['ok' => false, 'status' => 400, 'body' => '{"error":{"message":"Invalid parameter"}}',
        'json' => ['error' => ['message' => 'Invalid parameter']], 'error' => null];
};
MetaCapi::processDue(5);
$rowS3 = capiRows($pdo, $oS['id'])[0];
check($rowS3['status'] === 'failed' && $rowS3['next_attempt_at'] === null, 'Meta 400 → terminal failed (test 17)');

// success path: retryFromAdmin is env-gated — business.website_url takes
// precedence in storefrontBaseUrl(), so set the authorized staging host live.
$calls = [];
PaymentHttp::$testHandler = function ($method, $url, $body, $headers) use (&$calls) {
    $calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
    return ['ok' => true, 'status' => 200, 'body' => '{"events_received":1,"messages":[]}',
        'json' => ['events_received' => 1], 'error' => null];
};
$prevUrl = AppSettings::get('business.website_url');
AppSettings::set('business.website_url', 'https://apnitokri.manconnect.club');
AppSettings::resetCache();
try {
    check(MetaPixel::environmentAllowed(), 'env allowed under staging host for retry test');
    $retry = MetaCapi::retryFromAdmin(1);
} finally {
    AppSettings::set('business.website_url', $prevUrl === null ? null : (string) $prevUrl);
    AppSettings::resetCache();
}
$rowS4 = capiRows($pdo, $oS['id'])[0];
check(isset($retry) && !empty($retry['ok']), 'retryFromAdmin returned ok');
check($rowS4['status'] === 'sent' && $rowS4['sent_at'] !== null, 'admin retry sends event');

// sent payload is scrubbed to a non-sensitive audit stub (item 3)
$pSent = json_decode((string) $rowS4['payload_json'], true);
check(isset($pSent['scrubbed']) && !isset($pSent['user_data']), 'sent payload scrubbed after delivery (item 3)');

// suppressed rows are never requeued or transmitted by admin retry (item 5)
$sentOrders = array_map(
    fn($c) => (string) ($c['body']['data'][0]['custom_data']['order_id'] ?? ''),
    $calls
);
check(!in_array($oW['order_number'], $sentOrders, true) && !in_array($oW2['order_number'], $sentOrders, true),
    'suppressed events never transmitted during admin retry (item 5)');
$supCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM meta_capi_events WHERE order_id IN ({$oW['id']},{$oW2['id']}) AND status='suppressed'"
)->fetchColumn();
check($supCount === 2, 'suppressed rows untouched by retry requeue (item 5)');

$captured = end($calls) ?: null;
check($captured !== null && $captured['method'] === 'POST', 'POST request');
check(str_contains((string) $captured['url'], 'graph.facebook.com/'), 'graph.facebook.com host (not .net)');
check(str_contains((string) $captured['url'], '/' . MetaCapi::GRAPH_API_VERSION . '/999000111222333/events'), 'versioned dataset endpoint');
check(($captured['body']['test_event_code'] ?? '') === 'TEST99999', 'test_event_code sent');
check(is_array($captured['body']['data'] ?? null) && count($captured['body']['data']) === 1, 'single data event');
check(str_contains(implode(' ', $captured['headers']), 'Bearer'), 'bearer auth header');
check(!str_contains((string) $captured['url'], 'TEST_TOKEN') && !str_contains(json_encode($captured['body']), 'TEST_TOKEN'), 'token not in URL/body');

// already-sent row: processDue must skip it (no duplicate send)
$calls = [];
$res = MetaCapi::processDue(5);
check($calls === [], 'sent row not re-transmitted');

/* ---------- 14. granted consent → event exists (covered by o1/oS) ---------- */
check(count(capiRows($pdo, $o1['id'])) === 1, 'consent granted → eligible event (test 14)');

/* ---------- missing token/dataset → no send (test 22) ---------- */
$oT = makeOrder($pdo, 'granted');
complete($oT['order_number'], 'cashfree', $oT['idempotency']);
AppSettings::set('meta.dataset_id', null);
AppSettings::resetCache();
PaymentHttp::$testHandler = function () use (&$captured) {
    $captured = 'CALLED';
    return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => [], 'error' => null];
};
$captured = null;
MetaCapi::processDue(5);
$rowT = capiRows($pdo, $oT['id'])[0];
check($captured === null && $rowT['status'] === 'failed', 'missing dataset → no HTTP call, marked failed (test 22)');
AppSettings::set('meta.dataset_id', '999000111222333');
AppSettings::resetCache();

/* ---------- enqueue failure: payment survives, error is visible (item 4) ---------- */
/* Renaming the outbox table makes the INSERT throw (1146 — INSERT IGNORE only
   downgrades data errors). tableReady() is already cached true in-process, so
   enqueuePurchase reaches the INSERT and its catch must contain the failure
   inside the transaction and record an audit-trail error. */
$pdo->exec('RENAME TABLE meta_capi_events TO meta_capi_events_bak');
$oE = null;
$rE = [];
try {
    $oE = makeOrder($pdo, 'granted');
    $rE = complete($oE['order_number'], 'cashfree', $oE['idempotency']);
} finally {
    $pdo->exec('RENAME TABLE meta_capi_events_bak TO meta_capi_events');
}
check(!empty($rE['ok']), 'payment succeeds despite outbox insert failure (item 4)');
check($oE !== null && count(capiRows($pdo, $oE['id'])) === 0, 'failed enqueue leaves no outbox row');
$audit = $pdo->prepare(
    "SELECT payload_json FROM admin_audit_logs WHERE action = 'meta.capi.enqueue_failed'
     AND entity_id = ? ORDER BY id DESC LIMIT 1"
);
$audit->execute([$oE['order_number']]);
$auditRow = $audit->fetch(PDO::FETCH_ASSOC);
check($auditRow !== false, 'enqueue failure recorded in admin audit log (item 4)');
check($auditRow !== false && !str_contains((string) $auditRow['payload_json'], 'TEST_TOKEN'), 'enqueue audit sanitized (item 4)');
$st4 = MetaCapi::stats();
check(($st4['enqueue_failures'] ?? 0) >= 1 && !empty($st4['last_enqueue_failure']),
    'enqueue failure visible in admin status stats (item 4)');

/* ---------- retention: stale unsent event terminalized + scrubbed ---------- */
$oSt = makeOrder($pdo, 'granted');
complete($oSt['order_number'], 'cashfree', $oSt['idempotency']);
$pdo->prepare("UPDATE meta_capi_events SET created_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE order_id = ?")
    ->execute([$oSt['id']]);
$calls = [];
PaymentHttp::$testHandler = function ($method, $url, $body, $headers) use (&$calls) {
    $calls[] = ['url' => $url, 'body' => $body];
    return ['ok' => true, 'status' => 200, 'body' => '{"events_received":1}', 'json' => ['events_received' => 1], 'error' => null];
};
MetaCapi::processDue(5);
$rowSt = capiRows($pdo, $oSt['id'])[0];
check($rowSt['status'] === 'failed' && (int) $rowSt['attempts'] === (int) $rowSt['max_attempts']
    && $rowSt['next_attempt_at'] === null, 'stale unsent event terminalized (item 3)');
$pSt = json_decode((string) $rowSt['payload_json'], true);
check(isset($pSt['scrubbed']) && !isset($pSt['user_data']), 'stale payload scrubbed (item 3)');
check($calls === [], 'stale event never transmitted (item 3)');

/* ---------- cleanup ---------- */
PaymentHttp::$testHandler = null;
$orderIds = [$o1['id'], $oIp['id'], $oP['id'], $oF['id'], $oOff['id'], $oSim['id'], $oSub['id'],
    $oDen['id'], $oAbs['id'], $oW['id'], $oW2['id'], $oS['id'], $oT['id'], $oE['id'] ?? 0, $oSt['id']];
foreach ($orderIds as $id) {
    $pdo->prepare('DELETE FROM meta_capi_events WHERE order_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM payments WHERE order_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM order_status_history WHERE order_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);
}
AppSettings::set('meta.capi_enabled', '0');
AppSettings::set('meta.dataset_id', null);
AppSettings::set('meta.test_event_code', null);
AppSettings::set('business.website_url', $prevUrl ?? null);
$pdo->exec('DELETE FROM settings WHERE setting_key = \'meta.capi_access_token\'');
$pdo->exec("DELETE FROM admin_audit_logs WHERE action = 'meta.capi.enqueue_failed'");
AppSettings::resetCache();
$pdo->prepare('UPDATE product_variants SET stock_qty = ?, weight_kg = NULL WHERE id = 3')->execute([$stockBefore]);

echo "\n" . ($failures === [] ? "ALL {$count} CHECKS PASSED" : count($failures) . " FAILURES") . "\n";
exit($failures === [] ? 0 : 1);
