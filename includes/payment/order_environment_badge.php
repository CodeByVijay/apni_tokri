<?php

declare(strict_types=1);

/**
 * Customer-facing Demo / Test labels on order result pages.
 * Based on order source (session demo vs database) and payment attempt metadata — not payment failure alone.
 */
final class OrderPaymentEnvironmentBadge
{
    /**
     * @return array{text: string, class: string}|null
     */
    public static function forOrderConfirmation(string $orderNumber, bool $loadedFromDatabase): ?array
    {
        if ($orderNumber === '') {
            return null;
        }
        if (!$loadedFromDatabase) {
            return self::demoBadge();
        }
        if (!CommerceGateway::useDatabase() || !Install::isSchemaReady()) {
            return self::demoBadge();
        }

        return self::badgeForPersistedOrder($orderNumber);
    }

    /**
     * @return array{text: string, class: string}|null
     */
    public static function badgeForPersistedOrder(string $orderNumber): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([trim($orderNumber)]);
        $orderId = (int) ($stmt->fetchColumn() ?: 0);
        if ($orderId <= 0) {
            return null;
        }

        $payments = PaymentRepository::listPaymentsForOrder($orderId);
        if ($payments === []) {
            return null;
        }

        $pay = self::selectPaymentForEnvironment($payments);
        if ($pay === null) {
            return null;
        }

        return self::badgeForPaymentRow($pay);
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>|null
     */
    private static function selectPaymentForEnvironment(array $payments): ?array
    {
        $captured = null;
        $latest = null;
        foreach ($payments as $pay) {
            $latest = $pay;
            if ((string) ($pay['status'] ?? '') === 'captured') {
                $captured = $pay;
            }
        }

        return $captured ?? $latest;
    }

    /**
     * @param array<string, mixed> $pay
     * @return array{text: string, class: string}|null
     */
    public static function badgeForPaymentRow(array $pay): ?array
    {
        $env = self::environmentFromPaymentRow($pay);
        if ($env === 'demo') {
            return self::demoBadge();
        }
        if ($env === 'test') {
            return ['text' => 'Test payment', 'class' => 'at-badge at-badge--yellow'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pay
     * @return 'live'|'test'|'demo'
     */
    public static function environmentFromPaymentRow(array $pay): string
    {
        $gateway = strtolower(trim((string) ($pay['gateway'] ?? '')));
        if ($gateway === 'simulate') {
            return 'demo';
        }

        $stored = self::storedEnvironmentFromMetadata($pay);
        if ($stored !== null) {
            return $stored;
        }

        if ($gateway === 'razorpay') {
            return self::razorpayEnvironmentFallback();
        }
        if ($gateway === 'cashfree') {
            return self::cashfreeEnvironmentFallback();
        }

        return 'live';
    }

    /** @return 'live'|'test'|'demo'|null */
    private static function storedEnvironmentFromMetadata(array $pay): ?string
    {
        $raw = $pay['metadata_json'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $meta = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($meta)) {
            return null;
        }
        $env = strtolower(trim((string) ($meta['payment_environment'] ?? '')));
        if ($env === 'simulate' || $env === 'demo') {
            return 'demo';
        }
        if (in_array($env, ['sandbox', 'test'], true)) {
            return 'test';
        }
        if ($env === 'production' || $env === 'live') {
            return 'live';
        }

        return null;
    }

    private static function cashfreeEnvironmentFallback(): string
    {
        if (AppEnvironment::isProduction()) {
            return 'live';
        }

        return PaymentGatewayConfig::usesSandboxPayments() ? 'test' : 'live';
    }

    private static function razorpayEnvironmentFallback(): string
    {
        if (AppEnvironment::isProduction()) {
            return 'live';
        }
        $key = RazorpayGateway::keyId();

        return str_starts_with($key, 'rzp_test_') ? 'test' : 'live';
    }

    /** @return array{text: string, class: string} */
    private static function demoBadge(): array
    {
        return ['text' => 'Demo', 'class' => 'at-badge at-badge--neutral'];
    }
}
