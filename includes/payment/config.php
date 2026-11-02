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

    public static function isTestMode(): bool
    {
        return (AppSettings::get('payment.test_mode', '1') ?? '1') !== '0';
    }

    public static function useSimulatedCheckout(): bool
    {
        if (!CommerceGateway::useDatabase()) {
            return true;
        }
        $active = self::activeGateway();
        if ($active === 'simulate') {
            return true;
        }
        if ($active === 'razorpay') {
            return !RazorpayGateway::isConfigured();
        }
        if ($active === 'cashfree') {
            return !CashfreeGateway::isConfigured();
        }

        return true;
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
        if (self::useSimulatedCheckout()) {
            return ['ok' => true];
        }
        $gateway = self::activeGateway();
        if ($gateway === 'razorpay' && !RazorpayGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Payment gateway is not configured.'];
        }
        if ($gateway === 'cashfree' && !CashfreeGateway::isConfigured()) {
            return ['ok' => false, 'message' => 'Payment gateway is not configured.'];
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
