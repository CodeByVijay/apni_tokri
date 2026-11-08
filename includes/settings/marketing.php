<?php

declare(strict_types=1);

/** Marketing & conversion settings (`marketing.*` AppSettings keys). */
final class MarketingSettings
{
    public const KEY_LOW_STOCK_ENABLED = 'marketing.low_stock_enabled';
    public const KEY_LOW_STOCK_THRESHOLD = 'marketing.low_stock_threshold_default';
    public const KEY_FREE_DELIVERY_THRESHOLD = 'marketing.free_delivery_threshold_inr';
    public const KEY_OFFER_COUNTDOWN_END = 'marketing.offer_countdown_end_at';
    public const KEY_MIN_ORDER_ENABLED = 'marketing.min_order_enabled';
    public const KEY_MIN_ORDER_AMOUNT_INR = 'marketing.min_order_amount_inr';
    public const KEY_MIN_ORDER_MESSAGE = 'marketing.min_order_message';

    public static function lowStockEnabled(): bool
    {
        return AppSettings::get(self::KEY_LOW_STOCK_ENABLED, '1') === '1';
    }

    public static function defaultLowStockThreshold(): int
    {
        $v = (int) (AppSettings::get(self::KEY_LOW_STOCK_THRESHOLD, '10') ?? '10');

        return max(0, min(9999, $v));
    }

    public static function freeDeliveryThresholdInr(): ?float
    {
        $raw = trim(AppSettings::get(self::KEY_FREE_DELIVERY_THRESHOLD, '') ?? '');
        if ($raw === '') {
            return null;
        }
        $v = round((float) $raw, 2);

        return $v > 0 ? $v : null;
    }

    public static function minOrderEnabled(): bool
    {
        return AppSettings::get(self::KEY_MIN_ORDER_ENABLED, '0') === '1';
    }

    public static function minOrderAmountInr(): ?float
    {
        if (!self::minOrderEnabled()) {
            return null;
        }
        $raw = trim(AppSettings::get(self::KEY_MIN_ORDER_AMOUNT_INR, '') ?? '');
        if ($raw === '') {
            return null;
        }
        $v = round((float) $raw, 2);

        return $v > 0 ? $v : null;
    }

  /**
   * Raw admin template ({amount} optional).
   */
    public static function minOrderMessageTemplate(): string
    {
        $raw = trim(AppSettings::get(self::KEY_MIN_ORDER_MESSAGE, '') ?? '');
        if ($raw !== '') {
            return $raw;
        }

        return 'Your minimum order value should be {amount} to proceed to checkout.';
    }

    /** Merchandise after automatic offers and coupon — excludes delivery fee. */
    public static function merchandiseSubtotalAfterDiscounts(array $totals): float
    {
        return round(
            max(
                0,
                (float) ($totals['subtotal'] ?? 0)
                - (float) ($totals['offer_discount'] ?? 0)
                - (float) ($totals['coupon_discount'] ?? 0)
            ),
            2
        );
    }

    /**
     * @return array{
     *   min_order_enabled: bool,
     *   min_order_met: bool,
     *   min_order_shortfall: float,
     *   min_order_amount_inr: float,
     *   min_order_merchandise_inr: float,
     *   min_order_message: string
     * }
     */
    public static function minimumOrderStatusFromTotals(array $totals): array
    {
        if (!self::minOrderEnabled()) {
            return [
                'min_order_enabled' => false,
                'min_order_met' => true,
                'min_order_shortfall' => 0.0,
                'min_order_amount_inr' => 0.0,
                'min_order_merchandise_inr' => self::merchandiseSubtotalAfterDiscounts($totals),
                'min_order_message' => '',
            ];
        }
        $min = self::minOrderAmountInr();
        if ($min === null) {
            return [
                'min_order_enabled' => false,
                'min_order_met' => true,
                'min_order_shortfall' => 0.0,
                'min_order_amount_inr' => 0.0,
                'min_order_merchandise_inr' => self::merchandiseSubtotalAfterDiscounts($totals),
                'min_order_message' => '',
            ];
        }
        $merch = self::merchandiseSubtotalAfterDiscounts($totals);
        $shortfall = round(max(0, $min - $merch), 2);

        return [
            'min_order_enabled' => true,
            'min_order_met' => $shortfall <= 0.009,
            'min_order_shortfall' => $shortfall,
            'min_order_amount_inr' => $min,
            'min_order_merchandise_inr' => $merch,
            'min_order_message' => self::formatMinOrderCustomerMessage($min),
        ];
    }

    /** @param array<string, mixed> $totals */
    public static function attachMinimumOrderTotals(array $totals): array
    {
        return array_merge($totals, self::minimumOrderStatusFromTotals($totals));
    }

    /** Server-side checkout rejection message, or null if allowed. */
    public static function checkoutMinimumRejection(array $totals): ?string
    {
        $status = self::minimumOrderStatusFromTotals($totals);
        if (empty($status['min_order_enabled']) || !empty($status['min_order_met'])) {
            return null;
        }
        $msg = (string) ($status['min_order_message'] ?? '');
        $shortfall = (float) ($status['min_order_shortfall'] ?? 0);
        if ($shortfall > 0.009) {
            $msg .= ' Add ' . self::formatInr($shortfall) . ' more (after discounts, excluding delivery).';
        }

        return $msg !== '' ? $msg : 'Your cart does not meet the minimum order value.';
    }

    public static function formatMinOrderCustomerMessage(float $amountInr): string
    {
        $template = self::minOrderMessageTemplate();
        $formatted = self::formatInr($amountInr);
        if (str_contains($template, '{amount}')) {
            return str_replace('{amount}', $formatted, $template);
        }

        return $template;
    }

    private static function formatInr(float $amount): string
    {
        if (function_exists('catalogue_format_price')) {
            return catalogue_format_price($amount);
        }

        return '₹' . number_format(round($amount, 2), (floor($amount) !== $amount) ? 2 : 0, '.', ',');
    }

    /** @return DateTimeImmutable|null Asia/Kolkata instant when countdown is active */
    public static function offerCountdownEnd(): ?DateTimeImmutable
    {
        $raw = trim(AppSettings::get(self::KEY_OFFER_COUNTDOWN_END, '') ?? '');
        if ($raw === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
            $dt = new DateTimeImmutable($raw, $tz);
        } catch (Throwable $e) {
            return null;
        }
        if ($dt <= new DateTimeImmutable('now', $tz)) {
            return null;
        }

        return $dt;
    }

    /** @return array{low_stock: bool, threshold: int, free_threshold: string, offer_end_local: string, min_order: bool, min_order_amount: string, min_order_message: string} */
    public static function adminFormState(): array
    {
        $offerLocal = '';
        $raw = trim(AppSettings::get(self::KEY_OFFER_COUNTDOWN_END, '') ?? '');
        if ($raw !== '') {
            try {
                $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
                $dt = new DateTimeImmutable($raw, $tz);
                $offerLocal = $dt->format('Y-m-d\TH:i');
            } catch (Throwable $e) {
                $offerLocal = '';
            }
        }
        $free = AppSettings::get(self::KEY_FREE_DELIVERY_THRESHOLD, '499') ?? '499';

        $minAmt = trim(AppSettings::get(self::KEY_MIN_ORDER_AMOUNT_INR, '149') ?? '149');

        return [
            'low_stock' => self::lowStockEnabled(),
            'threshold' => self::defaultLowStockThreshold(),
            'free_threshold' => $free !== '' ? (string) $free : '',
            'offer_end_local' => $offerLocal,
            'min_order' => self::minOrderEnabled(),
            'min_order_amount' => $minAmt !== '' ? $minAmt : '149',
            'min_order_message' => self::minOrderMessageTemplate(),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }

        $errors = [];
        $lowStock = isset($post['low_stock']);
        $threshold = isset($post['threshold']) ? (int) $post['threshold'] : 10;
        if ($threshold < 0 || $threshold > 9999) {
            $errors['threshold'] = 'Threshold must be between 0 and 9999.';
        }
        $freeRaw = trim((string) ($post['free_threshold'] ?? ''));
        $freeVal = null;
        if ($freeRaw === '') {
            $errors['free_threshold'] = 'Enter a free delivery threshold amount.';
        } else {
            $freeVal = round((float) $freeRaw, 2);
            if ($freeVal <= 0 || $freeVal > 999999) {
                $errors['free_threshold'] = 'Enter a valid amount between 1 and 999999.';
            }
        }
        $offerEndIso = null;
        $offerLocal = trim((string) ($post['offer_end'] ?? ''));
        if ($offerLocal !== '') {
            try {
                $tz = new DateTimeZone(ReportDateRange::TIMEZONE);
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $offerLocal, $tz);
                if ($dt === false) {
                    $errors['offer_end'] = 'Enter a valid date and time.';
                } else {
                    $offerEndIso = $dt->format(DateTimeInterface::ATOM);
                }
            } catch (Throwable $e) {
                $errors['offer_end'] = 'Enter a valid date and time.';
            }
        }

        $minOrder = isset($post['min_order']);
        $minOrderRaw = trim((string) ($post['min_order_amount'] ?? ''));
        $minOrderMsg = trim((string) ($post['min_order_message'] ?? ''));
        $minOrderVal = null;
        if ($minOrder) {
            if ($minOrderRaw === '') {
                $errors['min_order_amount'] = 'Enter the minimum order amount.';
            } else {
                $minOrderVal = round((float) $minOrderRaw, 2);
                if ($minOrderVal <= 0 || $minOrderVal > 999999) {
                    $errors['min_order_amount'] = 'Enter a valid amount between 1 and 999999.';
                }
            }
            if ($minOrderMsg === '') {
                $errors['min_order_message'] = 'Enter the customer-facing validation message.';
            } elseif (mb_strlen($minOrderMsg) < 10) {
                $errors['min_order_message'] = 'Message should be at least 10 characters.';
            } elseif (mb_strlen($minOrderMsg) > 500) {
                $errors['min_order_message'] = 'Message is too long (max 500 characters).';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        AppSettings::set(self::KEY_LOW_STOCK_ENABLED, $lowStock ? '1' : '0');
        AppSettings::set(self::KEY_LOW_STOCK_THRESHOLD, (string) $threshold);
        AppSettings::set(self::KEY_FREE_DELIVERY_THRESHOLD, $freeVal !== null ? (string) $freeVal : null);
        AppSettings::set(self::KEY_OFFER_COUNTDOWN_END, $offerEndIso);
        AppSettings::set(self::KEY_MIN_ORDER_ENABLED, $minOrder ? '1' : '0');
        if ($minOrder && $minOrderVal !== null) {
            AppSettings::set(self::KEY_MIN_ORDER_AMOUNT_INR, (string) $minOrderVal);
            AppSettings::set(self::KEY_MIN_ORDER_MESSAGE, $minOrderMsg);
        }

        return ['ok' => true, 'message' => 'Marketing settings saved.'];
    }
}
