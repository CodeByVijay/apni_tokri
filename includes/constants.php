<?php

declare(strict_types=1);

/**
 * Shared status and enum-like constants for Veggie Tokri.
 * Extend in later stages; keep values stable for DB and API contracts.
 */

// Order statuses (Section 14.1)
final class OrderStatus
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAYMENT_FAILED = 'payment_failed';
    public const CONFIRMED = 'confirmed';
    public const ACCEPTED = 'accepted';
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';
    public const DELIVERY_EXCEPTION = 'delivery_exception';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::PAYMENT_FAILED,
            self::CONFIRMED,
            self::ACCEPTED,
            self::PREPARING,
            self::READY,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
            self::CANCELLED,
            self::DELIVERY_EXCEPTION,
            self::PARTIALLY_REFUNDED,
            self::REFUNDED,
        ];
    }
}

// Refund request statuses (Section 16.3)
final class RefundStatus
{
    public const REQUESTED = 'requested';
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const REFUND_INITIATED = 'refund_initiated';
    public const PROCESSING = 'processing';
    public const REFUNDED = 'refunded';
    public const REFUND_FAILED = 'refund_failed';
    public const CANCELLED = 'cancelled';
}

// Product review statuses (Section 18.2)
final class ReviewStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
}

// Support ticket statuses (Section 19.3)
final class TicketStatus
{
    public const OPEN = 'open';
    public const ASSIGNED = 'assigned';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
}

// Subscription delivery statuses (Section 17.7)
final class SubscriptionDeliveryStatus
{
    public const SCHEDULED = 'scheduled';
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const SKIPPED_CARRY_FORWARD = 'skipped_carry_forward';
    public const CANCELLED_EXCEPTION = 'cancelled_exception';
}

// Delivery row statuses (D3 manual courier — primary fulfilment tracking)
final class DeliveryStatus
{
    public const PENDING_DISPATCH = 'pending_dispatch';
    public const READY = 'ready';
    public const DISPATCHED = 'dispatched';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING_DISPATCH,
            self::READY,
            self::DISPATCHED,
            self::DELIVERED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    public static function normalize(string $status): string
    {
        $map = [
            'queued' => self::PENDING_DISPATCH,
            'assigned' => self::PENDING_DISPATCH,
            'out_for_delivery' => self::DISPATCHED,
            'in_transit' => self::DISPATCHED,
        ];

        return $map[$status] ?? $status;
    }

    /** Customer-safe label for timeline */
    public static function publicLabel(string $status): string
    {
        $status = self::normalize($status);
        $labels = [
            self::PENDING_DISPATCH => 'Order confirmed',
            self::READY => 'Packed / ready',
            self::DISPATCHED => 'Dispatched',
            self::DELIVERED => 'Delivered',
            self::FAILED => 'Delivery could not be completed',
            self::CANCELLED => 'Delivery cancelled',
        ];

        return $labels[$status] ?? 'Update';
    }
}

// Payment record statuses
final class PaymentStatus
{
    public const CREATED = 'created';
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
}

// Generic publish states for CMS/products (Stage 6+)
final class PublishStatus
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';
}
