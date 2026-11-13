<?php

declare(strict_types=1);

/** Cart page free-delivery / delivery preview copy from server totals only. */
final class CartDeliveryBanner
{
    public const MSG_SELECT_ADDRESS = 'Select your delivery address to see the exact delivery fee.';
    public const MSG_UPDATE_ADDRESS = 'Update your saved delivery address to see the exact delivery fee.';
    private const CTA_HREF = '/checkout.php';
    private const CTA_LABEL = 'Select delivery address';

    /**
     * @param array<string, mixed> $totals
     * @return array{message: string, tone: string, show_progress: bool, progress: int, cta_href?: string, cta_label?: string}
     */
    public static function fromTotals(array $totals): array
    {
        $quoteMsg = trim((string) ($totals['delivery_quote_message'] ?? ''));
        if ($quoteMsg !== '') {
            if (self::isSelectAddressMessage($quoteMsg)) {
                return self::withCta(self::neutral(self::MSG_SELECT_ADDRESS));
            }

            return self::withCta(self::neutral($quoteMsg));
        }

        if (empty($totals['delivery_quote_accurate'])) {
            return self::withCta(self::neutral(self::MSG_SELECT_ADDRESS));
        }

        if (empty($totals['delivery_serviceable'])) {
            return self::withCta(self::neutral('Delivery is not available for the selected address.'));
        }

        $fee = (float) ($totals['delivery_fee'] ?? 0);
        $toFree = (float) ($totals['amount_to_free_delivery'] ?? 0);
        $eligible = !empty($totals['free_delivery_eligible']);

        if ($fee <= 0 && $eligible && $toFree <= 0) {
            return [
                'message' => 'You\'ve unlocked free delivery',
                'tone' => 'success',
                'show_progress' => true,
                'progress' => 100,
            ];
        }

        if ($eligible && $toFree > 0) {
            return [
                'message' => 'Add ' . catalogue_format_price($toFree) . ' more to unlock free delivery',
                'tone' => 'progress',
                'show_progress' => true,
                'progress' => self::progressPercent($totals, $toFree),
            ];
        }

        return self::neutral('Delivery fee is shown in your order summary once your address is confirmed.');
    }

    /**
     * @param array<string, mixed> $totals
     */
    private static function progressPercent(array $totals, float $toFree): int
    {
        $threshold = (float) ($totals['free_delivery_threshold'] ?? 0);
        $eligibleSub = $threshold > 0 ? max(0, $threshold - $toFree) : 0;
        if ($threshold <= 0) {
            return 4;
        }

        return max(4, min(100, (int) round($eligibleSub / $threshold * 100)));
    }

    /** @return array{message: string, tone: string, show_progress: bool, progress: int} */
    private static function neutral(string $message): array
    {
        return [
            'message' => $message,
            'tone' => 'muted',
            'show_progress' => false,
            'progress' => 0,
        ];
    }

    /**
     * @param array{message: string, tone: string, show_progress: bool, progress: int} $banner
     * @return array{message: string, tone: string, show_progress: bool, progress: int, cta_href: string, cta_label: string}
     */
    private static function withCta(array $banner): array
    {
        $banner['cta_href'] = url_base() . self::CTA_HREF;
        $banner['cta_label'] = self::CTA_LABEL;

        return $banner;
    }

    private static function isSelectAddressMessage(string $quoteMsg): bool
    {
        $lower = strtolower($quoteMsg);

        return str_contains($lower, 'select a delivery address')
            || str_contains($lower, 'select delivery address')
            || str_contains($lower, 'delivery address for an accurate')
            || str_contains($lower, 'update your saved delivery address')
            || str_contains($lower, 'update your delivery address')
            || str_contains($lower, 'delivery address with a confirmed location');
    }
}
