<?php

declare(strict_types=1);

/**
 * Build database/production/apni_tokri_production.sql from database.sql + ordered forward migrations.
 *
 * CLI: php scripts/build-apni-tokri-production-sql.php
 *
 * Uses a temporary database apni_tokri_prod_build_* only — never touches config.local.php database.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';

/** Target schema label after full migration chain (must match last migration UPDATE). */
const PRODUCTION_INSTALL_SCHEMA_VERSION = '1.39.4-firebase-platform-flags';

/**
 * Canonical forward migration order (see database/README.md + 1.8.0 delivery track).
 *
 * @return list<string> paths relative to project root
 */
function production_migration_sequence(): array
{
    return [
        'database/database.sql',
        'database/migrations/1.1.0_catalogue_attributes.sql',
        'database/migrations/1.2.0_commerce_checkout.sql',
        'database/migrations/1.3.0_delivery_engine.sql',
        'database/migrations/1.4.0_payment_gateways.sql',
        'database/migrations/1.5.0_offers_coupons.sql',
        'database/migrations/1.6.0_subscription_plan_builder.sql',
        'database/migrations/1.6.1_subscription_checkout.sql',
        'database/migrations/1.6.2_subscription_menu_baskets.sql',
        'database/migrations/1.6.3_subscription_lifecycle.sql',
        'database/migrations/1.6.4_subscription_fulfilment.sql',
        'database/migrations/1.6.5_subscription_hardening.sql',
        'database/migrations/1.6.6_production_readiness.sql',
        'database/migrations/1.7.0_stage12_reviews_support_refunds.sql',
        'database/migrations/1.7.1_stage12_phase_b.sql',
        'database/migrations/1.8.0_delivery_zones_slots.sql',
        'database/migrations/1.8.0_d2_slot_management.sql',
        'database/migrations/1.8.0_d2_hardening.sql',
        'database/migrations/1.8.0_d3_manual_delivery.sql',
        'database/migrations/1.8.0_d3_hardening.sql',
        'database/migrations/1.8.0_d4_checkout_snapshot.sql',
        'database/migrations/1.8.0_d4_hardening.sql',
        'database/migrations/1.8.0_d5_subscription_fulfilment.sql',
        'database/migrations/1.7.2_stage12_review_moderation_history.sql',
        'database/migrations/1.8.0_stage12_c3_webhook_lease.sql',
        'database/migrations/1.9.0_stage13_cms_foundation.sql',
        'database/migrations/1.10.0_stage14_rbac_phase1.sql',
        'database/migrations/1.11.0_stage14_smtp_phase2.sql',
        'database/migrations/1.12.0_stage14_sms_phase3.sql',
        'database/migrations/1.13.0_stage14_notifications_phase4.sql',
        'database/migrations/1.14.0_stage14_reports_phase5.sql',
        'database/migrations/1.15.0_stage14_reports_phase6.sql',
        'database/migrations/1.16.0_admin_support_notifications.sql',
        'database/migrations/1.17.0_customer_addresses_archive.sql',
        'database/migrations/1.18.0_sms_operation_mode.sql',
        'database/migrations/1.19.0_address_map_pin.sql',
        'database/migrations/1.20.0_invoices.sql',
        'database/migrations/1.21.0_security_remediation_phase1.sql',
        'database/migrations/1.22.0_startmessaging_sms.sql',
        'database/migrations/1.23.0_order_customer_cancellation.sql',
        'database/migrations/1.24.0_cms_footer_display_order.sql',
        'database/migrations/1.25.0_subscription_menu_plans.sql',
        'database/migrations/1.26.0_product_types.sql',
        'database/migrations/1.27.0_subscription_basket_generation_notes.sql',
        'database/migrations/1.28.0_subscription_credit_lifecycle.sql',
        'database/migrations/1.29.0_category_tile_images.sql',
        'database/migrations/1.30.0_customer_order_email_notifications.sql',
        'database/migrations/1.31.0_coupon_storefront_visible.sql',
        'database/migrations/1.31.0_order_confirmation_email_log.sql',
        'database/migrations/1.32.0_refund_status_reconciliation.sql',
        'database/migrations/1.32.0_variant_max_qty_per_order.sql',
        'database/migrations/1.33.0_unpaid_order_payment_expiry.sql',
        'database/migrations/1.33.0_variant_min_qty_per_order.sql',
        'database/migrations/1.34.0_order_number_sequences.sql',
        'database/migrations/1.35.0_seo_center.sql',
        'database/migrations/1.36.0_customer_registration_consent.sql',
        'database/migrations/1.37.0_media_library.sql',
        'database/migrations/1.38.0_customer_remember_tokens.sql',
        'database/migrations/1.39.0_firebase_push_notifications.sql',
        'database/migrations/1.39.1_push_registration_target_type.sql',
        'database/migrations/1.39.2_push_subscription_templates.sql',
        'database/migrations/1.39.3_push_coupon_template.sql',
        'database/migrations/1.39.4_firebase_project_platform_flags.sql',
    ];
}

/**
 * Tables whose rows are included in the consolidated installer (system/reference seed only).
 *
 * @return list<string>
 */
function production_seed_table_allowlist(): array
{
    return [
        'settings',
        'admin_roles',
        'admin_permissions',
        'admin_role_permissions',
        'admin_users',
        'admin_user_roles',
        'faq_categories',
        'delivery_store',
        'delivery_zones',
        'delivery_slots',
        'subscription_plans',
        'cms_pages',
        'notification_templates',
        'coupons',
        'product_types',
        'push_notification_templates',
    ];
}

function resolve_mysqldump_cli(): string
{
    $env = getenv('MYSQLDUMP_BIN');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }

    return 'mysqldump';
}

/**
 * @param array<string, mixed> $db
 * @param list<string> $tables empty = all tables in database
 */
function mysqldump_to_file(array $db, string $database, string $outPath, string $options, array $tables = []): int
{
    $db = disposable_apply_cli_credential_override($db);
    $bin = resolve_mysqldump_cli();
    $cmd = escapeshellarg($bin)
        . ' -h ' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1'))
        . ' -P ' . (int) ($db['port'] ?? 3306)
        . ' -u ' . escapeshellarg((string) ($db['user'] ?? 'root'));
    $pass = (string) ($db['pass'] ?? $db['password'] ?? '');
    if ($pass !== '') {
        $cmd .= ' -p' . escapeshellarg($pass);
    }
    $cmd .= ' --single-transaction --set-gtid-purged=OFF --skip-add-drop-table'
        . ' --default-character-set=utf8mb4 ' . $options
        . ' ' . escapeshellarg($database);
    foreach ($tables as $table) {
        $cmd .= ' ' . escapeshellarg($table);
    }
    $cmd .= ' > ' . escapeshellarg($outPath);
    passthru($cmd, $code);

    return (int) $code;
}

/**
 * @param array<string, mixed> $db
 */
function build_production_sql(array $db, string $outFile): int
{
    $root = dirname(__DIR__);
    $buildDb = 'apni_tokri_prod_build_' . date('YmdHis');
    fwrite(STDOUT, "Building in temporary database `{$buildDb}`...\n");

    $createSql = 'CREATE DATABASE `' . str_replace('`', '``', $buildDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    if (disposable_mysql_exec($db, '-e ' . escapeshellarg($createSql)) !== 0) {
        fwrite(STDERR, "Could not create build database.\n");

        return 1;
    }

    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $user = (string) ($db['user'] ?? 'root');
    $pass = (string) ($db['pass'] ?? $db['password'] ?? '');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$buildDb};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
    );
    $dbArg = escapeshellarg($buildDb);
    foreach (production_migration_sequence() as $rel) {
        $path = $root . '/' . $rel;
        if (!is_readable($path)) {
            fwrite(STDERR, "Missing migration: {$rel}\n");
            disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

            return 1;
        }
        fwrite(STDOUT, "  apply {$rel}\n");
        $importCmd = $dbArg . ' < ' . escapeshellarg($path);
        if (disposable_mysql_exec($db, $importCmd) !== 0) {
            fwrite(STDERR, "Import failed: {$rel}\n");
            disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

            return 1;
        }
    }

    $ver = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1")->fetchColumn();
    if ($ver !== PRODUCTION_INSTALL_SCHEMA_VERSION) {
        fwrite(STDERR, "schema_version mismatch: expected " . PRODUCTION_INSTALL_SCHEMA_VERSION . ", got {$ver}\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    $firebaseTpl = (int) $pdo->query('SELECT COUNT(*) FROM push_notification_templates')->fetchColumn();
    if ($firebaseTpl < 13) {
        fwrite(STDERR, "Expected at least 13 push_notification_templates, got {$firebaseTpl}\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    $fpRows = (int) $pdo->query('SELECT COUNT(*) FROM firebase_projects')->fetchColumn();
    if ($fpRows !== 0) {
        fwrite(STDERR, "firebase_projects must be empty in seed build (got {$fpRows})\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    $schemaTmp = $outFile . '.schema.tmp';
    $dataTmp = $outFile . '.data.tmp';
    $code = mysqldump_to_file($db, $buildDb, $schemaTmp, '--no-data --routines --triggers');
    if ($code !== 0 || !is_readable($schemaTmp)) {
        fwrite(STDERR, "mysqldump schema failed.\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    $tables = production_seed_table_allowlist();
    $code = mysqldump_to_file($db, $buildDb, $dataTmp, '--no-create-info', $tables);
    if ($code !== 0 || !is_readable($dataTmp)) {
        fwrite(STDERR, "mysqldump seed data failed.\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    $header = <<<'HDR'
-- Veggie Tokri — consolidated production schema installer
-- Generated by scripts/build-apni-tokri-production-sql.php (regenerate; do not hand-edit schema DDL)
-- Final migration version: PRODUCTION_INSTALL_SCHEMA_VERSION
-- Install::SCHEMA_VERSION must match this label after PHP constant update.
--
-- Import into an EMPTY database only. No DROP TABLE statements included.
-- Contains: full schema + required system/reference seed rows only.
-- Does NOT contain: customers, orders, payments, subscriptions, invoices, reviews, support tickets, QA data,
--   Firebase project credentials, device tokens, or push logs.
--
-- After import: change default admin password; configure config.local.php; run php scripts/verify-install.php

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

HDR;
    $header = str_replace('PRODUCTION_INSTALL_SCHEMA_VERSION', PRODUCTION_INSTALL_SCHEMA_VERSION, $header);

    $schemaBody = (string) file_get_contents($schemaTmp);
    $dataBody = "\n-- ---------------------------------------------------------------------------\n"
        . "-- Reference / system seed data\n"
        . "-- ---------------------------------------------------------------------------\n\n"
        . (string) file_get_contents($dataTmp)
        . "\nSET FOREIGN_KEY_CHECKS = 1;\n";

    $out = $header . $schemaBody . $dataBody;
    if (file_put_contents($outFile, $out) === false) {
        fwrite(STDERR, "Could not write {$outFile}\n");
        disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

        return 1;
    }

    @unlink($schemaTmp);
    @unlink($dataTmp);
    disposable_mysql_exec($db, '-e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $buildDb . '`'));

    fwrite(STDOUT, 'Wrote ' . $outFile . ' (' . number_format(filesize($outFile)) . " bytes)\n");

    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(__FILE__)) {
    return;
}

$out = dirname(__DIR__) . '/database/production/apni_tokri_production.sql';
exit(build_production_sql(App::config()['database'], $out));
