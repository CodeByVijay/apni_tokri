<?php

declare(strict_types=1);

/** Cart coupon apply/remove (shared by AJAX endpoint and tests). */
final class CartCouponHandler
{
    /**
     * @param array<string, mixed> $input POST fields (coupon_code, remove)
     * @return array{ok: bool, message?: string, totals?: array<string, mixed>}
     */
    public static function process(array $input): array
    {
        if (!empty($input['remove'])) {
            return CartService::removeCoupon();
        }

        $code = isset($input['coupon_code']) && is_string($input['coupon_code']) ? $input['coupon_code'] : '';
        if (trim($code) === '') {
            return ['ok' => false, 'message' => 'Please enter a coupon code.'];
        }

        return CartService::applyCoupon($code);
    }
}
