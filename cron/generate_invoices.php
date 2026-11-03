#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate missing invoices / credit notes for paid orders. Idempotent; safe to run every few minutes.
 * cPanel: php /home/USER/public_html/cron/generate_invoices.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Database not ready.\n");
    exit(1);
}

$r = InvoiceService::sweep(100);
$cn = Database::pdo()->query(
    "SELECT pr.id FROM payment_refunds pr JOIN payments p ON p.id = pr.payment_id
     WHERE pr.gateway_status IN ('processed','success') AND pr.completed_at IS NOT NULL AND pr.amount_inr > 0
       AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.dedupe_key = CONCAT('cn:', pr.id))
     ORDER BY pr.id ASC LIMIT 100"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];
$cnCreated = 0;
foreach ($cn as $rid) {
    $x = InvoiceService::issueCreditNote((int) $rid);
    if (!empty($x['created'])) {
        $cnCreated++;
    }
}
echo 'invoices created=' . $r['created'] . ' skipped=' . $r['skipped'] . ' failed=' . $r['failed'] . ' credit_notes=' . $cnCreated . "\n";
exit($r['failed'] > 0 ? 2 : 0);
