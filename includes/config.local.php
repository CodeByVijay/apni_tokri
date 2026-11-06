<?php

declare(strict_types=1);

/**
 * Copy this file to config.local.php and adjust values.
 * Never commit config.local.php or real secrets to version control.
 *
 * Production: place config.local.php and the Sodium master key outside
 * the public webroot when hosting layout allows.
 */

return [
    'app' => [
        'name' => 'Veggie Tokri',
        'tagline' => 'Freshness, Ready for Your Kitchen.',
        'env' => 'local', // local | staging | production
        'debug' => true,
        'timezone' => 'Asia/Kolkata',
        'base_url' => 'http://localhost/Apni_Tokri',
        'url_path' => '/Apni_Tokri',
    ],

    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'apni_tokri',
        'user' => 'root',
        'password' => '12345',
        'charset' => 'utf8mb4',
    ],

    /**
     * Absolute path to the 32-byte Sodium master key file (generated at install).
     * Example: /var/www/apni-tokri-secrets/master.key
     */
    'encryption' => [
        'master_key_path' => '',
    ],

    'session' => [
        'name' => 'APNI_TOKRI_SESSID',
        'lifetime' => 7200,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    'install' => [
        'expected_schema_version' => '1.20.0-invoices',
    ],
];
