<?php

declare(strict_types=1);

/**
 * Standalone layout for unauthenticated admin screens (login).
 * Intentionally does NOT use the admin sidebar/topbar shell in layout/admin.php
 * and does not touch AdminAuth::user() or admin permissions.
 */
function layout_auth_start(string $pageTitle): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= Security::escape(Csrf::token()) ?>">
    <title><?= Security::escape($pageTitle) ?> — Admin · Veggie Tokri</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= Security::escape(asset_url('assets/css/admin-auth.css')) ?>">
</head>
<body class="auth-body">
<?php
}

function layout_auth_end(): void
{
    echo '<script src="' . Security::escape(asset_url('assets/js/admin-auth.js')) . '"></script>';
    echo '</body></html>';
}
