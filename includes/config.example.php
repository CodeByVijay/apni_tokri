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
        /**
         * Environment separation (use a different config.local.php per deploy):
         *   local | development | dev     — demo catalogue/cart when DB is empty; test scripts allowed
         *   staging                       — real DSN; same guards as local unless you set database.role
         *   production | prod               — no session demo commerce, no demo catalogue fallback,
         *                                   simulated payments blocked, OTP debug hidden
         */
        'env' => 'local',
        'debug' => true,
        'timezone' => 'Asia/Kolkata',
        // Production: typically https://veggietokri.com with url_path '' (site at web root).
        // Local dev: if the project folder is still named Apni_Tokri, use e.g. base_url http://localhost/Apni_Tokri and url_path /Apni_Tokri
        'base_url' => 'https://veggietokri.com',
        'url_path' => '',
    ],

    /**
     * Use a separate database per environment (never point staging/dev at production data).
     * Optional database.role = production | prod — blocks scripts/test-*.php and seed-catalogue.php
     * even when app.env is not production (belt-and-braces for shared config mistakes).
     */
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'apni_tokri_dev',
        'user' => 'apni_tokri_user',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
        // 'role' => 'development',
    ],

    /**
     * Absolute path to the 32-byte Sodium master key file (0600).
     * Local/dev: may be left empty to use storage/secrets/master.key under the project (auto-created).
     * Production: required; must exist outside the web document root. Never auto-generate a new key.
     * Example: /var/lib/apni-tokri/secrets/master.key
     */
    'encryption' => [
        'master_key_path' => '',
    ],

    /**
     * Private invoice PDF storage (encrypted at rest). Directory mode 0750, files 0640.
     * Local/dev: may be left empty to use storage/invoices under the project.
     * Production: required; must be outside the web document root.
     * Example: /var/lib/apni-tokri/invoices
     */
    'storage' => [
        'invoices_path' => '',
    ],

    'session' => [
        'name' => 'APNI_TOKRI_SESSID',
        'lifetime' => 7200,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    /**
     * Optional: delivery-address map (Leaflet + OpenStreetMap). No API key is used or required.
     * Defaults to the public OSM tile server (see its usage policy); override to use your own tile host.
     */
    // 'map' => [
    //     'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    //     'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    //     'max_zoom' => 19,
    // ],

    /**
     * Address search (Photon) — server-side proxy at /ajax/address/photon-search. No API key.
     * Public https://photon.komoot.io is for reasonable demo use; for production scale set base_url
     * to your self-hosted Photon (https://github.com/komoot/photon) or Pelias instance.
     */
    // 'geocoding' => [
    //     'photon' => [
    //         'enabled' => true,
    //         'base_url' => 'https://photon.komoot.io/api/',
    //         'limit' => 7, // suggestions returned to the browser
    //         'fetch_limit' => 15, // per Photon request (merge of two passes)
    //         'lang' => 'en', // hi may work for some names; depends on OSM data
    //         'bbox' => '75.45,22.45,76.15,22.92', // min_lon,min_lat,max_lon,max_lat (Indore vicinity)
    //         'bias_lat' => 22.7196,
    //         'bias_lon' => 75.8577,
    //         'zoom' => 17, // location bias radius (Photon)
    //         'location_bias_scale' => 0.38, // 0–1, balance prominence vs proximity
    //         // Second pass: layered search (house/street/locality/…) with "Indore" appended when missing — disambiguates common names.
    //         // 'second_pass_layers' => ['house', 'street', 'locality', 'district', 'city'],
    //         'timeout_seconds' => 8,
    //         'cache_ttl_seconds' => 120,
    //         'max_requests_per_minute_per_ip' => 40, // applies per upstream request after cache miss (up to 2 per search)
    //     ],
    // ],

    'install' => [
        'expected_schema_version' => '1.10.0-stage14-rbac-1',
    ],

    /**
     * Security remediation (Phase 1). Apply database/migrations/1.21.0_security_remediation_phase1.sql
     * before relying on admin login throttling, password reset, or guest support rate limits.
     */
    'security' => [
        'admin_login' => [
            'max_failures_email' => 5,
            'max_failures_ip' => 20,
            'window_minutes' => 15,
            'lock_minutes' => 15,
        ],
        'admin_password_reset' => [
            'token_ttl_seconds' => 3600,
            'max_requests_email' => 3,
            'max_requests_ip' => 10,
            'window_minutes' => 60,
        ],
        'support_guest' => [
            'max_per_ip' => 5,
            'window_minutes' => 60,
        ],
    ],
];
