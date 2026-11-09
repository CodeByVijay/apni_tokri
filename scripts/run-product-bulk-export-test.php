<?php

declare(strict_types=1);

if (getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') === false) {
    putenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS=');
}

$php = PHP_BINARY;
passthru(
    escapeshellarg($php) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/run-in-disposable-db.php') . ' '
    . escapeshellarg('scripts/test-product-bulk-export.php'),
    $code
);
exit($code);
