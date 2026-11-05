<?php

declare(strict_types=1);

final class PaymentGatewayConfig
{
    public static function activeGateway(): string
    {
        $g = AppSettings::get('payment.active_gateway', 'simulate') ?? 'simulate';
        if (!in_array($g, ['simulate', 'razorpay', 'cashfree'], true)) {
            return 'simulate';
        }

        return $g;
    }

    /** Persisted admin setting (payment.test_mode in settings table). */
    public static function storedTestModeEnabled(): bool
    {
        return (AppSettings::get('payment.test_mode', '1') ?? '1') !== '0';
    }

    /** @deprecated Prefer storedTestModeEnabled() or usesSandboxPayments() for clarity. */
    public static function isTestMode(): bool
    {
        return self::storedTestModeEnabled();
    }

    /**
     * Whether outbound payment API calls should use sandbox hosts.
     * Never true on production (checkout is blocked separately when stored test mode is on).
     */
    public static function usesSandboxPayments(): bool
    {
        if (AppEnvironment::isProduction()) {
            return false;
        }

        return self::storedTestModeEnabled();
    }

    /**
     * Value stored on payment rows (metadata_json.payment_environment) at initiation/capture.
     *
     * @return 'production'|'sandbox'|'test'|'simulate'
     */
    public static function recordedPaymentEnvironment(string $gateway): string
    {
        $gateway = strtolower(trim($gateway));
        if ($gateway === 'simulate') {
            return 'simulate';
        }
        if ($gateway === 'razorpay' && str_starts_with(RazorpayGateway::keyId(), 'rzp_test_')) {
            return 'test';
        }
        if ($gateway === 'cashfree' && self::usesSandboxPayments()) {
            return 'sandbox';
        }

        return 'production';
    }

    /**
     * Cashfree PG API base URL for orders/refunds (no network I/O).
     *
     * @throws RuntimeException when production has stored test mode enabled (fail closed)
     */
    public static function cashfreePgApiBase(): string
    {
        if (AppEnvironment::isProduction()) {
            if (self::storedTestModeEnabled()) {
                throw new RuntimeException(
                    'Cashfree API is unavailable while payment test/sandbox mode is enabled on production.'
                );
            }

            return 'https://api.cashfree.com/pg';
        }

        return self::usesSandboxPayments()
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';
    }

    /** Admin status line for Cashfree API host (reflects stored mode on production). */
    public static function cashfreeApiHostLabel(): string
    {
        if (AppEnvironment::isProduction() && self::storedTestModeEnabled()) {
            return 'sandbox (stored test mode — checkout blocked on production)';
        }

        return self::usesSandboxPayments() ? 'sandbox' : 'production';
    }

    public static function useSimulatedCheckout(): bool
    {
        if (AppEnvironment::isProduction()) {
            return false;
        }
        if (!CommerceGateway::useDatabase()) {
            return !AppEnvironment::isProduction();
        }
        $active = self::activeGateway();
        if ($active === 'simulate') {
            return true;
        }
        if ($active === 'razorpay') {
            return !self::isRazorpayOperational();
        }
        if ($active === 'cashfree') {
            return !self::isCashfreeOperational();
        }

        return true;
    }

    public static function isRazorpayOperational(): bool
    {
        return RazorpayGateway::isConfigured() && RazorpayGateway::keyIdLooksValid();
    }

    public static function isCashfreeOperational(): bool
    {
        return CashfreeGateway::isConfigured() && CashfreeGateway::appIdLooksValid();
    }

    /** @return array{ok: bool, message?: string, code?: string} */
    public static function readinessFor(string $gateway): array
    {
        if ($gateway === 'simulate') {
            return ['ok' => true];
        }
        if ($gateway === 'razorpay') {
            if (!RazorpayGateway::isEnabled()) {
                return ['ok' => false, 'code' => 'disabled', 'message' => 'Razorpay is disabled in admin settings.'];
            }
            if (!RazorpayGateway::isConfigured()) {
                return ['ok' => false, 'code' => 'missing_credentials', 'message' => 'Razorpay Key ID and Key Secret are required.'];
            }
            if (!RazorpayGateway::keyIdLooksValid()) {
                return ['ok' => false, 'code' => 'invalid_key_id', 'message' => 'Razorpay Key ID format is invalid (expected rzp_test_… or rzp_live_…).'];
            }
            if (!self::credentialsMatchTestMode('razorpay')) {
                return ['ok' => false, 'code' => 'mode_mismatch', 'message' => 'Razorpay Key ID does not match Test/Live mode.'];
            }

            return ['ok' => true];
        }
        if ($gateway === 'cashfree') {
            if (!CashfreeGateway::isEnabled()) {
                return ['ok' => false, 'code' => 'disabled', 'message' => 'Cashfree is disabled in admin settings.'];
            }
            if (!CashfreeGateway::isConfigured()) {
                return ['ok' => false, 'code' => 'missing_credentials', 'message' => 'Cashfree App ID and Secret Key are required.'];
            }
            if (!CashfreeGateway::appIdLooksValid()) {
                return ['ok' => false, 'code' => 'invalid_app_id', 'message' => 'Cashfree App ID format looks invalid.'];
            }

            return ['ok' => true];
        }

        return ['ok' => false, 'code' => 'unknown_gateway', 'message' => 'Unknown payment gateway.'];
    }

    public static function credentialsMatchTestMode(string $gateway): bool
    {
        if ($gateway === 'razorpay') {
            $kid = RazorpayGateway::keyId();
            if ($kid === '') {
                return false;
            }
            $isTestKey = strpos($kid, 'rzp_test_') === 0;
            $isLiveKey = strpos($kid, 'rzp_live_') === 0;
            if (!$isTestKey && !$isLiveKey) {
                return false;
            }

            return self::usesSandboxPayments() ? $isTestKey : $isLiveKey;
        }

        return true;
    }

    public static function gatewayLabel(string $gateway): string
    {
        if ($gateway === 'razorpay') {
            return 'Razorpay';
        }
        if ($gateway === 'cashfree') {
            return 'Cashfree';
        }
        if ($gateway === 'simulate') {
            return 'Simulated checkout';
        }

        return ucfirst($gateway);
    }

    /** Live gateway selected and credentials present (not simulate fallback). */
    public static function isProductionGatewayMode(): bool
    {
        return !self::useSimulatedCheckout() && self::activeGateway() !== 'simulate';
    }

    /**
     * Fail closed when live payments are expected but gateway is not ready.
     *
     * @return array{ok: bool, message?: string}
     */
    public static function assertLivePaymentsReady(): array
    {
        if (AppEnvironment::isProduction()) {
            return AppEnvironment::assertProductionPaymentsReady();
        }
        if (self::useSimulatedCheckout()) {
            return ['ok' => true];
        }
        $gateway = self::activeGateway();
        return self::readinessFor($gateway);
    }

    /**
     * Fail closed before creating or completing a payment row (orders, subscriptions, webhooks).
     *
     * @return array{ok: bool, message?: string}
     */
    public static function assertPaymentExecutionAllowed(string $gateway): array
    {
        if ($gateway === 'simulate' && AppEnvironment::isProduction()) {
            return ['ok' => false, 'message' => 'Simulated payments are disabled in production. Configure Razorpay or Cashfree.'];
        }
        if (AppEnvironment::isProduction()) {
            return AppEnvironment::assertProductionPaymentsReady();
        }

        return ['ok' => true];
    }

    public static function currency(): string
    {
        return 'INR';
    }

    /** @return array<string, mixed> */
    public static function publicCheckoutConfig(): array
    {
        $gateway = self::activeGateway();
        if (self::useSimulatedCheckout()) {
            return ['mode' => 'simulate', 'gateway' => 'simulate'];
        }
        if ($gateway === 'razorpay') {
            return [
                'mode' => 'razorpay',
                'gateway' => 'razorpay',
                'key_id' => RazorpayGateway::keyId(),
                'test_mode' => self::isTestMode(),
            ];
        }

        return [
            'mode' => 'cashfree',
            'gateway' => 'cashfree',
            'app_id' => CashfreeGateway::appId(),
            'test_mode' => self::isTestMode(),
        ];
    }
}
