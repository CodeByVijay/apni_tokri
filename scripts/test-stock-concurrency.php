<?php

declare(strict_types=1);

/**
 * Concurrent stock decrement test — only one of two competing decrements should succeed
 * when stock is 1.
 * CLI: php scripts/test-stock-concurrency.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/inventory.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_repository.php';

if (!Install::isSchemaReady() || ProductRepository::productCount() === 0) {
    fwrite(STDERR, "Run seed-catalogue.php first.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$variant = $pdo->query(
    'SELECT id FROM product_variants WHERE is_active = 1 ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$variant) {
    fwrite(STDERR, "No variant found.\n");
    exit(1);
}
$vid = (int) $variant['id'];
$pdo->prepare('UPDATE product_variants SET stock_qty = 1 WHERE id = ?')->execute([$vid]);

$ok1 = InventoryService::decrement($vid, 1, 'concurrency test A');
$ok2 = InventoryService::decrement($vid, 1, 'concurrency test B');

$final = InventoryService::availableQty($vid);

if ($ok1 && !$ok2 && $final === 0) {
    echo "PASS: concurrent oversell prevented (first ok, second rejected, final stock 0).\n";
    exit(0);
}

echo "FAIL: ok1=" . ($ok1 ? '1' : '0') . " ok2=" . ($ok2 ? '1' : '0') . " final=$final\n";
exit(1);
