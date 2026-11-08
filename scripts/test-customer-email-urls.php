<?php

declare(strict_types=1);

/**
 * Customer notification email links must be absolute (canonical Website URL), not url_path-only.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function okU(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function withBaseUrl(string $baseUrl, string $urlPath, callable $fn): void
{
    $cfg = App::config();
    App::setConfig(array_replace_recursive($cfg, [
        'app' => [
            'base_url' => $baseUrl,
            'url_path' => $urlPath,
        ],
    ]));
    try {
        $fn();
    } finally {
        App::setConfig($cfg);
    }
}

withBaseUrl('https://veggietokri.in', '', static function (): void {
    $order = 'AT-WRONG-BASE';
    $pay = OrderEmailContext::customerPaymentRetryUrl($order);
    okU(str_starts_with($pay, 'https://veggietokri.com/payment.php?order='), 'payment_retry_url ignores wrong app.base_url host');
    okU(!str_contains($pay, 'veggietokri.in'), 'payment_retry_url not on .in');
});

withBaseUrl('https://veggietokri.com', '', static function (): void {
    $order = 'AT-261105-FE25FF';
    $detail = OrderEmailContext::customerOrderDetailUrl($order);
    okU(str_starts_with($detail, 'https://veggietokri.com/'), 'Production order_url uses canonical base');
    okU(str_contains($detail, 'order-details.php?id=' . rawurlencode($order)), 'order_url uses order number query');
    okU(!str_contains($detail, 'order-details.php?id=6'), 'order_url does not use numeric internal id');

    $pay = OrderEmailContext::customerPaymentRetryUrl($order);
    okU(str_starts_with($pay, 'https://veggietokri.com/payment.php?order='), 'Production payment_retry_url');

    $list = OrderEmailContext::customerOrdersListUrl();
    okU($list === 'https://veggietokri.com/my-orders.php', 'Production orders_url');

    $payload = OrderEmailContext::enrichOrderPayload(6, [
        'order_number' => $order,
        'amount' => '1.00',
        'customer_name' => 'Test',
    ], null);
    okU(str_starts_with((string) ($payload['order_url'] ?? ''), 'https://veggietokri.com/'), 'enrichOrderPayload order_url absolute');
    okU(str_starts_with((string) ($payload['payment_retry_url'] ?? ''), 'https://veggietokri.com/'), 'enrichOrderPayload payment_retry_url absolute');
    okU(str_starts_with((string) ($payload['orders_url'] ?? ''), 'https://veggietokri.com/'), 'enrichOrderPayload orders_url absolute');

    foreach (['order.placed', 'payment.success', 'payment.failed', 'order.status'] as $event) {
        $sample = NotificationTemplateVariables::samplePreviewPayload($event);
        foreach (['order_url', 'payment_retry_url', 'orders_url'] as $key) {
            if (!isset($sample[$key]) || $sample[$key] === '') {
                continue;
            }
            okU(str_starts_with((string) $sample[$key], 'https://veggietokri.com/'), "Preview {$event} {$key} absolute");
        }
    }
});

withBaseUrl('http://localhost/Apni%20Tokri', '/Apni_Tokri', static function (): void {
    $order = 'AT-DEV-001';
    $detail = OrderEmailContext::customerOrderDetailUrl($order);
    okU(str_starts_with($detail, 'http://localhost/'), 'Local dev order_url preserves configured base');
    okU(str_contains($detail, 'order-details.php'), 'Local dev order_url has script path');
});

okU(
    !str_starts_with(OrderEmailContext::customerOrderDetailUrl('AT-X'), '/order-details'),
    'Default config must not emit root-relative-only order link for email'
);

echo $fail === 0 ? "All customer email URL tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
