<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

/**
 * @param array<string, mixed> $session
 * @return array{code: int, headers: list<string>, body: string}
 */
function run_admin_endpoint(array $session, string $query = ''): array
{
    $php = test_cli_php_binary();
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/at_udym_cert_' . getmypid();
    @mkdir($dir, 0700, true);
    $script = 'admin/settings/udyam-certificate.php';
    $code = <<<'PHP'
<?php
$root = $argv[1];
$cfg = json_decode($argv[2], true);
session_save_path($cfg['dir']);
session_id('udyamcert');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/' . $cfg['script'];
$qs = (string) ($cfg['query'] ?? '');
$_SERVER['QUERY_STRING'] = $qs;
$_SERVER['REQUEST_URI'] = '/' . $cfg['script'] . ($qs !== '' ? '?' . $qs : '');
if ($qs !== '') {
    parse_str($qs, $_GET);
} else {
    $_GET = [];
}
require $root . '/includes/bootstrap.php';
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}
$_SESSION = array_merge($_SESSION, $cfg['session']);
register_shutdown_function(function () {
    echo "\nSTATE:" . json_encode([
        'code' => http_response_code(),
        'hdr' => headers_list(),
    ]) . "\n";
});
chdir($root);
require $root . '/' . $cfg['script'];
PHP;
    $hf = $dir . '/harness.php';
    file_put_contents($hf, $code);
    $cfg = json_encode(['dir' => $dir, 'script' => $script, 'query' => $query, 'session' => $session]);
    $out = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($hf) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($cfg) . ' 2>&1', $out);
    $text = implode("\n", $out);
    $state = null;
    if (preg_match('/STATE:(\{.*\})/s', $text, $m)) {
        $state = json_decode($m[1], true);
    }
    $body = preg_replace('/\nSTATE:\{.*\}\s*$/s', '', $text) ?? $text;

    return [
        'code' => is_array($state) ? (int) ($state['code'] ?? 0) : 0,
        'headers' => is_array($state) ? (array) ($state['hdr'] ?? []) : [],
        'body' => $body,
    ];
}

ok(InvoiceStorage::readAdminAssetPdf('not-a-valid-name.pdf')['ok'] === false, 'Reject invalid stored filename');
ok(InvoiceStorage::readAdminAssetPdf('../secrets.pdf')['ok'] === false, 'Reject path traversal filename');

$origCertKey = AppSettings::get(BusinessSettings::KEY_UDYAM_CERTIFICATE_FILE, null);
$pdfBytes = "%PDF-1.4\n% udyam certificate fixture\n%%EOF\n";
$storedName = bin2hex(random_bytes(12)) . '.pdf';
$dest = InvoiceStorage::assetDir() . '/' . $storedName;
file_put_contents($dest, $pdfBytes);
@chmod($dest, 0640);
AppSettings::set(BusinessSettings::KEY_UDYAM_CERTIFICATE_FILE, $storedName);
AppSettings::resetCache();

$read = InvoiceStorage::readAdminAssetPdf($storedName);
ok($read['ok'] === true && ($read['bytes'] ?? '') === $pdfBytes, 'Read stored certificate by settings filename only');

$guest = run_admin_endpoint([]);
ok($guest['code'] === 302, 'Unauthenticated request denied (redirect to login)');

$limited = run_admin_endpoint(['admin_user_id' => 999999]);
ok($limited['code'] === 403 || $limited['code'] === 302, 'Non-settings admin cannot stream certificate');

$adminSession = [
    'admin_user_id' => 1,
    'admin_user_email' => 'qa@test',
    'admin_user_name' => 'QA',
    'admin_auth_epoch' => 1,
];

$view = run_admin_endpoint($adminSession, 'inline=1');
$viewHdr = strtolower(implode("\n", $view['headers']));
ok($view['code'] === 200, 'Authenticated admin can view certificate');
ok(str_contains($viewHdr, 'content-type: application/pdf'), 'View uses PDF content type');
ok(str_contains($viewHdr, 'inline') && str_contains($viewHdr, 'udyam-certificate'), 'View uses inline disposition');
ok(strncmp($view['body'], '%PDF-', 5) === 0, 'View body is PDF bytes');

$download = run_admin_endpoint($adminSession, '');
$dlHdr = strtolower(implode("\n", $download['headers']));
ok($download['code'] === 200, 'Authenticated admin can download certificate');
ok(str_contains($dlHdr, 'attachment') && str_contains($dlHdr, 'udyam-certificate'), 'Download uses attachment disposition');
ok(strncmp($download['body'], '%PDF-', 5) === 0, 'Download body is PDF bytes');

$indexSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/index.php');
ok(str_contains($indexSrc, 'udyam-certificate.php?inline=1'), 'Business profile links to view endpoint');
ok(str_contains($indexSrc, 'udyam-certificate.php"'), 'Business profile links to download endpoint');
ok(!str_contains($indexSrc, 'InvoiceStorage::assetDir'), 'Business profile does not expose storage path');

AppSettings::set(BusinessSettings::KEY_UDYAM_CERTIFICATE_FILE, $origCertKey);
AppSettings::resetCache();
@unlink($dest);

echo $fail === 0 ? "PASS: udyam certificate download.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
