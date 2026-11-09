<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') === false) {
    putenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS=');
}

passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/test-admin-http-cache.php'),
    $code
);
exit($code);
