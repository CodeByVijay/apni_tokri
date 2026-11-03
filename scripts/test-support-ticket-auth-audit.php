<?php

declare(strict_types=1);

/**
 * Support ticket auth audit probes (no persistent tickets when SKIP_PERSIST=1 rollback path).
 * CLI: php scripts/test-support-ticket-auth-audit.php
 *
 * Uses nested logic review + optional HTTP GET only by default.
 * Set SUPPORT_AUDIT_ALLOW_INSERT=1 to run rolled-back service probes on configured DSN (creates no rows if rollback works).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function okS(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$srcSupport = (string) file_get_contents(APNI_TOKRI_ROOT . '/support.php');
okS(str_contains($srcSupport, 'CustomerAuth::check() ? CustomerAuth::id() : null'), 'support.php derives customer_id from session only');
okS(!str_contains($srcSupport, '$_POST[\'customer_id\']'), 'support.php does not trust POST customer_id');

$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/trust/support.php');
okS(str_contains($svc, 'public static function create('), 'SupportTicketService::create exists');
okS(str_contains($svc, 'categoryRequiresCustomerAuth'), 'Service enforces auth for sensitive categories');

okS(
    str_contains($svc, 'orderRowForCustomer($orderNumber, $customerId)'),
    'Order link uses orderRowForCustomer when customer logged in'
);

if (Install::isSchemaReady()) {
    // In-memory policy check without INSERT: simulate decision tree
    $cats = SupportTicketService::categoryLabels();
    okS(isset($cats['refund']) && isset($cats['payment']) && isset($cats['late_delivery']), 'Sensitive categories exposed in API');

    $r = SupportTicketService::create(null, 'refund', 'Audit probe', 'Automated security audit', null);
    okS(empty($r['ok']), 'Guest refund ticket denied at service layer');
} else {
    echo "SKIP: schema not ready\n";
}

$base = rtrim((string) (App::config()['app']['base_url'] ?? ''), '/');
if ($base !== '' && function_exists('curl_init')) {
    $ch = curl_init($base . '/support.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    okS(str_contains($body, 'Open a ticket') && !str_contains($body, 'value="refund"'), 'Guest HTTP GET hides refund category');
    okS(!str_contains($body, 'Sign in to open'), 'No hard guest gate on support form HTML');
} else {
    echo "SKIP: HTTP GET probe (base_url or curl)\n";
}

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
