<?php

declare(strict_types=1);

/**
 * Factory defaults for notification templates (restore + seed reference).
 * Keys match notification_templates.template_key.
 */
final class NotificationTemplateDefaults
{
    /** @return array<string, array{event_type: string, channel: string, subject: string, body_html: string, body_text: string}> */
    public static function all(): array
    {
        return [
            'order.placed.email' => [
                'event_type' => 'order.placed',
                'channel' => 'email',
                'subject' => 'Order {{order_number}} — complete payment',
                'body_html' => '<p>Hi {{customer_name}},</p><p>Thank you for shopping with {{site_name}}. Your order <strong>{{order_number}}</strong> is placed and awaiting payment of <strong>₹{{amount}}</strong>.</p><p>Complete payment securely via {{gateway_name}}:</p><p><a href="{{payment_retry_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">Pay now</a></p><p style="font-size:13px;color:#64748b;">You can also view your order anytime from <a href="{{order_url}}">order details</a>.</p>',
                'body_text' => 'Hi {{customer_name}}, order {{order_number}} (Rs {{amount}}) is awaiting payment. Pay at {{payment_retry_url}} or view {{order_url}}.',
            ],
            'order.status.email' => [
                'event_type' => 'order.status',
                'channel' => 'email',
                'subject' => 'Order {{order_number}} — {{status_label}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>Your order <strong>{{order_number}}</strong> is now: <strong>{{status_label}}</strong>.</p><p>Amount: ₹{{amount}}</p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p>',
                'body_text' => 'Order {{order_number}} status: {{status_label}}. View: {{order_url}}',
            ],
            'payment.success.email' => [
                'event_type' => 'payment.success',
                'channel' => 'email',
                'subject' => 'Payment received — order {{order_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>We received your payment of <strong>₹{{amount}}</strong> for order <strong>{{order_number}}</strong> via {{gateway_name}}.</p><p>Payment reference: <code>{{payment_reference}}</code></p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p>',
                'body_text' => 'Payment of Rs {{amount}} received for order {{order_number}} via {{gateway_name}}. Ref: {{payment_reference}}. {{order_url}}',
            ],
            'payment.failed.email' => [
                'event_type' => 'payment.failed',
                'channel' => 'email',
                'subject' => 'Payment issue — order {{order_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>Your payment for order <strong>{{order_number}}</strong> (₹{{amount}}) via {{gateway_name}} could not be completed.</p><p>You can try again securely:</p><p><a href="{{payment_retry_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">Retry payment</a></p><p style="font-size:13px;color:#64748b;">Need help? View <a href="{{order_url}}">order details</a>.</p>',
                'body_text' => 'Payment for order {{order_number}} (Rs {{amount}}) failed. Retry: {{payment_retry_url}}',
            ],
            'refund.initiated.email' => [
                'event_type' => 'refund.initiated',
                'channel' => 'email',
                'subject' => 'Refund initiated — {{request_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>We have initiated your refund <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong>{{amount_line}}.</p><p>Status: {{status_label}}</p><p style="font-size:13px;color:#64748b;">Refunds typically appear in your account within 5–7 business days depending on your bank.</p>',
                'body_text' => 'Refund {{request_number}} for order {{order_number}} has been initiated. Status: {{status_label}}.',
            ],
            'refund.completed.email' => [
                'event_type' => 'refund.completed',
                'channel' => 'email',
                'subject' => 'Refund completed — {{request_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>Your refund <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong> is complete{{amount_line}}.</p><p>If you do not see the amount yet, please allow a few days for your bank to post it.</p>',
                'body_text' => 'Refund {{request_number}} for order {{order_number}} is complete.',
            ],
            'refund.status_updated.email' => [
                'event_type' => 'refund.status_updated',
                'channel' => 'email',
                'subject' => 'Refund update — {{request_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>Your refund request <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong> status is now: <strong>{{status_label}}</strong>.</p>',
                'body_text' => 'Refund {{request_number}} (order {{order_number}}) status: {{status_label}}.',
            ],
            'support.ticket_created.email' => [
                'event_type' => 'support.ticket_created',
                'channel' => 'email',
                'subject' => 'Support ticket {{ticket_number}} received',
                'body_html' => '<p>Hi {{customer_name}},</p><p>We received your support request ({{ticket_number}}). Our team will respond soon.</p>',
                'body_text' => 'We received your support ticket {{ticket_number}}.',
            ],
            'support.ticket_replied.email' => [
                'event_type' => 'support.ticket_replied',
                'channel' => 'email',
                'subject' => 'Update on ticket {{ticket_number}}',
                'body_html' => '<p>Hi {{customer_name}},</p><p>There is a new reply on your support ticket {{ticket_number}}.</p>',
                'body_text' => 'New reply on support ticket {{ticket_number}}.',
            ],
        ];
    }

    /** @return array{event_type: string, channel: string, subject: string, body_html: string, body_text: string}|null */
    public static function forKey(string $templateKey): ?array
    {
        $all = self::all();

        return $all[$templateKey] ?? null;
    }
}
