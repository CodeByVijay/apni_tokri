<?php

declare(strict_types=1);

/**
 * Guest vs authenticated customer account shell and page guards.
 * CLI: php scripts/test-customer-account-shell-security.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okCas(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
    }
}

function httpCas(string $url, ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($cookieJar !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['code' => $code, 'body' => substr($raw, $hs), 'headers' => substr($raw, 0, $hs)];
}

$base = rtrim((string) (App::config()['app']['base_url'] ?? ''), '/');
if ($base === '' || !function_exists('curl_init')) {
    fwrite(STDERR, "Need app.base_url and curl.\n");
    exit(1);
}

$guestJar = sys_get_temp_dir() . '/at-guest-shell-' . getmypid() . '.jar';
@unlink($guestJar);

$support = httpCas($base . '/support.php', $guestJar);
okCas($support['code'] === 200, 'Guest GET support.php returns 200');
okCas(str_contains($support['body'], 'Open a ticket'), 'Guest sees support form');
okCas(!str_contains($support['body'], 'at-account-nav__logout'), 'Guest support: no logout control in account nav');
okCas(!preg_match('/at-account-nav[^>]*>[\s\S]*?my-orders\.php/', $support['body']), 'Guest support: no orders link in account nav');
okCas(str_contains($support['body'], 'Login / Register') || str_contains($support['body'], '/login.php'), 'Guest support: login CTA shown');

$protected = [
    '/my-profile.php',
    '/my-addresses.php',
    '/my-orders.php',
    '/my-invoices.php',
    '/my-refunds.php',
    '/my-subscriptions.php',
    '/submit-review.php',
    '/my-support.php',
];
foreach ($protected as $path) {
    $r = httpCas($base . $path, $guestJar);
    $loc = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $r['headers'], $m)) {
        $loc = trim($m[1]);
    }
    okCas($r['code'] === 302 && str_contains($loc, 'login.php'), 'Guest redirected from ' . $path);
}

// Guest POST logout only affects empty session (must not 500).
if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $support['body'], $csrfM)) {
    $ch = curl_init($base . '/logout.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '_csrf_token=' . rawurlencode($csrfM[1]),
        CURLOPT_COOKIEJAR => $guestJar,
        CURLOPT_COOKIEFILE => $guestJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $logoutCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    okCas($logoutCode === 303 || $logoutCode === 302, 'Guest POST logout returns redirect (no error)');
} else {
    okCas(false, 'Extract CSRF for guest logout probe');
}

$guestRefund = SupportTicketService::create(null, 'refund', 'x', 'y', null);
okCas(empty($guestRefund['ok']), 'Service denies guest refund ticket (server-side)');

echo "\nSUMMARY pass={$pass} fail={$fail}\n";
@unlink($guestJar);
exit($fail === 0 ? 0 : 1);
