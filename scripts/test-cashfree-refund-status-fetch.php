<?php

declare(strict_types=1);

/**
 * Cashfree refund status GET (mocked HTTP; no live API).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — skipped (start MySQL and re-run).\n");
    exit(0);
}

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'cf_test_id_12345678');
AppSettings::set('cashfree.secret_key', 'cf_test_secret_key_value', true);
AppSettings::set('payment.test_mode', '1');
AppSettings::resetCache();

$orderNo = 'AT-CF-REF-TEST';
$merchantRefundId = 'refund_req_2';
$pathSuffix = '/orders/' . rawurlencode($orderNo) . '/refunds/' . rawurlencode($merchantRefundId);

$seenHeaders = [];
$prev = PaymentHttp::$testHandler;
PaymentHttp::$testHandler = static function (string $method, string $url, ?array $body, array $headers = []) use ($pathSuffix, &$seenHeaders): array {
    $seenHeaders = $headers;
    if ($method !== 'GET' || !str_ends_with($url, $pathSuffix)) {
        return ['ok' => false, 'status' => 500, 'body' => '', 'json' => null];
    }

    return [
        'ok' => true,
        'status' => 200,
        'body' => '{}',
        'json' => [
            'refund_id' => 'refund_req_2',
            'cf_refund_id' => '155230244',
            'refund_status' => 'PENDING',
            'refund_amount' => 5,
        ],
    ];
};

$resp = CashfreeGateway::fetchRefund($orderNo, $merchantRefundId);
$hdr = implode("\n", $seenHeaders);
$ok(isset($seenHeaders[0]) && str_contains($seenHeaders[0], 'x-client-id'), 'Auth headers sent');
$ok(str_contains($hdr, 'x-api-version: 2023-08-01'), 'API version header');
$ok(!empty($resp['ok']), 'Pending refund fetch succeeds');
$ok(($resp['refund_status'] ?? '') === 'PENDING', 'Pending status parsed');
$ok(($resp['gateway_refund_id'] ?? '') === '155230244', 'cf_refund_id from response');

PaymentHttp::$testHandler = $prev;

$cases = [
    401 => 'credentials',
    403 => 'credentials',
    404 => 'not found',
    429 => 'rate-limited',
    502 => 'server error',
    503 => 'server error',
];
foreach ($cases as $http => $needle) {
    PaymentHttp::$testHandler = static function () use ($http): array {
        return [
            'ok' => false,
            'status' => $http,
            'body' => $http === 404
                ? '{"code":"refund_not_found","message":"Refund not found"}'
                : '{"message":"err"}',
            'json' => $http === 404
                ? ['code' => 'refund_not_found', 'message' => 'Refund not found']
                : ['message' => 'err'],
        ];
    };
    $r = CashfreeGateway::fetchRefund($orderNo, $merchantRefundId);
    $ok(empty($r['ok']) && str_contains(strtolower((string) ($r['error'] ?? '')), $needle), 'HTTP ' . $http . ' admin message');
    $ok((int) ($r['http_status'] ?? 0) === $http, 'HTTP ' . $http . ' diagnostics status');
}

PaymentHttp::$testHandler = static function (): array {
    return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'timed out'];
};
$to = CashfreeGateway::fetchRefund($orderNo, $merchantRefundId);
$ok(!empty($to['network_error']) && str_contains((string) ($to['error'] ?? ''), 'timed out'), 'Network timeout message');

PaymentHttp::$testHandler = static function () use ($pathSuffix): array {
    return ['ok' => true, 'status' => 200, 'body' => 'not-json{{{', 'json' => null];
};
$badJson = CashfreeGateway::fetchRefund($orderNo, $merchantRefundId);
$ok(empty($badJson['ok']) && !empty($badJson['invalid_json']), 'Invalid JSON flagged');

$row = [
    'gateway' => 'cashfree',
    'order_number' => $orderNo,
    'idempotency_key' => 'refund_req_2',
    'gateway_refund_id' => '155230244',
];
$ok(
    GatewayRefundService::cashfreeMerchantRefundIdForStatusGet($row) === 'refund_req_2',
    'Lookup id prefers idempotency merchant id over cf_refund_id'
);

PaymentHttp::$testHandler = $prev;

echo $fail === 0 ? "PASS: Cashfree refund status fetch.\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
