<?php

declare(strict_types=1);

/**
 * Cart "Available offers" discovery.
 *
 * Contains NO eligibility or discount maths of its own: every decision is delegated to
 * CouponService::validate() (the same call used when a coupon is applied and at checkout).
 * This class only selects candidates, maps validation reasons to customer-facing hints and ranks.
 */
final class CouponOfferService
{
    public const MAX_PREVIEW = 3;
    public const MAX_PREVIEW_MOBILE = 2;

    /** Reasons for which a coupon must never be shown to this customer. */
    private const HIDDEN_REASONS = ['invalid', 'not_assigned', 'no_discount'];

    /**
     * @param list<array<string, mixed>> $lines pricing lines
     * @return list<array<string, mixed>> ranked offers (applied first, eligible by saving desc, then unavailable)
     */
    public static function forCart(array $lines, ?int $customerId, ?string $appliedCode = null): array
    {
        if ($lines === [] || !CommerceGateway::useDatabase() || !Install::isSchemaReady()) {
            return [];
        }
        $appliedCode = $appliedCode !== null && trim($appliedCode) !== '' ? strtoupper(trim($appliedCode)) : null;

        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
        }
        $offer = PromotionService::bestAutomaticOffer($lines, $customerId);
        $offerDiscount = (float) ($offer['discount'] ?? 0);
        $offerStacks = !empty($offer['stack_with_coupons']);

        $items = [];
        foreach (CouponRepository::activeForStorefront() as $row) {
            $code = strtoupper((string) $row['code']);
            $check = CouponService::validate($code, $subtotal, $customerId, $lines, $offerDiscount, $offerStacks);
            $reason = $check['ok'] ? null : (string) ($check['reason'] ?? 'invalid');
            if ($reason !== null && in_array($reason, self::HIDDEN_REASONS, true)) {
                continue;
            }
            $eligible = (bool) $check['ok'];
            $item = [
                'code' => $code,
                'eligible' => $eligible,
                'applied' => $eligible && $appliedCode === $code,
                'discount' => $eligible ? (float) ($check['discount'] ?? 0) : 0.0,
                'headline' => self::headline($row),
                'description' => self::description($row),
                'min_order' => $row['min_cart_value'] !== null && (float) $row['min_cart_value'] > 0
                    ? (float) $row['min_cart_value'] : null,
                'max_discount' => ($row['discount_type'] ?? '') === 'percent' && $row['max_discount'] !== null
                    ? (float) $row['max_discount'] : null,
                'tags' => self::tags($row),
                'reason' => $reason,
                'shortfall' => isset($check['shortfall']) ? (float) $check['shortfall'] : null,
                'hint' => $eligible ? null : self::hint($reason, $check),
                'priority' => (int) ($row['priority'] ?? 0),
                'best' => false,
                'preview' => false,
            ];
            $items[] = $item;
        }

        usort($items, static function (array $a, array $b): int {
            $ga = $a['applied'] ? 0 : ($a['eligible'] ? 1 : 2);
            $gb = $b['applied'] ? 0 : ($b['eligible'] ? 1 : 2);
            if ($ga !== $gb) {
                return $ga <=> $gb;
            }
            if ($ga <= 1) { // highest actual saving first
                $cmp = $b['discount'] <=> $a['discount'];
            } else { // closest to unlocking first
                $sa = $a['shortfall'] ?? PHP_FLOAT_MAX;
                $sb = $b['shortfall'] ?? PHP_FLOAT_MAX;
                $cmp = $sa <=> $sb;
            }

            return $cmp !== 0 ? $cmp : ($b['priority'] <=> $a['priority'] ?: strcmp($a['code'], $b['code']));
        });

        // Best eligible offer = highest actual saving (applied coupon keeps its slot at the front).
        $bestIdx = null;
        $bestDiscount = 0.0;
        foreach ($items as $i => $it) {
            if ($it['eligible'] && $it['discount'] > $bestDiscount) {
                $bestDiscount = $it['discount'];
                $bestIdx = $i;
            }
        }
        if ($bestIdx !== null) {
            $items[$bestIdx]['best'] = true;
        }

        // Preview: eligible first; top up with the nearest "add ₹X to unlock" offers.
        $slots = self::MAX_PREVIEW;
        foreach ($items as $i => $it) {
            if ($slots <= 0) {
                break;
            }
            if ($it['eligible']) {
                $items[$i]['preview'] = true;
                $slots--;
            }
        }
        $eligibleShown = self::MAX_PREVIEW - $slots;
        $fill = max(0, min($slots, self::MAX_PREVIEW_MOBILE - $eligibleShown));
        foreach ($items as $i => $it) {
            if ($fill <= 0) {
                break;
            }
            if (!$it['eligible'] && $it['reason'] === 'min_cart') {
                $items[$i]['preview'] = true;
                $fill--;
            }
        }

        return $items;
    }

    /** @param array<string, mixed>|null $totals */
    public static function forCurrentCart(?array $totals = null): array
    {
        $totals = $totals ?? CartService::totals();
        $applied = catalogue_cart_coupon_applied($totals) ? (string) $totals['coupon_code'] : null;

        return self::forCart(CartService::currentPricingLines(), CustomerAuth::id(), $applied);
    }

    /** @param array<string, mixed> $row */
    private static function headline(array $row): string
    {
        $value = (float) ($row['discount_value'] ?? 0);
        $n = static function (float $v): string {
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        };
        if (($row['discount_type'] ?? '') === 'percent') {
            return $n($value) . '% OFF';
        }

        return '₹' . $n($value) . ' OFF';
    }

    /** @param array<string, mixed> $row */
    private static function description(array $row): string
    {
        $parts = [];
        if (($row['discount_type'] ?? '') === 'percent') {
            $d = rtrim(rtrim(number_format((float) $row['discount_value'], 2, '.', ''), '0'), '.') . '% off';
            if ($row['max_discount'] !== null) {
                $d .= ' up to ₹' . number_format((float) $row['max_discount'], 0);
            }
        } else {
            $d = 'Flat ₹' . number_format((float) $row['discount_value'], 0) . ' off';
        }
        $parts[] = $d;
        $id = (int) $row['id'];
        if (CouponRepository::productIds($id) !== [] || CouponRepository::categoryIds($id) !== []) {
            $parts[] = 'on selected items';
        }
        if ($row['min_cart_value'] !== null && (float) $row['min_cart_value'] > 0) {
            $parts[] = 'on orders above ₹' . number_format((float) $row['min_cart_value'], 0);
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $row @return list<string> */
    private static function tags(array $row): array
    {
        $tags = [];
        if (!empty($row['first_order_only'])) {
            $tags[] = 'New customers';
        }
        $id = (int) $row['id'];
        if (CouponRepository::productIds($id) !== [] || CouponRepository::categoryIds($id) !== []) {
            $tags[] = 'Selected items';
        }

        return $tags;
    }

    /** @param array<string, mixed> $check */
    private static function hint(?string $reason, array $check): string
    {
        switch ($reason) {
            case 'min_cart':
                $short = (float) ($check['shortfall'] ?? 0);

                return 'Add ₹' . number_format(max(1, ceil($short)), 0) . ' more to unlock';
            case 'login_required':
                return 'Log in to use this offer';
            case 'first_order_only':
                return 'New customers only';
            case 'usage_limit':
                return 'Usage limit reached';
            case 'per_customer_limit':
                return 'You have already used this offer';
            case 'not_applicable':
                return 'Selected products are not eligible';
            case 'offer_conflict':
                return 'Cannot be combined with the automatic offer in your cart';
            default:
                return 'Not available for this cart';
        }
    }
}
