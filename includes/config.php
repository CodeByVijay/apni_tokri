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

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');

return $config;
