<?php

declare(strict_types=1);

/**
 * Read-only delivery zone compatibility audit (SELECT only).
 * Run: php7.3 scripts/audit-delivery-zones-readonly.php
 * Or:  php7.3 scripts/run-in-disposable-db.php scripts/audit-delivery-zones-readonly.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$mode = DeliveryZoneResolver::zoneResolutionMode();
$dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');

echo "=== Delivery zone audit (read-only) ===\n";
echo "Database: {$dbName}\n";
echo "Zone resolution mode: {$mode} (" . DeliveryAdminUi::modeLabel($mode) . ")\n\n";

$rows = $pdo->query('SELECT * FROM delivery_zones ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$issues = [
    'invalid_pincode_prefix_length' => [],
    'hybrid_missing_pincode' => [],
    'inactive' => [],
    'distance_ignored_in_pincode_mode' => [],
    'distance_band_incomplete' => [],
];

foreach ($rows as $z) {
    $id = (int) $z['id'];
    $label = "#{$id} " . ($z['name'] ?? '');
    $kind = (string) ($z['zone_kind'] ?? 'pincode');
    $pin = trim((string) ($z['match_value'] ?? ''));
    $active = !empty($z['is_active']);

    if (!$active) {
        $issues['inactive'][] = $label;
    }

    if (in_array($kind, ['pincode', 'hybrid'], true) && $pin !== '') {
        if (!preg_match('/^\d{1,6}$/', $pin)) {
            $issues['invalid_pincode_prefix_length'][] = $label . " (prefix: \"{$pin}\")";
        }
    }
    if ($kind === 'hybrid' && $pin === '') {
        $issues['hybrid_missing_pincode'][] = $label;
    }
    if ($kind === 'pincode' && $pin === '') {
        $issues['hybrid_missing_pincode'][] = $label . ' (pincode kind, empty prefix)';
    }

    if (in_array($kind, ['distance_band', 'hybrid'], true)) {
        $min = $z['min_distance_km'] ?? null;
        $max = $z['max_distance_km'] ?? null;
        if ($min === null || $max === null || $min === '' || $max === '') {
            $issues['distance_band_incomplete'][] = $label;
        }
        if ($mode === 'pincode_legacy' && $active) {
            $issues['distance_ignored_in_pincode_mode'][] = $label . ' (' . DeliveryAdminUi::kindLabel($kind) . ')';
        }
    }
}

foreach ($issues as $key => $list) {
    $title = str_replace('_', ' ', ucfirst($key));
    echo "--- {$title} (" . count($list) . ") ---\n";
    if ($list === []) {
        echo "  (none)\n";
    } else {
        foreach ($list as $line) {
            echo "  • {$line}\n";
        }
    }
    echo "\n";
}

echo "--- All zones summary ---\n";
foreach ($rows as $z) {
    echo sprintf(
        "  #%d %s | %s | %s | %s\n",
        (int) $z['id'],
        $z['name'] ?? '',
        DeliveryAdminUi::kindLabel((string) ($z['zone_kind'] ?? 'pincode')),
        !empty($z['is_active']) ? 'active' : 'inactive',
        DeliveryAdminUi::coverage($z)
    );
}

exit(0);
