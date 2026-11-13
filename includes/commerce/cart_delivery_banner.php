<?php

declare(strict_types=1);

/** Cart page free-delivery / delivery preview copy from server totals only. */
final class CartDeliveryBanner
{
    public const MSG_SELECT_ADDRESS = 'Select your delivery address to see your delivery charge.';
    public const MSG_UPDATE_ADDRESS = 'Update your saved delivery address to see your delivery charge.';
    public const MSG_SUMMARY_NO_ADDRESS = 'Select an address to calculate delivery charge.';
    private const CTA_HREF = '/checkout.php';
    private const CTA_LABEL = 'Select delivery address';
    private const CTA_CHANGE_LABEL = 'Change address';

    /**
     * @param array<string, mixed> $totals
     * @return array{message: string, tone: string, show_progress: bool, progress: int, cta_href?: string, cta_label?: string}
     */
    public static function fromTotals(array $totals): array
    {
        $quoteMsg = trim((string) ($totals['delivery_quote_message'] ?? ''));
        $zone = trim((string) ($totals['delivery_zone'] ?? ''));
        $zoneSuffix = $zone !== '' ? ' · ' . $zone : '';

        if ($quoteMsg !== '') {
            if (self::isSelectAddressMessage($quoteMsg)) {
                return self::withCta(self::neutral(self::MSG_SELECT_ADDRESS), self::CTA_LABEL);
            }
            if (self::isSelectSlotMessage($quoteMsg)) {
                return self::neutral('Address saved' . $zoneSuffix . ' — choose your delivery slot at checkout to see your delivery charge.');
            }

            return self::withCta(self::neutral($quoteMsg), self::CTA_CHANGE_LABEL);
        }

        if (empty($totals['delivery_quote_accurate'])) {
            return self::withCta(self::neutral(self::MSG_SELECT_ADDRESS), self::CTA_LABEL);
        }

        if (empty($totals['delivery_serviceable'])) {
            return self::withCta(self::neutral('Delivery is not available for the selected address.'), self::CTA_CHANGE_LABEL);
        }

        $fee = (float) ($totals['delivery_fee'] ?? 0);
        $toFree = (float) ($totals['amount_to_free_delivery'] ?? 0);
        $eligible = !empty($totals['free_delivery_eligible']);

        if ($fee <= 0) {
            return [
                'message' => ($eligible && $toFree <= 0 ? 'Free delivery unlocked' : 'Free delivery') . $zoneSuffix,
                'tone' => 'success',
                'show_progress' => $eligible,
                'progress' => 100,
            ];
        }

        if ($eligible && $toFree > 0) {
            return [
                'message' => 'Delivery charge: ' . catalogue_format_price($fee) . $zoneSuffix . ' · Add ' . catalogue_format_price($toFree) . ' more for free delivery',
                'tone' => 'progress',
                'show_progress' => true,
                'progress' => self::progressPercent($totals, $toFree),
            ];
        }

        return self::neutral('Delivery charge: ' . catalogue_format_price($fee) . $zoneSuffix);
    }

    /**
     * Order-summary helper line when delivery quote is not yet resolved.
     *
     * @param array<string, mixed> $totals
     */
    public static function summaryPendingMessage(array $totals): string
    {
        $quoteMsg = trim((string) ($totals['delivery_quote_message'] ?? ''));
        if ($quoteMsg !== '') {
            if (self::isSelectAddressMessage($quoteMsg)) {
                return self::MSG_SUMMARY_NO_ADDRESS;
            }
            if (self::isSelectSlotMessage($quoteMsg)) {
                return 'Choose a delivery slot at checkout to calculate delivery charge.';
            }

            return $quoteMsg;
        }
        if (empty($totals['delivery_quote_accurate'])) {
            return self::MSG_SUMMARY_NO_ADDRESS;
        }
        if (empty($totals['delivery_serviceable'])) {
            return 'Delivery is not available for the selected address.';
        }

        return '';
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
    private static function withCta(array $banner, string $label = self::CTA_LABEL): array
    {
        $banner['cta_href'] = url_base() . self::CTA_HREF;
        $banner['cta_label'] = $label;

        return $banner;
    }

    public static function isSelectAddressMessage(string $quoteMsg): bool
    {
        $lower = strtolower($quoteMsg);

        return str_contains($lower, 'select a delivery address')
            || str_contains($lower, 'select delivery address')
            || str_contains($lower, 'select your delivery address')
            || str_contains($lower, 'select an address')
            || str_contains($lower, 'delivery address for an accurate')
            || str_contains($lower, 'update your saved delivery address')
            || str_contains($lower, 'update your delivery address')
            || str_contains($lower, 'delivery address with a confirmed location');
    }

    public static function isSelectSlotMessage(string $quoteMsg): bool
    {
        return str_contains(strtolower($quoteMsg), 'delivery slot');
    }
}
