<?php

declare(strict_types=1);

if (getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') === false) {
    putenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS=');
}

$php = PHP_BINARY;
$argv = [
    $php,
    dirname(__DIR__) . '/scripts/run-in-disposable-db.php',
    '--migrate-media-library',
    'scripts/test-admin-products-list-catalogue.php',
];
passthru(implode(' ', array_map('escapeshellarg', $argv)), $code);
exit($code);
