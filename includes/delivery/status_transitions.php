<?php

declare(strict_types=1);

final class DeliveryStatusTransitions
{
    /** @return list<string> */
    public static function allowedNext(string $current): array
    {
        $current = DeliveryStatus::normalize($current);
        if ($current === DeliveryStatus::DELIVERED || $current === DeliveryStatus::CANCELLED) {
            return [];
        }
        if ($current === DeliveryStatus::FAILED) {
            return [DeliveryStatus::PENDING_DISPATCH, DeliveryStatus::CANCELLED];
        }

        $map = [
            DeliveryStatus::PENDING_DISPATCH => [
                DeliveryStatus::READY,
                DeliveryStatus::DISPATCHED,
                DeliveryStatus::FAILED,
                DeliveryStatus::CANCELLED,
            ],
            DeliveryStatus::READY => [
                DeliveryStatus::DISPATCHED,
                DeliveryStatus::FAILED,
                DeliveryStatus::CANCELLED,
            ],
            DeliveryStatus::DISPATCHED => [
                DeliveryStatus::DELIVERED,
                DeliveryStatus::FAILED,
                DeliveryStatus::CANCELLED,
            ],
        ];

        return $map[$current] ?? [];
    }

    public static function canTransition(string $from, string $to, bool $isRetry = false): bool
    {
        $from = DeliveryStatus::normalize($from);
        $to = DeliveryStatus::normalize($to);
        if ($from === $to) {
            return false;
        }
        if ($isRetry && $from === DeliveryStatus::FAILED && $to === DeliveryStatus::PENDING_DISPATCH) {
            return true;
        }

        return in_array($to, self::allowedNext($from), true);
    }

    public static function smsEventForStatus(string $status): ?string
    {
        $status = DeliveryStatus::normalize($status);
        $map = [
            DeliveryStatus::READY => 'ready',
            DeliveryStatus::DISPATCHED => 'dispatched',
            DeliveryStatus::DELIVERED => 'delivered',
            DeliveryStatus::FAILED => 'failed',
        ];

        return $map[$status] ?? null;
    }
}
