<?php

declare(strict_types=1);

/**
 * Example AJAX endpoint — validates JSON envelope (Stage 0).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Csrf::requireValid();
}

if (Install::isApplicationInstalled() && !AdminAuth::check()) {
    Ajax::success(['status' => 'ok'], 'OK');
}

Ajax::success([
    'app' => (string) (App::config()['app']['name'] ?? 'Apni Tokri'),
    'status' => 'ok',
], 'OK');
