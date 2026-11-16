<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ok = 0;
$fail = 0;

function progOk(bool $c, string $l): void
{
    global $ok, $fail;
    if ($c) {
        ++$ok;
        fwrite(STDOUT, "OK  {$l}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$l}\n");
    }
}

$row = [
    'id' => 1,
    'name' => 'Test',
    'firebase_project_id' => 'demo',
    'service_account_client_email' => '',
    'last_test_status' => 'unknown',
    'is_active_global' => 0,
    'status' => 'draft',
    'web_api_key' => '',
];
$p = FirebaseSetupProgress::build($row, ['web' => true, 'android' => false]);
progOk(($p['shared'][0]['status'] ?? '') === 'done', 'saved name+project id marks project details done');
progOk(($p['shared'][1]['status'] ?? '') === 'pending', 'missing service account pending');
progOk(($p['website'][0]['status'] ?? '') === 'manual', 'firebase console web registration is manual');
$browserManual = false;
foreach ($p['website'] as $item) {
    if (($item['id'] ?? '') === 'browser_test' && ($item['status'] ?? '') === 'manual') {
        $browserManual = true;
        break;
    }
}
progOk($browserManual, 'browser test is manual');

$rowComplete = array_merge($row, [
    'service_account_client_email' => 'a@b.iam.gserviceaccount.com',
    'last_test_status' => 'passed',
    'firebase_project_number' => '123',
    'web_api_key' => 'k',
    'web_app_id' => '1:123:web:x',
    'web_auth_domain' => 'd',
    'web_storage_bucket' => 'b',
    'web_measurement_id' => 'G-x',
    'vapid_public_key' => 'V',
]);
$p2 = FirebaseSetupProgress::build($rowComplete, ['web' => true, 'android' => false]);
progOk(($p2['shared'][1]['status'] ?? '') === 'done', 'service account stored');
progOk(($p2['shared'][2]['status'] ?? '') === 'done', 'credentials passed');
progOk(($p2['website'][1]['status'] ?? '') === 'done', 'web config complete when all fields set');

$p3 = FirebaseSetupProgress::build($rowComplete, ['web' => false, 'android' => true]);
progOk($p3['website'] === [], 'website progress hidden when web platform off');

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
