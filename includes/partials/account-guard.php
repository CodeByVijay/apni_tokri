<?php

declare(strict_types=1);

/**
 * Guard for customer self-service pages (DB mode). Sets $customerId (int) from the session only.
 *
 * @var int $customerId
 */
if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '')), true, 302);
    exit;
}
$customerId = (int) CustomerAuth::id();
if ($customerId <= 0 || !CommerceGateway::useDatabase()) {
    layout_public_start(['pageTitle' => 'My account — Veggie Tokri', 'activeNav' => 'account']);
    echo '<div class="at-container py-10"><p>This page needs a signed-in account backed by the database.</p></div>';
    layout_public_end();
    exit;
}
