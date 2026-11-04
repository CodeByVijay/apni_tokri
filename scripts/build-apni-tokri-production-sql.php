<?php

declare(strict_types=1);

/**
 * Build database/production/apni_tokri_production.sql from database.sql + ordered migrations.
 * Does NOT touch the configured application database unless BUILD_TARGET_DB env overrides.
 *
 * CLI: php scripts/build-apni-tokri-production-sql.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$root = dirname(__DIR__);
$outFile = $root . '/database/production/apni_tokri_production.sql';

/** @return list<string> Canonical forward migration order (Install::SCHEMA_VERSION). */
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
    ];
}

/** Tables whose rows are required system / reference seed (no transactional demo data). */
function production_seed_tables(): array
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
    ];
}

function shell_quote(string $s): string
{
    return escapeshellarg($s);
}

if (realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return;
}

$db = App::config()['database'];
$host = (string) ($db['host'] ?? '127.0.0.1');
$port = (int) ($db['port'] ?? 3306);
$user = (string) ($db['user'] ?? 'root');
$pass = (string) ($db['pass'] ?? $db['password'] ?? '');
$buildDb = getenv('BUILD_TARGET_DB') ?: ('apni_tokri_prod_build_' . date('YmdHis'));

$mysqlBase = 'mysql -h ' . shell_quote($host) . ' -P ' . (int) $port . ' -u ' . shell_quote($user);
if ($pass !== '') {
    $mysqlBase .= ' -p' . shell_quote($pass);
}
$mysqldumpBase = 'mysqldump -h ' . shell_quote($host) . ' -P ' . (int) $port . ' -u ' . shell_quote($user);
if ($pass !== '') {
    $mysqldumpBase .= ' -p' . shell_quote($pass);
}

echo "Building consolidated SQL using temporary database: {$buildDb}\n";

$createDb = $mysqlBase . ' -e ' . shell_quote("CREATE DATABASE IF NOT EXISTS `{$buildDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$code = 0;
passthru($createDb, $code);
if ($code !== 0) {
    fwrite(STDERR, "Failed to create build database.\n");
    exit(1);
}

$useDb = $mysqlBase . ' ' . shell_quote($buildDb);
foreach (production_migration_sequence() as $rel) {
    $path = $root . '/' . $rel;
    if (!is_readable($path)) {
        fwrite(STDERR, "Missing migration file: {$rel}\n");
        exit(1);
    }
    echo "  apply {$rel}\n";
    $cmd = $useDb . ' < ' . shell_quote($path);
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Migration failed: {$rel} (exit {$code})\n");
        exit(1);
    }
}

@mkdir(dirname($outFile), 0755, true);

$header = <<<'HDR'
-- Apni Tokri — consolidated production schema installer
-- Generated by scripts/build-apni-tokri-production-sql.php (do not edit by hand)
-- Schema version: 1.22.0-startmessaging-sms (Install::SCHEMA_VERSION)
--
-- Import into an EMPTY database only. No DROP TABLE statements included.
-- Contains: full schema + required system/reference seed rows only.
-- Does NOT contain: customers, orders, payments, subscriptions, invoices, reviews, support tickets, or QA data.
--
-- After import: change default admin password; configure config.local.php; run php scripts/verify-install.php

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

HDR;

file_put_contents($outFile, $header);

$schemaDump = $mysqldumpBase
    . ' --no-data --skip-add-drop-table --routines --triggers --single-transaction --set-gtid-purged=OFF '
    . shell_quote($buildDb);
echo "  mysqldump schema\n";
ob_start();
passthru($schemaDump, $code);
$schemaSql = ob_get_clean();
if ($code !== 0) {
    fwrite(STDERR, "Schema mysqldump failed.\n");
    exit(1);
}
file_put_contents($outFile, $schemaSql, FILE_APPEND);

file_put_contents($outFile, "\n-- ---------------------------------------------------------------------------\n-- Reference / system seed data\n-- ---------------------------------------------------------------------------\n\n", FILE_APPEND);

$tables = production_seed_tables();
$dataDump = $mysqldumpBase
    . ' --no-create-info --skip-add-drop-table --complete-insert --single-transaction --set-gtid-purged=OFF '
    . shell_quote($buildDb) . ' ' . implode(' ', array_map(static fn (string $t): string => escapeshellarg($t), $tables));
echo "  mysqldump seed tables\n";
ob_start();
passthru($dataDump, $code);
$dataSql = ob_get_clean();
if ($code !== 0) {
    fwrite(STDERR, "Data mysqldump failed.\n");
    exit(1);
}
file_put_contents($outFile, $dataSql, FILE_APPEND);

file_put_contents($outFile, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND);

$dropCmd = $mysqlBase . ' -e ' . shell_quote("DROP DATABASE IF EXISTS `{$buildDb}`");
passthru($dropCmd, $code);

$bytes = filesize($outFile);
echo "Wrote {$outFile} (" . number_format((float) $bytes) . " bytes)\n";
exit(0);
