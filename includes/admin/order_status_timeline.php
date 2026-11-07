<?php

declare(strict_types=1);

/**
 * Presentation mapping for admin order status history (read-only; does not alter stored rows).
 */
final class AdminOrderStatusTimeline
{
    public const TONE_COMPLETED = 'completed';
    public const TONE_PENDING = 'pending';
    public const TONE_FAILURE = 'failure';
    public const TONE_RESOLVED = 'resolved';

    /**
     * @param list<array{status?: string, note?: string, created_at?: string, admin_user_id?: int|null, admin_name?: string|null}> $rows
     * @return list<array{
     *   title: string,
     *   description: string,
     *   datetime: string,
     *   datetime_attr: string,
     *   tone: string,
     *   icon: string,
     *   is_latest: bool
     * }>
     */
    public static function present(array $rows): array
    {
        $out = [];
        $count = count($rows);
        foreach ($rows as $i => $row) {
            $status = trim((string) ($row['status'] ?? ''));
            $note = trim((string) ($row['note'] ?? ''));
            $createdAt = trim((string) ($row['created_at'] ?? ''));
            $adminName = trim((string) ($row['admin_name'] ?? ''));

            $title = self::eventTitle($status, $note);
            $tone = self::eventTone($status, $note);
            $icon = self::eventIcon($status, $note, $tone);

            $description = self::eventDescription($title, $note, $adminName);

            $out[] = [
                'title' => $title,
                'description' => $description,
                'datetime' => $createdAt !== '' ? customer_format_datetime($createdAt) : '—',
                'datetime_attr' => $createdAt,
                'tone' => $tone,
                'icon' => $icon,
                'is_latest' => $i === $count - 1,
            ];
        }

        return $out;
    }

    private static function eventTitle(string $status, string $note): string
    {
        if ($status === '' && $note !== '') {
            return self::shortenLeadSentence($note);
        }

        if (self::isGenericPlacedLabel($status) && $note !== '') {
            return self::shortenLeadSentence($note);
        }

        $norm = self::normalizeToken($status);
        if ($norm !== '' && in_array($norm, OrderStatus::all(), true)) {
            if ($note !== '' && strlen($note) < 120 && stripos($note, 'payment') !== false) {
                return self::shortenLeadSentence($note);
            }

            return self::orderStatusTitle($norm);
        }

        if ($status !== '') {
            return $status;
        }

        return $note !== '' ? self::shortenLeadSentence($note) : 'Status update';
    }

    private static function eventDescription(string $title, string $note, string $adminName): string
    {
        $parts = [];
        if ($note !== '' && !self::titlesEquivalent($title, $note)) {
            $parts[] = $note;
        }
        if ($adminName !== '') {
            $parts[] = 'Updated by ' . $adminName;
        }

        return implode("\n", $parts);
    }

    private static function eventTone(string $status, string $note): string
    {
        if (self::isResolvedExceptionStatus($status)) {
            return self::TONE_RESOLVED;
        }

        $norm = self::normalizeToken($status);
        $statusLc = strtolower($status);

        if (str_contains($statusLc, 'payment reconciliation required')
            || str_contains($statusLc, 'reconciliation required')) {
            return self::TONE_FAILURE;
        }

        if ($norm === OrderStatus::PAYMENT_FAILED
            || str_contains($statusLc, 'payment failed')
            || str_contains($statusLc, 'payment attempt failed')) {
            return self::TONE_FAILURE;
        }

        if ($norm === OrderStatus::DELIVERY_EXCEPTION) {
            return self::TONE_FAILURE;
        }

        if ($norm === OrderStatus::CANCELLED) {
            return self::TONE_FAILURE;
        }

        if (self::isPendingStatus($status, $norm)) {
            return self::TONE_PENDING;
        }

        if (str_contains($statusLc, 'payment reconciliation') && !str_contains($statusLc, 'required')) {
            return self::TONE_COMPLETED;
        }

        return self::TONE_COMPLETED;
    }

    private static function eventIcon(string $status, string $note, string $tone): string
    {
        $norm = self::normalizeToken($status);
        $blob = strtolower($status . ' ' . $note);

        if (str_contains($blob, 'payment')) {
            return 'wallet';
        }
        if ($norm === OrderStatus::OUT_FOR_DELIVERY || str_contains($blob, 'delivery')) {
            return 'truck';
        }
        if ($norm === OrderStatus::CANCELLED) {
            return 'circle-x';
        }

        return match ($tone) {
            self::TONE_FAILURE => 'alert-triangle',
            self::TONE_PENDING => 'clock',
            self::TONE_RESOLVED => 'shield',
            default => 'check',
        };
    }

    private static function isResolvedExceptionStatus(string $status): bool
    {
        $s = strtolower(trim($status));
        if (str_starts_with($s, 'exception —') || str_starts_with($s, 'exception -')) {
            return true;
        }

        return str_contains($s, 'exception dismissed');
    }

    private static function isPendingStatus(string $status, string $norm): bool
    {
        if ($norm === OrderStatus::PENDING_PAYMENT) {
            return true;
        }
        $s = strtolower(str_replace('_', ' ', $status));

        return str_contains($s, 'awaiting payment') || $s === 'pending' || $s === 'pending payment';
    }

    private static function isGenericPlacedLabel(string $status): bool
    {
        $norm = self::normalizeToken($status);

        return in_array($norm, ['order_placed', 'placed'], true)
            || strtolower(trim($status)) === 'order placed';
    }

    private static function normalizeToken(string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($status)));
    }

    private static function orderStatusTitle(string $norm): string
    {
        return match ($norm) {
            OrderStatus::PENDING_PAYMENT => 'Awaiting payment',
            OrderStatus::CONFIRMED, OrderStatus::ACCEPTED => 'Order confirmed',
            OrderStatus::PREPARING => 'Preparing order',
            OrderStatus::READY => 'Packed and ready',
            OrderStatus::OUT_FOR_DELIVERY => 'Out for delivery',
            OrderStatus::DELIVERED => 'Delivered',
            OrderStatus::CANCELLED => 'Order cancelled',
            OrderStatus::PAYMENT_FAILED => 'Payment failed',
            OrderStatus::DELIVERY_EXCEPTION => 'Delivery exception',
            OrderStatus::REFUNDED => 'Order refunded',
            OrderStatus::PARTIALLY_REFUNDED => 'Partially refunded',
            default => AdminOrderService::statusLabel($norm),
        };
    }

    private static function shortenLeadSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^([^.!?\n]{1,120}(?:[.!?]|$))/', $text, $m) === 1) {
            $lead = trim((string) $m[1]);
            if ($lead !== '') {
                return $lead;
            }
        }

        return mb_strlen($text) > 96 ? mb_substr($text, 0, 93) . '…' : $text;
    }

    private static function titlesEquivalent(string $title, string $note): bool
    {
        $t = strtolower(trim($title));
        $n = strtolower(trim($note));

        if ($t === $n) {
            return true;
        }

        return str_starts_with($n, $t) || str_starts_with($t, $n);
    }
}
