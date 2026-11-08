<?php

declare(strict_types=1);

final class CashfreeGateway
{
    private static function baseUrl(): string
    {
        return PaymentGatewayConfig::cashfreePgApiBase();
    }

    public static function isEnabled(): bool
    {
        return (AppSettings::get('cashfree.enabled', '0') ?? '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return self::isEnabled() && self::appId() !== '' && self::secretKey() !== '';
    }

    public static function appIdLooksValid(): bool
    {
        $id = self::appId();

        return strlen($id) >= 8 && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }

    public static function appId(): string
    {
        return trim(AppSettings::get('cashfree.app_id', '') ?? '');
    }

    public static function secretKey(): string
    {
        return trim(AppSettings::getSecret('cashfree.secret_key', '') ?? '');
    }

    public static function webhookSecret(): string
    {
        return trim(AppSettings::getSecret('cashfree.webhook_secret', '') ?? '');
    }

    /** Secret used to verify PG webhooks (client secret per Cashfree docs; optional webhook_secret override). */
    public static function webhookSigningSecret(): string
    {
        $override = self::webhookSecret();
        if ($override !== '') {
            return $override;
        }

        return self::secretKey();
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'x-client-id: ' . self::appId(),
            'x-client-secret: ' . self::secretKey(),
            'x-api-version: 2023-08-01',
        ];
    }

    /**
     * @return array{ok: bool, gateway_order_id?: string, payment_session_id?: string, error?: string}
     */
    public static function createOrder(
        float $amountInr,
        string $orderId,
        string $customerPhone,
        string $returnUrl,
        string $referenceId,
        ?int $internalOrderId = null
    ): array {
        if ($amountInr < 1.0) {
            PaymentInitDiagnostics::logInitFailure('cashfree', $referenceId, 'validation', $internalOrderId, 0, null, 'amount_below_minimum');

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId), 'reference_id' => $referenceId];
        }
        $phone = preg_replace('/\D+/', '', $customerPhone) ?: '9999999999';
        $orderExpiry = PaymentSlotHoldService::cashfreeOrderExpiryTime();
        $body = [
            'order_id' => $orderId,
            'order_amount' => round($amountInr, 2),
            'order_currency' => 'INR',
            'order_expiry_time' => $orderExpiry->format('Y-m-d\TH:i:sP'),
            'customer_details' => [
                'customer_id' => $phone,
                'customer_phone' => $phone,
            ],
            'order_meta' => ['return_url' => $returnUrl, 'notify_url' => SeoUrl::storefrontAbsolute('/webhooks/cashfree.php')],
        ];
        $apiBase = self::baseUrl();
        $resp = PaymentHttp::request('POST', $apiBase . '/orders', $body, self::headers());
        $httpStatus = (int) $resp['status'];
        $timedOut = $httpStatus === 0 && !empty($resp['error']);
        if ($timedOut || $httpStatus === 0) {
            $reconcile = self::fetchOrder($orderId);
            if ($reconcile['ok'] && trim((string) ($reconcile['payment_session_id'] ?? '')) !== '') {
                PaymentInitDiagnostics::logInitAmbiguous(
                    'cashfree',
                    $referenceId,
                    'timeout_reconcile',
                    (int) ($internalOrderId ?? 0),
                    $orderId,
                    $httpStatus,
                    true
                );

                return [
                    'ok' => true,
                    'gateway_order_id' => (string) ($reconcile['cf_order_id'] ?? ''),
                    'payment_session_id' => (string) $reconcile['payment_session_id'],
                    'order_expiry_time' => (string) ($reconcile['order_expiry_time'] ?? $orderExpiry->format(DateTimeInterface::ATOM)),
                    'reconciled' => true,
                ];
            }
        }
        if ($httpStatus === 409) {
            $reconcile = self::fetchOrder($orderId);
            if ($reconcile['ok'] && trim((string) ($reconcile['payment_session_id'] ?? '')) !== '') {
                PaymentInitDiagnostics::logInitAmbiguous(
                    'cashfree',
                    $referenceId,
                    'duplicate_reconcile',
                    (int) ($internalOrderId ?? 0),
                    $orderId,
                    $httpStatus,
                    true
                );

                return [
                    'ok' => true,
                    'gateway_order_id' => (string) ($reconcile['cf_order_id'] ?? ''),
                    'payment_session_id' => (string) $reconcile['payment_session_id'],
                    'order_expiry_time' => (string) ($reconcile['order_expiry_time'] ?? $orderExpiry->format(DateTimeInterface::ATOM)),
                    'reconciled' => true,
                ];
            }
        }
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $stage = $httpStatus === 401 ? 'gateway_authentication' : 'gateway_api';
            PaymentInitDiagnostics::logInitFailure(
                'cashfree',
                $referenceId,
                $stage,
                $internalOrderId,
                $httpStatus,
                is_array($resp['json']) ? $resp['json'] : null,
                $resp['error'] ?? null,
                $orderId,
                false,
                $timedOut
            );
            PaymentGatewayDiagnosticState::recordInitAttempt('cashfree', $httpStatus, false, $referenceId);

            return [
                'ok' => false,
                'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId),
                'reference_id' => $referenceId,
                'http_status' => $httpStatus,
            ];
        }
        $cfId = (string) ($resp['json']['cf_order_id'] ?? $resp['json']['order_id'] ?? '');
        $session = (string) ($resp['json']['payment_session_id'] ?? '');
        if ($cfId === '' || $session === '') {
            PaymentInitDiagnostics::logInitFailure(
                'cashfree',
                $referenceId,
                'response_parse',
                $internalOrderId,
                $httpStatus,
                $resp['json'],
                null,
                $orderId,
                false,
                false
            );

            return ['ok' => false, 'error' => PaymentInitDiagnostics::customerMessage('Cashfree', $referenceId), 'reference_id' => $referenceId];
        }

        PaymentInitDiagnostics::logInitSuccess('cashfree', $referenceId, $internalOrderId, $orderId, $httpStatus, true);
        PaymentGatewayDiagnosticState::recordInitAttempt('cashfree', $httpStatus, true, $referenceId);

        return [
            'ok' => true,
            'gateway_order_id' => $cfId,
            'payment_session_id' => $session,
            'order_expiry_time' => $orderExpiry->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{
     *     http_status?: int,
     *     gateway_error_code?: string|null,
     *     gateway_error_message?: string|null,
     *     environment?: string,
     *     request_url_path?: string,
     *     invalid_json?: bool,
     *     network_error?: bool
     * }
     */
    private static function orderFetchFailureDiagnostics(array $resp, string $requestPath, ?string $recordedEnvironment): array
    {
        $status = (int) ($resp['status'] ?? 0);
        $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $code = trim((string) ($json['code'] ?? $json['error_code'] ?? ''));
        $msg = trim((string) ($json['message'] ?? $json['error_description'] ?? $json['error'] ?? ''));
        if ($msg === '' && $status > 0 && !is_array($resp['json'] ?? null)) {
            $body = trim((string) ($resp['body'] ?? ''));
            if ($body !== '' && strlen($body) <= 200) {
                $msg = $body;
            }
        }

        return [
            'http_status' => $status,
            'gateway_error_code' => $code !== '' ? $code : null,
            'gateway_error_message' => $msg !== '' ? mb_substr($msg, 0, 255) : null,
            'environment' => PaymentGatewayConfig::cashfreeOrderStatusHostLabel($recordedEnvironment),
            'request_url_path' => $requestPath,
            'invalid_json' => $status >= 200 && $status < 300 && !is_array($resp['json'] ?? null),
            'network_error' => $status === 0,
        ];
    }

    /** @param array<string, mixed> $diag */
    private static function orderFetchAdminMessage(array $diag, string $merchantOrderId, bool $paymentsEndpoint): string
    {
        $status = (int) ($diag['http_status'] ?? 0);
        $code = (string) ($diag['gateway_error_code'] ?? '');
        $gMsg = (string) ($diag['gateway_error_message'] ?? '');
        $env = (string) ($diag['environment'] ?? '');
        $what = $paymentsEndpoint ? 'payment list' : 'order status';

        if (!empty($diag['network_error'])) {
            return 'Cashfree ' . $what . ' request timed out or could not reach the network (environment: ' . $env . ').';
        }
        if (!empty($diag['invalid_json'])) {
            return 'Cashfree returned an invalid response for ' . $what . ' (HTTP ' . $status . ', environment: ' . $env . ').';
        }
        if ($status === 401 || $status === 403) {
            $hint = $gMsg !== '' ? ' ' . $gMsg : '';

            return 'Cashfree rejected API credentials for ' . $what . ' (HTTP ' . $status . ', environment: ' . $env . ').'
                . $hint
                . ' Confirm app ID and secret match this environment in Admin → Payment gateways.';
        }
        if ($status === 404) {
            return 'Cashfree has no order for merchant ID “' . $merchantOrderId . '” (HTTP 404, environment: ' . $env . '). '
                . 'If gateway order ID is blank locally, payment may never have been created at Cashfree (failed checkout initiation). '
                . 'Do not create a new gateway order for refresh — customer can use Retry payment.';
        }
        if ($status === 429) {
            return 'Cashfree rate-limited the ' . $what . ' request (HTTP 429). Wait and try again.';
        }
        if ($status >= 500 && $status < 600) {
            return 'Cashfree server error while fetching ' . $what . ' (HTTP ' . $status . ', environment: ' . $env . ').';
        }
        $suffix = '';
        if ($code !== '' || $gMsg !== '') {
            $suffix = ' [' . trim($code . ($code !== '' && $gMsg !== '' ? ': ' : '') . $gMsg) . ']';
        }
        if ($status > 0) {
            return 'Cashfree ' . $what . ' request failed (HTTP ' . $status . ', environment: ' . $env . ').' . $suffix;
        }

        return $paymentsEndpoint ? 'Could not fetch Cashfree payment status.' : 'Could not fetch Cashfree order status.';
    }

    /**
     * @return array{
     *     ok: bool,
     *     order_status?: string,
     *     cf_order_id?: string,
     *     order_amount?: float,
     *     payment_session_id?: string,
     *     error?: string,
     *     http_status?: int,
     *     environment?: string
     * }
     */
    public static function fetchOrder(string $merchantOrderId, ?string $recordedEnvironment = null): array
    {
        $merchantOrderId = trim($merchantOrderId);
        if ($merchantOrderId === '') {
            return ['ok' => false, 'error' => 'Invalid order reference.'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Cashfree is not configured.'];
        }
        try {
            $apiBase = PaymentGatewayConfig::cashfreePgApiBaseForOrderStatus($recordedEnvironment);
        } catch (RuntimeException $e) {
            error_log('Cashfree fetch order: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'Cashfree API is blocked by current payment settings. '
                    . 'Disable payment test/sandbox mode on production or match credentials to the payment environment.',
            ];
        }
        $path = '/orders/' . rawurlencode($merchantOrderId);
        $url = $apiBase . $path;
        $resp = PaymentHttp::request('GET', $url, null, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $diag = self::orderFetchFailureDiagnostics($resp, $path, $recordedEnvironment);
            error_log(
                'Cashfree fetch order merchant=' . $merchantOrderId
                . ' http=' . (int) ($diag['http_status'] ?? 0)
                . ' env=' . (string) ($diag['environment'] ?? '')
                . ($diag['gateway_error_code'] ? ' code=' . $diag['gateway_error_code'] : '')
            );

            return [
                'ok' => false,
                'error' => self::orderFetchAdminMessage($diag, $merchantOrderId, false),
            ] + $diag;
        }

        return [
            'ok' => true,
            'order_status' => (string) ($resp['json']['order_status'] ?? ''),
            'cf_order_id' => (string) ($resp['json']['cf_order_id'] ?? ''),
            'order_amount' => (float) ($resp['json']['order_amount'] ?? 0),
            'payment_session_id' => (string) ($resp['json']['payment_session_id'] ?? ''),
            'order_expiry_time' => (string) ($resp['json']['order_expiry_time'] ?? ''),
            'http_status' => (int) ($resp['status'] ?? 200),
            'environment' => PaymentGatewayConfig::cashfreeOrderStatusHostLabel($recordedEnvironment),
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     payments?: list<array<string, mixed>>,
     *     error?: string,
     *     http_status?: int,
     *     environment?: string
     * }
     */
    public static function fetchPayments(string $merchantOrderId, ?string $recordedEnvironment = null): array
    {
        $merchantOrderId = trim($merchantOrderId);
        if ($merchantOrderId === '') {
            return ['ok' => false, 'error' => 'Invalid order reference.'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Cashfree is not configured.'];
        }
        try {
            $apiBase = PaymentGatewayConfig::cashfreePgApiBaseForOrderStatus($recordedEnvironment);
        } catch (RuntimeException $e) {
            error_log('Cashfree fetch payments: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'Cashfree API is blocked by current payment settings. '
                    . 'Disable payment test/sandbox mode on production or match credentials to the payment environment.',
            ];
        }
        $path = '/orders/' . rawurlencode($merchantOrderId) . '/payments';
        $url = $apiBase . $path;
        $resp = PaymentHttp::request('GET', $url, null, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $diag = self::orderFetchFailureDiagnostics($resp, $path, $recordedEnvironment);
            error_log(
                'Cashfree fetch payments merchant=' . $merchantOrderId
                . ' http=' . (int) ($diag['http_status'] ?? 0)
                . ' env=' . (string) ($diag['environment'] ?? '')
            );

            return [
                'ok' => false,
                'error' => self::orderFetchAdminMessage($diag, $merchantOrderId, true),
            ] + $diag;
        }

        $json = $resp['json'];
        if (isset($json['payments']) && is_array($json['payments'])) {
            $list = $json['payments'];
        } elseif ($json !== [] && array_keys($json) === range(0, count($json) - 1)) {
            $list = $json;
        } else {
            $list = [$json];
        }

        return ['ok' => true, 'payments' => $list];
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>|null
     */
    public static function findSuccessfulCapturedPayment(array $payments): ?array
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            $captured = !empty($payment['is_captured']);
            if ($status === 'SUCCESS' && $captured) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    public static function hasTerminalFailure(array $payments): bool
    {
        return self::hasHardGatewayFailure($payments);
    }

    /** Definitive gateway failures (not USER_DROPPED — customer may retry). */
    public static function hasHardGatewayFailure(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            if (in_array($status, ['FAILED', 'VOID', 'CANCELLED'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $payments */
    public static function hasRetryableCashfreeActivity(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            if (in_array($status, ['PENDING', 'ACTIVE', 'INITIATED', 'USER_DROPPED', ''], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $gatewayPayments
     * @return array{
     *   success: array<string, mixed>|null,
     *   should_mark_order_failed: bool,
     *   retryable: bool
     * }
     */
    public static function analyzeGatewayPayments(array $gatewayPayments): array
    {
        $success = self::findSuccessfulCapturedPayment($gatewayPayments);
        if ($success !== null) {
            return [
                'success' => $success,
                'should_mark_order_failed' => false,
                'retryable' => false,
            ];
        }
        $retryable = self::hasRetryableCashfreeActivity($gatewayPayments);
        $hardFail = self::hasHardGatewayFailure($gatewayPayments);

        return [
            'success' => null,
            'should_mark_order_failed' => !$retryable && $hardFail,
            'retryable' => $retryable || (!$hardFail && $gatewayPayments === []),
        ];
    }

    public static function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        if ($signature === '' || trim($timestamp) === '') {
            return false;
        }
        $secret = self::webhookSigningSecret();
        if ($secret === '') {
            return false;
        }
        $payload = $timestamp . $rawBody;
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        return Security::hashEquals($expected, $signature);
    }

    /**
     * Merchant refund_id sent on POST /refunds (Cashfree GET path segment — not cf_refund_id).
     */
    public static function merchantRefundIdFromIdempotency(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return '';
        }

        return substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $idempotencyKey), 0, 40);
    }

    public static function ordersRefundApiPath(string $merchantOrderId): string
    {
        return PaymentGatewayConfig::cashfreePgApiBaseForRefundOperations()
            . '/orders/' . rawurlencode($merchantOrderId) . '/refunds';
    }

    public static function orderRefundStatusApiPath(string $merchantOrderId, string $refundLookupId): string
    {
        return self::ordersRefundApiPath($merchantOrderId)
            . '/' . rawurlencode($refundLookupId);
    }

    /**
     * @return array{
     *     http_status?: int,
     *     gateway_error_code?: string,
     *     gateway_error_message?: string,
     *     environment?: string,
     *     request_url_path?: string,
     *     invalid_json?: bool,
     *     network_error?: bool
     * }
     */
    private static function refundFetchFailureDiagnostics(array $resp, string $requestPath): array
    {
        $status = (int) ($resp['status'] ?? 0);
        $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $code = trim((string) ($json['code'] ?? $json['error_code'] ?? ''));
        $msg = trim((string) ($json['message'] ?? $json['error_description'] ?? $json['error'] ?? ''));
        if ($msg === '' && $status > 0 && !is_array($resp['json'] ?? null)) {
            $body = trim((string) ($resp['body'] ?? ''));
            if ($body !== '' && strlen($body) <= 200) {
                $msg = $body;
            }
        }

        return [
            'http_status' => $status,
            'gateway_error_code' => $code !== '' ? $code : null,
            'gateway_error_message' => $msg !== '' ? mb_substr($msg, 0, 255) : null,
            'environment' => PaymentGatewayConfig::cashfreeApiHostLabel(),
            'request_url_path' => $requestPath,
            'invalid_json' => $status >= 200 && $status < 300 && !is_array($resp['json'] ?? null),
            'network_error' => $status === 0,
        ];
    }

    /** @param array<string, mixed> $diag */
    private static function refundFetchAdminMessage(array $diag, string $merchantRefundId): string
    {
        $status = (int) ($diag['http_status'] ?? 0);
        $code = (string) ($diag['gateway_error_code'] ?? '');
        $gMsg = (string) ($diag['gateway_error_message'] ?? '');
        $env = (string) ($diag['environment'] ?? '');

        if (!empty($diag['network_error'])) {
            return 'Cashfree refund status request timed out or could not reach the network. Check server connectivity and try again.';
        }
        if (!empty($diag['invalid_json'])) {
            return 'Cashfree returned an invalid response (HTTP ' . $status . '). Try again or reconcile from the Cashfree dashboard.';
        }
        if ($status === 401 || $status === 403) {
            $hint = $gMsg !== '' ? ' ' . $gMsg : '';

            return 'Cashfree rejected API credentials (HTTP ' . $status . ', environment: ' . $env . ').' . $hint;
        }
        if ($status === 404) {
            return 'Cashfree refund not found for order using merchant refund ID “'
                . $merchantRefundId
                . '” (HTTP 404). The GET API uses merchant refund_id, not CF refund ID — confirm the refund exists on this order in Cashfree ('
                . $env
                . ').';
        }
        if ($status === 429) {
            return 'Cashfree rate-limited the refund status request (HTTP 429). Wait and try again.';
        }
        if ($status >= 500 && $status < 600) {
            return 'Cashfree server error while fetching refund status (HTTP ' . $status . '). Try again shortly.';
        }
        $suffix = '';
        if ($code !== '' || $gMsg !== '') {
            $suffix = ' [' . trim($code . ($code !== '' && $gMsg !== '' ? ': ' : '') . $gMsg) . ']';
        }
        if ($status > 0) {
            return 'Cashfree refund status request failed (HTTP ' . $status . ', environment: ' . $env . ').' . $suffix;
        }

        return 'Could not fetch refund status from Cashfree.';
    }

    /**
     * Fetch status of an existing Cashfree refund (GET only).
     *
     * @return array{
     *     ok: bool,
     *     refund_status?: string,
     *     gateway_refund_id?: string,
     *     refund_amount?: float,
     *     snapshot?: array<string, mixed>,
     *     error?: string,
     *     http_status?: int,
     *     gateway_error_code?: string|null,
     *     gateway_error_message?: string|null,
     *     environment?: string,
     *     request_url_path?: string,
     *     invalid_json?: bool,
     *     network_error?: bool
     * }
     */
    public static function fetchRefund(string $merchantOrderId, string $refundLookupId): array
    {
        $merchantOrderId = trim($merchantOrderId);
        $refundLookupId = trim($refundLookupId);
        if ($merchantOrderId === '' || $refundLookupId === '') {
            return ['ok' => false, 'error' => 'Invalid refund lookup reference.'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Cashfree is not configured.'];
        }
        try {
            $url = self::orderRefundStatusApiPath($merchantOrderId, $refundLookupId);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'Cashfree is not configured.'];
        }
        $path = '/orders/' . rawurlencode($merchantOrderId) . '/refunds/' . rawurlencode($refundLookupId);
        $resp = PaymentHttp::request('GET', $url, null, self::headers());
        if (!$resp['ok'] || !is_array($resp['json'])) {
            $diag = self::refundFetchFailureDiagnostics($resp, $path);
            error_log('Cashfree fetch refund HTTP ' . ($diag['http_status'] ?? 0));

            return [
                'ok' => false,
                'error' => self::refundFetchAdminMessage($diag, $refundLookupId),
            ] + $diag;
        }
        $json = $resp['json'];
        $status = (string) ($json['refund_status'] ?? $json['status'] ?? '');
        $cfId = (string) ($json['cf_refund_id'] ?? '');

        return [
            'ok' => true,
            'refund_status' => $status,
            'gateway_refund_id' => $cfId !== '' ? $cfId : null,
            'refund_amount' => isset($json['refund_amount']) ? (float) $json['refund_amount'] : null,
            'snapshot' => $json,
            'http_status' => (int) ($resp['status'] ?? 200),
            'environment' => PaymentGatewayConfig::cashfreeApiHostLabel(),
            'request_url_path' => $path,
        ];
    }
}
