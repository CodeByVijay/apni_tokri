<?php

declare(strict_types=1);

/** Storefront presentation for automatic offers (`offers` table) — eligibility remains PromotionService. */
final class OfferStorefront
{
    public const DISPLAY_TZ = ReportDateRange::TIMEZONE;

    /** Unix seconds for client countdown, or null when open-ended. */
    public static function endsAtToUnix(?string $endsAt): ?int
    {
        if ($endsAt === null || trim($endsAt) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable(trim($endsAt), new DateTimeZone(self::DISPLAY_TZ));
        } catch (Throwable $e) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /**
     * @return array{name: string, headline: string, description: string, scope: string, countdown_end_unix: ?int, offer_id: int}|null
     */
    public static function cardFromRow(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $unix = self::endsAtToUnix(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
        $type = (string) ($row['discount_type'] ?? 'percent');
        $val = (float) ($row['discount_value'] ?? 0);
        $headline = $type === 'percent'
            ? rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '% off'
            : catalogue_format_price($val) . ' off';
        $scope = (string) ($row['scope'] ?? 'store');
        $desc = (string) ($row['name'] ?? 'Special offer');
        if ($scope !== 'store' && $scope !== 'first_order') {
            $desc .= ' · ' . ucwords(str_replace('_', ' ', $scope)) . ' offer';
        }

        return [
            'offer_id' => $id,
            'name' => (string) ($row['name'] ?? 'Offer'),
            'headline' => $headline,
            'description' => $desc,
            'scope' => $scope,
            'countdown_end_unix' => $unix,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function activeCards(?int $customerId = null, int $limit = 12): array
    {
        if (!CommerceGateway::useDatabase()) {
            return [];
        }
        $out = [];
        foreach (PromotionService::activeOffers($customerId) as $row) {
            $card = self::cardFromRow($row);
            if ($card !== null) {
                $out[] = $card;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function forProduct(int $productId, int $categoryId, ?int $customerId = null): array
    {
        $out = [];
        foreach (self::activeCards($customerId, 20) as $card) {
            $row = self::findOfferRow((int) $card['offer_id']);
            if ($row === null) {
                continue;
            }
            if (self::rowAppliesToProduct($row, $productId, $categoryId)) {
                $out[] = $card;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function forCategory(int $categoryId, ?int $customerId = null): array
    {
        $out = [];
        foreach (self::activeCards($customerId, 20) as $card) {
            $row = self::findOfferRow((int) $card['offer_id']);
            if ($row === null) {
                continue;
            }
            if (self::rowAppliesToCategory($row, $categoryId)) {
                $out[] = $card;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public static function rowAppliesToProduct(array $row, int $productId, int $categoryId): bool
    {
        $scope = (string) ($row['scope'] ?? 'store');
        $target = isset($row['target_id']) ? (int) $row['target_id'] : 0;
        if ($scope === 'store' || $scope === 'first_order') {
            return true;
        }
        if ($scope === 'product' && $target > 0) {
            return $productId === $target;
        }
        if ($scope === 'category' && $target > 0) {
            return $categoryId === $target;
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    public static function rowAppliesToCategory(array $row, int $categoryId): bool
    {
        $scope = (string) ($row['scope'] ?? 'store');
        $target = isset($row['target_id']) ? (int) $row['target_id'] : 0;
        if ($scope === 'store' || $scope === 'first_order') {
            return true;
        }
        if ($scope === 'category' && $target > 0) {
            return $categoryId === $target;
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private static function findOfferRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return OfferRepository::find($id);
    }

    public static function datetimeLocalFromDb(?string $mysql): string
    {
        if ($mysql === null || trim($mysql) === '') {
            return '';
        }
        try {
            $dt = new DateTimeImmutable(trim($mysql), new DateTimeZone(self::DISPLAY_TZ));

            return $dt->format('Y-m-d\TH:i');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @return array{ok: bool, starts_at: ?string, ends_at: ?string, errors?: array<string, string>}
     */
    public static function parseAdminWindow(array $post): array
    {
        $errors = [];
        $starts = self::parseAdminField($post, 'starts_at', 'Start date', $errors);
        $ends = self::parseAdminField($post, 'ends_at', 'Expiry date', $errors);
        if ($starts !== null && $ends !== null) {
            try {
                $tz = new DateTimeZone(self::DISPLAY_TZ);
                $s = new DateTimeImmutable($starts, $tz);
                $e = new DateTimeImmutable($ends, $tz);
                if ($e <= $s) {
                    $errors['ends_at'] = 'Expiry must be later than start (Asia/Kolkata).';
                }
            } catch (Throwable $e) {
                $errors['ends_at'] = 'Expiry must be later than start (Asia/Kolkata).';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'starts_at' => $starts, 'ends_at' => $ends];
    }

    /** @param array<string, mixed> $post */
    private static function parseAdminField(array $post, string $key, string $label, array &$errors): ?string
    {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone(self::DISPLAY_TZ);
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $tz);
            if ($dt === false) {
                $errors[$key] = $label . ' is not valid.';

                return null;
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $errors[$key] = $label . ' is not valid.';

            return null;
        }
    }
}
