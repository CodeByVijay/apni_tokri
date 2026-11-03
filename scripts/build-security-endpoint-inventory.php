<?php

declare(strict_types=1);

/**
 * Static endpoint inventory for security audit (no HTTP, no DB).
 * CLI: php scripts/build-security-endpoint-inventory.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';

$root = dirname(__DIR__);
$entries = [];

/** @param list<string> $paths */
function classify_paths(array $paths, string $area, callable $meta): void
{
    global $entries;
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
        $entries[] = array_merge([
            'path' => '/' . $rel,
            'area' => $area,
            'script' => basename($path),
        ], $meta($path, $rel));
    }
}

$rootPhp = glob($root . '/*.php') ?: [];
classify_paths($rootPhp, 'storefront', static function (string $path, string $rel): array {
    $auth = str_contains($path, 'my-') || in_array(basename($path), ['checkout.php', 'payment.php', 'subscription-payment.php', 'subscription-details.php', 'invoice-download.php', 'invoice-view.php', 'order-details.php', 'refund-details.php', 'support-ticket.php', 'verify-otp.php'], true);
    $publicAlways = in_array(basename($path), ['index.php', 'login.php', 'logout.php', 'faqs.php', 'contact.php', 'page.php', 'product.php', 'category.php', 'search.php', 'cart.php', 'subscriptions.php', 'subscribe.php', 'health-check.php', 'install.php', 'robots.php', 'sitemap.php', 'styleguide.php', 'payment-failed.php'], true);

    return [
        'methods' => ['GET', 'POST'],
        'auth' => $auth ? 'customer_session' : ($publicAlways ? 'public' : 'mixed'),
        'authorization' => $auth ? 'customer_self' : 'public_or_guest',
        'csrf' => 'POST_forms_and_ajax',
    ];
});

$adminPhp = glob($root . '/admin/**/*.php') ?: [];
classify_paths($adminPhp, 'admin', static function (string $path, string $rel): array {
    $base = basename($path);
    $public = AdminGuard::isPublicScript($base);
    $pwOnly = in_array($base, ['change-password.php', 'logout.php'], true);

    return [
        'methods' => ['GET', 'POST'],
        'auth' => $public ? 'public' : 'admin_session',
        'authorization' => $public ? 'none' : ($pwOnly ? 'mandatory_password_change_window' : 'rbac_module'),
        'csrf' => $public ? 'login_post' : 'post_mutations',
    ];
});

$ajaxPhp = glob($root . '/ajax/**/*.php') ?: [];
classify_paths($ajaxPhp, 'ajax', static function (string $path, string $rel): array {
    $guestOk = str_starts_with($rel, 'ajax/cart/') || $rel === 'ajax/delivery/check-pin.php' || $rel === 'ajax/ping.php';

    return [
        'methods' => ['GET', 'POST'],
        'auth' => $guestOk ? 'session_optional' : 'customer_session',
        'authorization' => $guestOk ? 'cart_or_public' : 'customer_self',
        'csrf' => str_contains($rel, 'ping.php') ? 'post_only' : 'required',
    ];
});

foreach (['webhooks/razorpay.php', 'webhooks/cashfree.php'] as $rel) {
    $entries[] = [
        'path' => '/' . $rel,
        'area' => 'webhook',
        'script' => basename($rel),
        'methods' => ['POST'],
        'auth' => 'gateway_hmac',
        'authorization' => 'signature_valid',
        'csrf' => 'n/a',
    ];
}

foreach (glob($root . '/cron/*.php') ?: [] as $path) {
    $rel = 'cron/' . basename($path);
    $entries[] = [
        'path' => '/' . $rel,
        'area' => 'cron',
        'script' => basename($path),
        'methods' => ['CLI'],
        'auth' => 'cli_only_expected',
        'authorization' => 'AppEnvironment::assertCronEnvironment',
        'csrf' => 'n/a',
    ];
}

usort($entries, static fn ($a, $b) => strcmp($a['path'], $b['path']));

$out = [
    'generated_at' => date('c'),
    'endpoint_count' => count($entries),
    'notes' => 'Classification is static; verify auth/csrf on each script before release.',
    'endpoints' => $entries,
];

$jsonPath = $root . '/storage/security-audit-results.json';
$existing = is_readable($jsonPath) ? json_decode((string) file_get_contents($jsonPath), true) : [];
if (!is_array($existing)) {
    $existing = [];
}
$existing['endpoint_inventory'] = $out;
file_put_contents($jsonPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Wrote ' . count($entries) . ' endpoints to storage/security-audit-results.json' . "\n";
