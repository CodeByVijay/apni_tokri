<?php

declare(strict_types=1);

/**
 * Application configuration loader.
 * Local overrides: includes/config.local.php (not in Git).
 */

$configPath = __DIR__ . '/config.local.php';
$examplePath = __DIR__ . '/config.example.php';

if (is_readable($configPath)) {
    $config = require $configPath;
} elseif (is_readable($examplePath)) {
    $config = require $examplePath;
} else {
    throw new RuntimeException('Configuration file missing. Copy includes/config.example.php to includes/config.local.php.');
}

if (!is_array($config)) {
    throw new RuntimeException('Configuration must return an array.');
}

// Disposable DB subprocesses (see scripts/run-in-disposable-db.php + test_cli_exec_script).
if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1') {
    $db = &$config['database'];
    $name = getenv('APNI_TOKRI_TEST_DB_NAME');
    if (is_string($name) && $name !== '') {
        $db['name'] = $name;
    }
    foreach (['host' => 'APNI_TOKRI_TEST_DB_HOST', 'port' => 'APNI_TOKRI_TEST_DB_PORT', 'user' => 'APNI_TOKRI_TEST_DB_USER'] as $key => $envKey) {
        $v = getenv($envKey);
        if (is_string($v) && $v !== '') {
            $db[$key] = $key === 'port' ? (int) $v : $v;
        }
    }
    $pass = getenv('APNI_TOKRI_TEST_DB_PASS');
    if ($pass === false) {
        $pass = getenv('APNI_TOKRI_DISPOSABLE_MYSQL_PASS');
    }
    if ($pass !== false) {
        $db['pass'] = is_string($pass) ? $pass : '';
        $db['password'] = is_string($pass) ? $pass : '';
    }
}

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');

return $config;
