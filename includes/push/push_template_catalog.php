<?php

declare(strict_types=1);

/**
 * Push template event types, placeholders, and seeded (system) template keys.
 */
final class PushTemplateCatalog
{
    /** @var list<string> */
    public const SYSTEM_TEMPLATE_KEYS = [
        'order.confirmed.push',
        'order.processing.push',
        'order.dispatched.push',
        'order.out_for_delivery.push',
        'order.delivered.push',
        'order.cancelled.push',
        'refund.status.push',
        'subscription.activated.push',
        'subscription.paused.push',
        'subscription.resumed.push',
        'announcement.general.push',
        'offer.promo.push',
        'coupon.promotional.push',
    ];

  /** @return array<string, list<string>> event_type => allowed placeholders */
    public static function placeholdersByEventType(): array
    {
        return [
            'payment.success' => ['customer_name', 'order_number', 'order_total', 'order_id'],
            'order.status' => ['customer_name', 'order_number', 'order_total', 'order_id', 'status_label'],
            'refund.status_updated' => ['customer_name', 'request_number', 'status_label'],
            'subscription.activated' => ['customer_name'],
            'subscription.paused' => ['customer_name'],
            'subscription.resumed' => ['customer_name'],
            'announcement' => ['title', 'body', 'click_url'],
            'admin.manual' => ['title', 'body', 'click_url'],
            'promotion' => ['title', 'body', 'click_url'],
            'promotion.coupon' => [
                'customer_name',
                'coupon_code',
                'discount_value',
                'minimum_order',
                'expiry_date',
                'click_url',
            ],
        ];
    }

    /** @return list<string> */
    public static function allowedEventTypes(): array
    {
        $types = array_keys(self::placeholdersByEventType());
        sort($types);

        return $types;
    }

    /** @return list<string> */
    public static function placeholdersForEventType(string $eventType): array
    {
        $map = self::placeholdersByEventType();

        return $map[$eventType] ?? [];
    }

    public static function isSystemTemplateKey(string $templateKey): bool
    {
        return in_array($templateKey, self::SYSTEM_TEMPLATE_KEYS, true);
    }

    /**
     * @return list<string>
     */
    public static function extractPlaceholders(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $m)) {
            return [];
        }
        $found = [];
        foreach ($m[1] as $p) {
            $found[strtolower((string) $p)] = true;
        }

        return array_keys($found);
    }

    /**
     * @param list<string> $fields title/body/click_url fragments to scan
     * @return list<string> unsupported placeholder names
     */
    public static function unsupportedPlaceholders(string $eventType, array $fields): array
    {
        $allowed = self::placeholdersForEventType($eventType);
        if ($allowed === []) {
            return ['Event type is not recognized for placeholders.'];
        }
        $used = [];
        foreach ($fields as $chunk) {
            foreach (self::extractPlaceholders((string) $chunk) as $p) {
                $used[$p] = true;
            }
        }
        $bad = [];
        foreach (array_keys($used) as $p) {
            if (!in_array($p, $allowed, true)) {
                $bad[] = $p;
            }
        }
        sort($bad);

        return $bad;
    }

    /** Template keys referenced by application code (not only migration seed). */
    public static function isBackendBoundTemplateKey(string $templateKey): bool
    {
        return self::isSystemTemplateKey($templateKey);
    }
}
