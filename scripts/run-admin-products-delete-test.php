<?php

declare(strict_types=1);

/**
 * Products admin delete/archive regression (disposable DB).
 * Sets empty disposable MySQL password override when XAMPP root has no password.
 */
if (getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS') === false) {
    putenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS=');
}

$php = PHP_BINARY;
$argv = [$php, dirname(__DIR__) . '/scripts/run-in-disposable-db.php', 'scripts/test-admin-products-delete.php'];
passthru(implode(' ', array_map('escapeshellarg', $argv)), $code);
exit($code);
