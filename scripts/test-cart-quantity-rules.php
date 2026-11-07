<?php

declare(strict_types=1);

/**
 * Cart quantity min/max rules (unit-level + optional DB).
 * CLI: php scripts/test-cart-quantity-rules.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function t(bool $ok, string $label): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

function v(array $variant, int $qty): array
{
    return CartQuantityRules::validateForCatalogueVariant($variant, $qty);
}

$variant = static function (int $stock, ?int $maxPer, int $minPer = 1): array {
    return [
        'stock' => $stock,
        'max_qty_per_order' => $maxPer,
        'min_qty_per_order' => $minPer,
    ];
};

// Max rules (unchanged)
$r = v($variant(100, 20), 20);
t($r['ok'] === true && ($r['cap'] ?? 0) === 20, 'stock 100 limit 20 accepts 20');
$r = v($variant(100, 20), 21);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Maximum 20'), 'stock 100 limit 20 rejects 21');

$r = v($variant(8, 20), 8);
t($r['ok'] === true, 'stock 8 limit 20 accepts 8');
$r = v($variant(8, 20), 9);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Only 8'), 'stock 8 limit 20 rejects 9');

$r = v($variant(0, 20), 1);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'out of stock'), 'stock 0 rejects add');

// Min rules
$r = v($variant(100, 20, 1), 1);
t($r['ok'] === true, 'min 1 accepts qty 1');
$r = v($variant(100, 20, 2), 1);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Minimum 2'), 'min 2 rejects qty 1');
$r = v($variant(100, 20, 2), 2);
t($r['ok'] === true, 'min 2 accepts qty 2');
$r = v($variant(100, 20, 3), 2);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Minimum 3'), 'min 3 rejects qty 2');

// Min + max
$r = v($variant(100, 10, 2), 10);
t($r['ok'] === true, 'min 2 max 10 accepts 10');
$r = v($variant(100, 10, 2), 1);
t($r['ok'] === false, 'min 2 max 10 rejects 1');

// Stock below min
$r = v($variant(2, 20, 3), 2);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Minimum 3'), 'stock below configured min');

// Unlimited max
$r = v($variant(500, null, 1), 500);
t($r['ok'] === true, 'unlimited max accepts 500');

t(CartQuantityRules::minPerOrderFromCatalogueVariant($variant(1, 20, 2)) === 2, 'catalogue min read');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    foreach (['1.32.0_variant_max_qty_per_order.sql', '1.33.0_variant_min_qty_per_order.sql'] as $file) {
        try {
            $pdo->exec((string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/' . $file));
        } catch (Throwable $e) {
            // column may already exist
        }
    }
}

echo "All cart quantity rule tests passed.\n";
