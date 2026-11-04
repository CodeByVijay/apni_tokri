<?php

declare(strict_types=1);

/**
 * Run an existing test script against a throwaway database (fresh import of the production SQL),
 * so tests that normally use the configured DB never touch real data.
 *
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-subscription-stage11-phase-c.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$target = $argv[1] ?? '';
$targetPath = realpath(dirname(__DIR__) . '/' . ltrim($target, '/')) ?: realpath($target);
if ($targetPath === false || strpos($targetPath, dirname(__DIR__) . '/scripts/') !== 0) {
    fwrite(STDERR, "Usage: php scripts/run-in-disposable-db.php scripts/<test>.php\n");
    exit(2);
}

$db = App::config()['database'];
$fresh = 'apni_tokri_tmp_' . date('YmdHis') . '_' . bin2hex(random_bytes(2));
$mysql = static function (string $extra) use ($db): int {
    $cmd = 'mysql -h ' . escapeshellarg((string) $db['host']) . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $p = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($p !== '') {
        $cmd .= ' -p' . escapeshellarg($p);
    }
    exec($cmd . ' 2>/dev/null ' . $extra, $o, $code);

    return (int) $code;
};
if ($mysql('-e ' . escapeshellarg("CREATE DATABASE `{$fresh}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) !== 0
    || $mysql(escapeshellarg($fresh) . ' < ' . escapeshellarg(dirname(__DIR__) . '/database/production/apni_tokri_production.sql')) !== 0) {
    fwrite(STDERR, "Could not prepare disposable database.\n");
    exit(2);
}
register_shutdown_function(static function () use ($mysql, $fresh): void {
    Database::disconnect();
    $mysql('-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$fresh}`"));
});
App::setConfig(array_replace_recursive(App::config(), ['database' => array_merge($db, ['name' => $fresh])]));
Database::disconnect();
// Minimal baseline data that these suites assume exists in a developer database (catalogue + one customer).
Install::ensureConnection();
$seedPdo = Database::pdo();
$seedPdo->exec("INSERT INTO categories (name, slug) VALUES ('Vegetables','tmp-veg')");
$seedCat = (int) $seedPdo->lastInsertId();
foreach (['Tomato', 'Potato', 'Onion', 'Spinach'] as $i => $n) {
    $seedPdo->prepare("INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, 'published')")
        ->execute([$seedCat, $n, 'tmp-' . strtolower($n), 'TMP-' . $i]);
    $seedPid = (int) $seedPdo->lastInsertId();
    $seedPdo->prepare("INSERT INTO product_variants (product_id, name, sku, price, stock_qty) VALUES (?, '500 g', ?, 40, 1000)")
        ->execute([$seedPid, 'TMP-' . $i . '-500']);
}
$seedPdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['9700000001', 'Disposable Customer']);
$seedCust = (int) $seedPdo->lastInsertId();
$seedPdo->prepare('INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)')
    ->execute([$seedCust, 'Home', 'Test line', 'Indore', 'MP', '452001']);
echo "[disposable DB {$fresh}] running " . basename($targetPath) . "\n";
$argv = [$targetPath];
require $targetPath;
