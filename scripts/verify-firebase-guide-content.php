<?php

declare(strict_types=1);

/**
 * Static checklist — Firebase setup guide strings present in partial.
 * CLI: php scripts/verify-firebase-guide-content.php
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$path = dirname(__DIR__) . '/includes/partials/admin/firebase-setup-guide.php';
$html = file_get_contents($path);
if ($html === false) {
    fwrite(STDERR, "FAIL cannot read guide partial\n");
    exit(1);
}

$required = [
    'Firebase Setup Guide',
    'console.firebase.google.com',
    'Web Push certificates',
    'google-services.json',
    'android/app/google-services.json',
    'Service accounts',
    'Firebase Installation ID',
    'target_type=fcm_token',
    'Dry run ON',
    'Website testing checklist',
    'Foreground',
    'Permission denied',
    '13.2.0',
    'firebase-messaging-sw.js',
];

$ok = 0;
$fail = 0;
foreach ($required as $needle) {
    if (str_contains($html, $needle)) {
        ++$ok;
        fwrite(STDOUT, "OK  contains: {$needle}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL missing: {$needle}\n");
    }
}

fwrite(STDOUT, "\n{$ok} passed, {$fail} failed\n");
exit($fail > 0 ? 1 : 0);
