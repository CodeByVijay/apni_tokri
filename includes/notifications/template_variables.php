<?php

declare(strict_types=1);

final class NotificationTemplateVariables
{
    /** @return list<string> */
    public static function allowedForEventType(string $eventType): array
    {
        $common = ['customer_name', 'site_name'];
        $order = ['order_number', 'amount', 'gateway_name', 'payment_reference', 'payment_retry_url', 'order_url', 'orders_url', 'paid_at', 'event_date'];
        $status = ['status_label', 'status_from'];
        $refund = ['request_number', 'amount', 'amount_line', 'status_label', 'order_number'];
        $support = ['ticket_number'];

        switch ($eventType) {
            case 'order.placed':
                return array_values(array_unique(array_merge($common, $order)));
            case 'order.status':
                return array_values(array_unique(array_merge($common, $order, $status)));
            case 'payment.success':
            case 'payment.failed':
                return array_values(array_unique(array_merge($common, $order)));
            case 'refund.initiated':
            case 'refund.completed':
            case 'refund.status_updated':
                return array_values(array_unique(array_merge($common, $refund)));
            case 'support.ticket_created':
            case 'support.ticket_replied':
                return array_values(array_unique(array_merge($common, $support)));
            default:
                return $common;
        }
    }

    /** @return array<string, string> */
    public static function samplePreviewPayload(string $eventType): array
    {
        $sampleOrder = 'AT-SAMPLE-001';
        $payload = [
            'customer_name' => 'Sample Customer',
            'site_name' => trim((string) (AppSettings::get('app.name', 'Apni Tokri') ?? 'Apni Tokri')) ?: 'Apni Tokri',
            'order_number' => $sampleOrder,
            'amount' => '499.00',
            'gateway_name' => 'Cashfree',
            'payment_reference' => 'cf_sample_pay_12345',
            'payment_retry_url' => OrderEmailContext::customerPaymentRetryUrl($sampleOrder),
            'order_url' => OrderEmailContext::customerOrderDetailUrl($sampleOrder),
            'orders_url' => OrderEmailContext::customerOrdersListUrl(),
            'paid_at' => date('Y-m-d H:i:s'),
            'event_date' => date('d M Y, h:i A'),
            'status_label' => 'Out for delivery',
            'status_from' => 'Preparing',
            'request_number' => 'RF-SAMPLE-001',
            'amount_line' => ' of ₹250.00',
            'ticket_number' => 'TK-SAMPLE-001',
        ];

        $allowed = self::allowedForEventType($eventType);
        $out = [];
        foreach ($allowed as $key) {
            $out[$key] = $payload[$key] ?? '';
        }

        return $out;
    }

    public static function eventTypeLabel(string $eventType): string
    {
        return ucwords(str_replace(['.', '_'], [' · ', ' '], $eventType));
    }

    /**
     * @return list<string> Unknown variable names used in template text.
     */
    public static function findUnknownVariables(string $text, string $eventType): array
    {
        $allowed = self::allowedForEventType($eventType);
        $allowedMap = array_fill_keys($allowed, true);
        $unknown = [];
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $m)) {
            foreach ($m[1] as $var) {
                if (!isset($allowedMap[$var])) {
                    $unknown[$var] = true;
                }
            }
        }

        return array_keys($unknown);
    }
}
