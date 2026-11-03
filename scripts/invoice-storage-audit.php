<?php

declare(strict_types=1);

/**
 * Audit invoice PDF files on disk vs invoices.pdf_path. Default is dry-run only.
 *
 * Usage:
 *   php scripts/invoice-storage-audit.php
 *   php scripts/invoice-storage-audit.php --delete   # remove verified orphans only
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$delete = in_array('--delete', $argv, true);
$dir = InvoiceStorage::baseDir();
$pattern = $dir . '/*.pdf';
$onDisk = glob($pattern) ?: [];

$pdo = Database::pdo();
$referenced = [];
foreach ($pdo->query('SELECT id, invoice_number, pdf_path FROM invoices WHERE pdf_path IS NOT NULL AND pdf_path <> \'\'')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $path = InvoiceStorage::resolve((string) $row['pdf_path']);
    if ($path !== null) {
        $referenced[$path] = (int) $row['id'];
    }
}

$orphans = [];
foreach ($onDisk as $path) {
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        continue;
    }
    if (!isset($referenced[$real])) {
        $orphans[] = $real;
    }
}

echo 'Invoice storage: ' . $dir . "\n";
echo 'PDF files on disk: ' . count($onDisk) . "\n";
echo 'Referenced by invoices table: ' . count($referenced) . "\n";
echo 'Orphan candidates: ' . count($orphans) . "\n";

foreach ($orphans as $p) {
    echo '  orphan: ' . $p . ' (' . filesize($p) . " bytes)\n";
}

if (!$delete) {
    if (count($orphans) > 0) {
        echo "\nDry-run only. Re-run with --delete to remove verified orphans (not referenced by any invoice row).\n";
    }
    exit(0);
}

if (count($orphans) === 0) {
    echo "Nothing to delete.\n";
    exit(0);
}

$removed = 0;
foreach ($orphans as $p) {
    $base = basename($p);
    if (!preg_match('/^[a-f0-9]{40}\.pdf$/', $base)) {
        echo "Skip (unsafe name): $p\n";
        continue;
    }
    // Re-verify no invoice row points at this filename before unlink.
    $st = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE pdf_path = ?');
    $st->execute([$base]);
    if ((int) $st->fetchColumn() > 0) {
        echo "Skip (now referenced): $p\n";
        continue;
    }
    if (@unlink($p)) {
        $removed++;
        echo "Deleted: $p\n";
    } else {
        echo "Failed to delete: $p\n";
    }
}

echo "\nRemoved $removed orphan file(s).\n";
exit(0);
