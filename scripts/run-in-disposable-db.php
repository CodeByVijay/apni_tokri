<?php

declare(strict_types=1);

/**
 * Run an existing test script against a throwaway database (fresh import of the production SQL).
 *
 * CLI:
 *   php scripts/run-in-disposable-db.php scripts/<test>.php
 *   php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library.php
 *
 * Local CLI credential mismatch (e.g. XAMPP empty root password): set
 * APNI_TOKRI_DISPOSABLE_MYSQL_PASS in the environment before running (empty string is allowed).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';

$migrateMediaLibrary = false;
$migrateRememberLogin = false;
$migrateFirebasePush = false;
$migrateAdminAssisted = false;
$targetArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--migrate-media-library') {
        $migrateMediaLibrary = true;
        continue;
    }
    if ($arg === '--migrate-remember') {
        $migrateRememberLogin = true;
        continue;
    }
    if ($arg === '--migrate-firebase-push') {
        $migrateFirebasePush = true;
        continue;
    }
    if ($arg === '--migrate-admin-assisted') {
        $migrateAdminAssisted = true;
        continue;
    }
    if ($targetArg === '' && $arg !== '' && $arg[0] !== '-') {
        $targetArg = $arg;
    }
}

$target = $targetArg;
$root = dirname(__DIR__);
$targetPath = realpath($root . '/' . ltrim(str_replace('\\', '/', $target), '/')) ?: ($target !== '' ? realpath($target) : false);
$scriptsPrefix = str_replace('\\', '/', $root) . '/scripts/';
$targetNorm = $targetPath !== false ? str_replace('\\', '/', $targetPath) : '';
if ($targetPath === false || !str_starts_with($targetNorm, $scriptsPrefix)) {
    fwrite(STDERR, "Usage: php scripts/run-in-disposable-db.php [--migrate-media-library] scripts/<test>.php\n");
    exit(2);
}

$needsMediaMigration = in_array(
    basename($targetPath),
    [
        'test-media-library.php',
        'test-media-library-concurrent.php',
        'test-media-library-trash.php',
        'test-media-library-bulk.php',
        'test-product-bulk-import.php',
        'test-admin-products-list-catalogue.php',
        'test-media-library-zip-upload.php',
    ],
    true
);
if ($needsMediaMigration && !$migrateMediaLibrary) {
    fwrite(STDERR, "Hint: Media Library tests require --migrate-media-library (production SQL has no media_assets).\n");
    exit(2);
}

$db = App::config()['database'];
$db = disposable_apply_cli_credential_override($db);
$originalAppDb = (string) ($db['name'] ?? '');
$fresh = disposable_generate_tmp_db_name();

$created = false;
$imported = false;

try {
    if (disposable_mysql_exec($db, '-e ' . escapeshellarg(
        "CREATE DATABASE `{$fresh}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    )) !== 0) {
        throw new RuntimeException('CREATE DATABASE failed for disposable DB.');
    }
    $created = true;

    $sqlFile = $root . '/database/production/apni_tokri_production.sql';
    if (disposable_import_sql_file($db, $fresh, $sqlFile) !== 0) {
        throw new RuntimeException('Import of production SQL into disposable DB failed.');
    }
    $imported = true;
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not prepare disposable database: ' . $e->getMessage() . "\n");
    if ($created && disposable_is_tmp_db_name($fresh) && $fresh !== $originalAppDb) {
        disposable_mysql_exec($db, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$fresh}`"));
    }
    exit(2);
}

register_shutdown_function(static function () use ($db, $fresh, $originalAppDb): void {
    try {
        Database::disconnect();
    } catch (Throwable $e) {
        // ignore
    }
    if (disposable_refuse_drop($fresh, $originalAppDb)) {
        return;
    }
    disposable_mysql_exec($db, '-e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$fresh}`"));
});

$mergedDb = array_merge($db, ['name' => $fresh]);
App::setConfig(array_replace_recursive(App::config(), ['database' => $mergedDb]));
Database::disconnect();

putenv('APNI_TOKRI_DISPOSABLE_DB=1');
putenv('APNI_TOKRI_TEST_DB_NAME=' . $fresh);
putenv('APNI_TOKRI_ORIGINAL_DB_NAME=' . $originalAppDb);
putenv('APNI_TOKRI_TEST_DB_HOST=' . (string) ($mergedDb['host'] ?? '127.0.0.1'));
putenv('APNI_TOKRI_TEST_DB_PORT=' . (string) ($mergedDb['port'] ?? 3306));
putenv('APNI_TOKRI_TEST_DB_USER=' . (string) ($mergedDb['user'] ?? 'root'));
$passExport = (string) ($mergedDb['pass'] ?? $mergedDb['password'] ?? '');
putenv('APNI_TOKRI_TEST_DB_PASS=' . $passExport);
$_ENV['APNI_TOKRI_TEST_DB_PASS'] = $passExport;

Install::ensureConnection();
$pdo = Database::pdo();
$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($activeDb !== $fresh) {
    fwrite(STDERR, "ABORT: application connected to `{$activeDb}` instead of disposable `{$fresh}`.\n");
    exit(2);
}

if ($migrateMediaLibrary) {
    $migration = $root . '/database/migrations/1.37.0_media_library.sql';
    fwrite(STDERR, "[disposable DB {$fresh}] applying migration 1.37.0_media_library.sql\n");
    $mig = disposable_apply_migration_file($pdo, $fresh, $migration);
    if (empty($mig['ok'])) {
        fwrite(STDERR, 'Migration failed: ' . ($mig['message'] ?? 'unknown') . "\n");
        exit(2);
    }
}

$firebasePushTest = in_array(
    basename($targetPath),
    [
        'test-firebase-push-integration.php',
        'test-firebase-push-schema.php',
        'test-firebase-push-migration-idempotent.php',
    ],
    true
);
if ($migrateFirebasePush || $firebasePushTest) {
    foreach (disposable_firebase_push_migration_paths() as $migration) {
        fwrite(STDERR, "[disposable DB {$fresh}] applying migration " . basename($migration) . "\n");
        $mig = disposable_apply_migration_file($pdo, $fresh, $migration);
        if (empty($mig['ok'])) {
            fwrite(STDERR, 'Migration failed: ' . ($mig['message'] ?? 'unknown') . "\n");
            exit(2);
        }
    }
}

$adminAssistedTest = in_array(basename($targetPath), [
    'test-admin-assisted-order.php',
    'test-admin-assisted-order-quote.php',
    'test-admin-assisted-order-placement.php',
    'test-admin-assisted-order-phase5.php',
    'test-admin-assisted-order-address.php',
    'test-admin-assisted-order-final-ux.php',
    'test-storefront-canonical-urls.php',
], true);
if ($migrateAdminAssisted || $adminAssistedTest) {
    $migration = $root . '/database/migrations/1.40.0_admin_assisted_orders.sql';
    fwrite(STDERR, "[disposable DB {$fresh}] applying migration 1.40.0_admin_assisted_orders.sql\n");
    $mig = disposable_apply_migration_file($pdo, $fresh, $migration);
    if (empty($mig['ok'])) {
        fwrite(STDERR, 'Migration failed: ' . ($mig['message'] ?? 'unknown') . "\n");
        exit(2);
    }
    $migration41 = $root . '/database/migrations/1.41.0_admin_assisted_offline_payment.sql';
    fwrite(STDERR, "[disposable DB {$fresh}] applying migration 1.41.0_admin_assisted_offline_payment.sql\n");
    $mig41 = disposable_apply_migration_file($pdo, $fresh, $migration41);
    if (empty($mig41['ok'])) {
        fwrite(STDERR, 'Migration failed: ' . ($mig41['message'] ?? 'unknown') . "\n");
        exit(2);
    }
}

$rememberTest = str_starts_with(basename($targetPath), 'test-customer-remember');
if ($migrateRememberLogin || $rememberTest) {
    $migration = $root . '/database/migrations/1.38.0_customer_remember_tokens.sql';
    fwrite(STDERR, "[disposable DB {$fresh}] applying migration 1.38.0_customer_remember_tokens.sql\n");
    $mig = disposable_apply_migration_file($pdo, $fresh, $migration);
    if (empty($mig['ok'])) {
        fwrite(STDERR, 'Migration failed: ' . ($mig['message'] ?? 'unknown') . "\n");
        exit(2);
    }
}

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
if (!$migrateFirebasePush) {
    $seedPdo->exec(
        "UPDATE settings SET setting_value = " . $seedPdo->quote(Install::SCHEMA_VERSION)
        . " WHERE setting_key = 'schema_version'"
    );
}

fwrite(STDERR, "[disposable DB {$fresh}] running " . basename($targetPath) . "\n");
$argv = [$targetPath];
require $targetPath;
