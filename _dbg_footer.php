<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: text/plain');
echo "hasLocalConfig: "; var_dump(Install::hasLocalConfig());
try {
    $cfg = App::config()['database'] ?? [];
    Database::connect($cfg);
    echo "connect OK\n";
} catch (Throwable $e) { echo "connect ERR: ".$e->getMessage()."\n"; }
