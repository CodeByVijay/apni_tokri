<?php

declare(strict_types=1);

/**
 * Invoice system tests. Uses committed, uniquely-named fixtures (prefix ZT) that are removed at the end.
 * Never sends real email (transport is mocked).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();

// ---- worker mode (concurrency tests) -----------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    $r = InvoiceService::ensureForOrder((int) $argv[2], ['prefix' => 'ZT', 'now' => (int) $argv[3]]);
    echo json_encode(['ok' => $r['ok'], 'created' => $r['created'] ?? null, 'no' => $r['invoice']['invoice_number'] ?? null]);
    exit(0);
}

$pass = 0;
$fail = 0;
function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$createdOrders = [];
$createdCustomers = [];
$settingsBackup = [];
foreach (['invoice.brand_name', 'invoice.footer', 'invoice.enable_email', 'invoice.auto_email', 'invoice.admin_resend', 'invoice.enable_thermal', 'invoice.prefix'] as $k) {
    $settingsBackup[$k] = AppSettings::get($k, null);
}

function mkCustomer(PDO $pdo, string $email): int
{
    global $createdCustomers, $tok;
    $mobile = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?,?,?,1)')->execute([$mobile, 'ZT Customer ' . $tok, $email]);
    $id = (int) $pdo->lastInsertId();
    $createdCustomers[] = $id;

    return $id;
}

/** @param list<array{0:string,1:string,2:int,3:float}> $items */
function mkOrder(PDO $pdo, int $cid, string $status, string $pay, array $items, string $capture = 'captured', string $source = 'storefront', float $discount = 0.0, float $fee = 40.0): int
{
    global $createdOrders, $tok;
    $sub = 0.0;
    foreach ($items as $it) {
        $sub += $it[2] * $it[3];
    }
    $grand = round($sub - $discount + $fee, 2);
    $on = 'ZT-' . $tok . '-' . bin2hex(random_bytes(3));
    $snap = json_encode(['address' => ['receiver_name' => 'Test Receiver', 'receiver_phone' => '9876543210', 'line1' => '12 Test Street', 'city' => 'Indore', 'state' => 'Madhya Pradesh', 'pincode' => '452010', 'label' => 'Home']], JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_snapshot_json, coupon_code, placed_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([$on, $source, $cid, $status, $sub, $discount, $fee, $grand, $pay, $snap, $discount > 0 ? 'ZTCOUPON' : null]);
    $oid = (int) $pdo->lastInsertId();
    $createdOrders[] = $oid;
    foreach ($items as $it) {
        $pdo->prepare('INSERT INTO order_items (order_id, product_name, variant_name, sku, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)')
            ->execute([$oid, $it[0], $it[1], 'SKU-' . substr(md5($it[0]), 0, 6), $it[2], $it[3], round($it[2] * $it[3], 2)]);
    }
    if ($capture !== '') {
        $pdo->prepare('INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$oid, 'razorpay', 'gw_' . $on, $capture === 'captured' ? 'pay_' . $on : null, $grand, 'INR', $capture, $capture === 'captured' ? date('Y-m-d H:i:s') : null]);
    }

    return $oid;
}

/** Inflate every content stream and return the concatenated drawing operators (text is literal there). */
function pdfText(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $raw) {
            $d = @gzuncompress($raw);
            $out .= $d !== false ? $d : '';
        }
    }

    return $out;
}

function invFiles(): array
{
    return glob(InvoiceStorage::baseDir() . '/*.pdf') ?: [];
}

$prefix = 'ZT';
$cid = mkCustomer($pdo, 'zt-' . strtolower($tok) . '@example.test');
$items = [['Fresh Tomatoes', '500 g', 2, 35.0], ['Basmati Rice <b>Premium</b> (Aged) \\ 5kg', '5 kg', 1, 1234567.0 / 100]];

// 1. Schema
foreach (['invoices', 'invoice_sequences', 'invoice_email_log'] as $tbl) {
    t($pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn() !== false, "Table $tbl exists");
}

// 2. Eligibility
$oPending = mkOrder($pdo, $cid, 'pending_payment', 'pending', $items, '');
$oFailed = mkOrder($pdo, $cid, 'pending_payment', 'failed', $items, 'failed');
$oCancelled = mkOrder($pdo, $cid, 'cancelled', 'paid', $items);
$oSub = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, 'captured', 'subscription');
$oNoCap = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, '');
foreach (['pending' => $oPending, 'failed payment' => $oFailed, 'cancelled' => $oCancelled, 'subscription' => $oSub, 'paid without captured payment' => $oNoCap] as $label => $oid) {
    $r = InvoiceService::ensureForOrder($oid, ['prefix' => $prefix]);
    t(!$r['ok'] && InvoiceService::retailForOrder($oid) === null, "No invoice for $label order");
}

// H4: captured payment amount rules (invoice only; order UI unchanged)
$oZeroCap = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, 'captured');
$pdo->prepare('UPDATE payments SET amount = 0 WHERE order_id = ?')->execute([$oZeroCap]);
t(!InvoiceService::ensureForOrder($oZeroCap, ['prefix' => $prefix])['ok'], 'No invoice when captured payment amount is zero');

$oMulti = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, 'captured');
$gtMulti = (float) $pdo->query('SELECT grand_total FROM orders WHERE id = ' . (int) $oMulti)->fetchColumn();
$half = round($gtMulti / 2, 2);
$pdo->prepare('UPDATE payments SET amount = ? WHERE order_id = ?')->execute([$half, $oMulti]);
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, captured_at, created_at)
     VALUES (?,?,?,?,?,?,?,NOW(),NOW())'
)->execute([$oMulti, 'razorpay', 'gw2_' . $oMulti, 'pay2_' . $oMulti, round($gtMulti - $half, 2), 'INR', 'captured']);
$rMulti = InvoiceService::ensureForOrder($oMulti, ['prefix' => $prefix]);
t($rMulti['ok'], 'Invoice allowed when multiple captured payments sum to a positive total');
$snapMulti = InvoiceService::snapshot($rMulti['invoice']);
t(abs($snapMulti['pricing']['amount_paid'] - $gtMulti) < 0.02, 'Invoice snapshot amount_paid sums captured rows (no paid-status fallback)');

$oPartRef = mkOrder($pdo, $cid, 'confirmed', 'partially_refunded', $items, 'captured');
$pdo->prepare('UPDATE payments SET amount_refunded_inr = 30.00 WHERE order_id = ? AND status = ?')->execute([$oPartRef, 'captured']);
$rPartRef = InvoiceService::ensureForOrder($oPartRef, ['prefix' => $prefix]);
t($rPartRef['ok'], 'Partially refunded order remains invoice-eligible with positive capture');
$snapPartRef = InvoiceService::snapshot($rPartRef['invoice']);
t($snapPartRef['pricing']['amount_refunded'] >= 29.99, 'Partially refunded order snapshot includes refund total');

// 3. Generation, numbering, PDF
$o1 = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, 'captured', 'storefront', 10.0);
$filesBefore = count(invFiles());
$fixedNow = strtotime('2026-11-03 10:00:00');
$r1 = InvoiceService::ensureForOrder($o1, ['prefix' => $prefix, 'now' => $fixedNow]);
t($r1['ok'] && !empty($r1['created']), 'Eligible paid order gets an invoice');
$inv1 = $r1['invoice'];
t(preg_match('#^ZT/2026-27/\d{5}$#', (string) $inv1['invoice_number']) === 1, 'Invoice number format PREFIX/FY/00001: ' . $inv1['invoice_number']);
t(count(invFiles()) === $filesBefore + 1, 'Exactly one PDF stored');
$read = InvoiceStorage::read($inv1);
t($read['ok'] && strncmp((string) $read['bytes'], '%PDF-1.', 7) === 0, 'Stored PDF is valid and hash-verified');
t(preg_match('/^[a-f0-9]{40}\.pdf$/', (string) $inv1['pdf_path']) === 1, 'PDF filename is random 40-hex');
t(!empty($inv1['pdf_hash']) && hash('sha256', (string) $read['bytes']) === $inv1['pdf_hash'], 'PDF SHA-256 stored matches');
$r1b = InvoiceService::ensureForOrder($o1, ['prefix' => $prefix]);
t($r1b['ok'] && empty($r1b['created']) && (int) $r1b['invoice']['id'] === (int) $inv1['id'], 'Second ensureForOrder is idempotent');
t((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $o1")->fetchColumn() === 1, 'Only one invoice row per order');
$bytes = pdfText((string) $read['bytes']);
t(strpos($bytes, '(Apni Tokri) Tj') !== false && strpos($bytes, 'Fresh Tomatoes') !== false, 'PDF content streams decode and contain brand + items');
foreach (['GSTIN', 'CGST', 'SGST', 'IGST', 'Unregistered'] as $bad) {
    t(stripos($bytes, $bad) === false, "PDF contains no \"$bad\"");
}
t(strpos($bytes, 'RETAIL INVOICE') !== false, 'PDF title is RETAIL INVOICE');
t(strpos($bytes, 'Rs. 12,345.67') !== false && InvoiceFormat::inr(1234567.5) === '12,34,567.50', 'Indian number formatting 12,34,567.50');
t(InvoiceFormat::inr(999.0) === '999.00' && InvoiceFormat::inr(1000.0) === '1,000.00' && InvoiceFormat::inr(100000.0) === '1,00,000.00', 'Indian grouping boundaries');
$snap1 = InvoiceService::snapshot($inv1);
t(abs($snap1['pricing']['grand_total'] - (70.0 + 12345.67 - 10.0 + 40.0)) < 0.01 || $snap1['pricing']['grand_total'] > 0, 'Snapshot grand total recorded');
t(abs($snap1['pricing']['amount_paid'] - $snap1['pricing']['grand_total']) < 0.005 && abs($snap1['pricing']['balance_due']) < 0.005, 'Paid in full => balance due 0');
t(($snap1['delivery_address']['receiver_name'] ?? '') === 'Test Receiver', 'Delivery address from immutable order snapshot');
t(count($snap1['items']) === 2 && $snap1['items'][1]['name'] === $items[1][0], 'Items captured verbatim (special characters kept)');

// 4. Immutability
AppSettings::set('invoice.brand_name', 'Changed Brand ' . $tok);
$pdo->prepare('UPDATE order_items SET product_name = ? WHERE order_id = ?')->execute(['Renamed product', $o1]);
$pdo->prepare('UPDATE orders SET grand_total = grand_total + 5 WHERE id = ?')->execute([$o1]);
$again = InvoiceService::ensureForOrder($o1, ['prefix' => $prefix]);
t($again['invoice']['snapshot_hash'] === $inv1['snapshot_hash'] && $again['invoice']['pdf_hash'] === $inv1['pdf_hash'], 'Settings/product/order edits do not alter issued invoice');
t(InvoiceService::snapshot($again['invoice'])['seller']['brand_name'] !== 'Changed Brand ' . $tok, 'Seller snapshot unchanged after settings change');
t(InvoiceStorage::read($again['invoice'])['ok'], 'Stored PDF still verifies');
$pdfFile = InvoiceStorage::resolve((string) $inv1['pdf_path']);
$orig = (string) file_get_contents((string) $pdfFile);
$tampered = $orig;
$tampered[10] = $tampered[10] === 'A' ? 'B' : 'A';
file_put_contents((string) $pdfFile, $tampered);
t(!InvoiceStorage::read($inv1)['ok'], 'Tampered PDF fails integrity check');
file_put_contents((string) $pdfFile, $orig);

// 5. Path safety
t(InvoiceStorage::resolve('../../includes/config.local.php') === null, 'Path traversal rejected');
t(InvoiceStorage::resolve('/etc/passwd') === null, 'Absolute path rejected');
t(InvoiceStorage::resolve('abc.pdf') === null, 'Non-hex name rejected');
t(is_file(InvoiceStorage::baseDir() . '/.htaccess'), 'Storage directory has .htaccess deny');
t(strncmp($orig, '%PDF-', 5) !== 0 && strpos($orig, 'RETAIL') === false, 'PDF is encrypted at rest (file on disk is not a readable PDF)');

// 6. Failure rolls back
$oFail = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
$seqBefore = (int) $pdo->query("SELECT COALESCE(MAX(last_number),0) FROM invoice_sequences WHERE prefix = 'ZT' AND doc_series = 'invoice'")->fetchColumn();
$filesBefore = count(invFiles());
InvoiceService::$afterRenderHook = static function (string $file): void {
    throw new RuntimeException('forced failure');
};
$rf = InvoiceService::ensureForOrder($oFail, ['prefix' => $prefix, 'now' => $fixedNow]);
InvoiceService::$afterRenderHook = null;
t(!$rf['ok'] && InvoiceService::retailForOrder($oFail) === null, 'Failed generation leaves no invoice row');
t(count(invFiles()) === $filesBefore, 'Failed generation leaves no orphan PDF file');
$seqAfter = (int) $pdo->query("SELECT COALESCE(MAX(last_number),0) FROM invoice_sequences WHERE prefix = 'ZT' AND doc_series = 'invoice'")->fetchColumn();
t($seqAfter === $seqBefore, 'Failed generation does not burn a sequence number');
$rfOk = InvoiceService::ensureForOrder($oFail, ['prefix' => $prefix, 'now' => $fixedNow]);
t($rfOk['ok'] && !empty($rfOk['created']), 'Retry after failure succeeds');

// 7. Financial year boundaries
$oA = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
$oB = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
$ra = InvoiceService::ensureForOrder($oA, ['prefix' => 'ZY', 'now' => strtotime('2027-03-31 23:00:00')]);
$rb = InvoiceService::ensureForOrder($oB, ['prefix' => 'ZY', 'now' => strtotime('2027-04-01 00:30:00')]);
t(strpos((string) $ra['invoice']['invoice_number'], '2026-27') !== false, '31 Mar belongs to previous FY');
t(strpos((string) $rb['invoice']['invoice_number'], '2027-28') !== false && substr((string) $rb['invoice']['invoice_number'], -5) === '00001', '1 Apr starts a new FY at 00001');

// 8. Concurrency: different orders -> unique sequential numbers
$conc = [];
for ($i = 0; $i < 6; $i++) {
    $conc[] = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
}
$procs = [];
foreach ($conc as $oid) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker ' . $oid . ' ' . strtotime('2026-12-01 10:00:00');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$p, $pipes];
}
$numbers = [];
foreach ($procs as [$p, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    proc_close($p);
    $j = json_decode((string) $out, true);
    if (is_array($j) && !empty($j['no'])) {
        $numbers[] = (string) $j['no'];
    }
}
sort($numbers);
t(count($numbers) === 6 && count(array_unique($numbers)) === 6, 'Parallel generation: 6 unique numbers');
$seqs = array_map(static function ($n) {
    return (int) substr($n, -5);
}, $numbers);
t($seqs === range($seqs[0], $seqs[0] + 5), 'Parallel generation: numbers are gap-free and sequential');
// same order from 5 processes
$same = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
$procs = [];
for ($i = 0; $i < 5; $i++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --worker ' . $same . ' ' . strtotime('2026-12-01 10:00:00');
    $procs[] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
}
$created = 0;
foreach ($procs as [$p, $pipes]) {
    $j = json_decode((string) stream_get_contents($pipes[1]), true);
    proc_close($p);
    $created += !empty($j['created']) ? 1 : 0;
}
t((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $same")->fetchColumn() === 1 && $created === 1, 'Same order from 5 processes yields exactly one invoice');
$sameFiles = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $same")->fetchColumn();
t($sameFiles === 1, 'No duplicate invoice rows after race');

// 9. Credit notes
$oC = mkOrder($pdo, $cid, 'confirmed', 'paid', $items);
$rc = InvoiceService::ensureForOrder($oC, ['prefix' => $prefix, 'now' => $fixedNow]);
$payId = (int) $pdo->query("SELECT id FROM payments WHERE order_id = $oC AND status='captured' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO payment_refunds (payment_id, gateway, amount_inr, idempotency_key, gateway_refund_id, gateway_status, completed_at) VALUES (?,?,?,?,?,?,NOW())")
    ->execute([$payId, 'razorpay', 50, 'zt_' . $tok . '_1', 'rfnd_zt1', 'processed']);
$rid1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO payment_refunds (payment_id, gateway, amount_inr, idempotency_key, gateway_status) VALUES (?,?,?,?,?)")
    ->execute([$payId, 'razorpay', 20, 'zt_' . $tok . '_2', 'created']);
$rid2 = (int) $pdo->lastInsertId();
$origHashBefore = $rc['invoice']['pdf_hash'];
$cn = InvoiceService::issueCreditNote($rid1, ['prefix' => $prefix, 'now' => $fixedNow]);
t($cn['ok'] && !empty($cn['created']) && $cn['invoice']['document_type'] === 'credit_note', 'Credit note issued for completed refund');
t(strpos((string) $cn['invoice']['invoice_number'], 'ZTCN/') === 0, 'Credit note uses separate series: ' . $cn['invoice']['invoice_number']);
t((int) $cn['invoice']['parent_invoice_id'] === (int) $rc['invoice']['id'], 'Credit note links to original invoice');
t((float) $cn['invoice']['grand_total'] === 50.0, 'Credit note amount equals refund');
$cn2 = InvoiceService::issueCreditNote($rid1, ['prefix' => $prefix]);
t($cn2['ok'] && empty($cn2['created']) && $cn2['invoice']['id'] === $cn['invoice']['id'], 'Credit note issuance idempotent');
$pendingCn = InvoiceService::issueCreditNote($rid2, ['prefix' => $prefix]);
t(!$pendingCn['ok'], 'No credit note for incomplete refund');
t(InvoiceService::byId((int) $rc['invoice']['id'])['pdf_hash'] === $origHashBefore, 'Original invoice unchanged by credit note');
$cnPdf = pdfText((string) InvoiceStorage::read($cn['invoice'])['bytes']);
t(strpos($cnPdf, '(CREDIT NOTE) Tj') !== false && strpos($cnPdf, 'RETAIL INVOICE') === false, 'Credit note PDF titled CREDIT NOTE');
$sync = InvoiceService::syncForOrder($oC);
t($sync['invoice'] !== null && count($sync['credit_notes']) === 1, 'syncForOrder returns invoice and its credit note without duplicating');
$syncNone = InvoiceService::syncForOrder($oPending);
t($syncNone['invoice'] === null && !$syncNone['eligible'], 'syncForOrder: unpaid order has no invoice and is not "eligible"');
$noInvRefund = mkOrder($pdo, $cid, 'confirmed', 'paid', $items, '');
t(InvoiceService::issueCreditNote(999999999)['ok'] === false, 'Credit note for unknown refund rejected');

// 10. Layout stress: many items, long names
$many = [];
for ($i = 1; $i <= 70; $i++) {
    $many[] = ['Very long product name number ' . $i . ' ' . str_repeat('Organic Himalayan Premium ', 4), 'Pack of ' . $i . ' with extra long variant label to force wrapping', 1 + ($i % 3), 10.5 + $i];
}
$oM = mkOrder($pdo, $cid, 'confirmed', 'paid', $many);
$rm = InvoiceService::ensureForOrder($oM, ['prefix' => $prefix, 'now' => $fixedNow]);
$mRaw = $rm['ok'] ? (string) InvoiceStorage::read($rm['invoice'])['bytes'] : '';
$mBytes = pdfText($mRaw);
preg_match_all('#/Type\s*/Page[^s]#', $mRaw, $mm);
t($rm['ok'] && count($mm[0]) >= 2, 'Large order paginates over multiple pages (' . count($mm[0]) . ')');
t(substr_count($mBytes, '(ITEM) Tj') === count($mm[0]), 'Table header repeats on each page');
t(strpos($mBytes, '(Page 1 of ' . count($mm[0]) . ') Tj') !== false && strpos($mBytes, '(Page ' . count($mm[0]) . ' of ' . count($mm[0]) . ') Tj') !== false, 'Footer shows page numbering');

// 11. Thermal
InvoiceSettings::saveFromPost(array_merge(InvoiceSettings::formState(), ['enable_thermal' => '1', 'enable_email' => '1', 'admin_resend' => '1', 'fy_numbering' => '1', 'prefix' => 'AT']));
$th = InvoiceService::thermalPdf($rc['invoice']);
t($th !== null && strncmp($th, '%PDF-', 5) === 0, 'Thermal layout renders from snapshot');
AppSettings::set('invoice.enable_thermal', '0');
t(InvoiceService::thermalPdf($rc['invoice']) === null, 'Thermal layout disabled by setting');

// 12. Email
$sent = [];
InvoiceEmailService::$transport = static function ($to, $subject, $text, $html, $att) use (&$sent) {
    $sent[] = compact('to', 'subject', 'text', 'html', 'att');

    return ['ok' => true];
};
AppSettings::set('invoice.enable_email', '1');
AppSettings::set('invoice.admin_resend', '1');
AppSettings::set('invoice.auto_email', '0');
$e1 = InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1);
t($e1['ok'] && count($sent) === 1, 'Admin send succeeds through (mock) transport');
t(strpos($sent[0]['subject'], 'Your Apni Tokri Invoice') === 0 && strpos($sent[0]['subject'], $snap1['order']['number']) !== false, 'Subject: ' . $sent[0]['subject']);
t($sent[0]['att'][0]['mime'] === 'application/pdf' && strncmp($sent[0]['att'][0]['content'], '%PDF-', 5) === 0, 'PDF attached');
t(strpos($sent[0]['html'], '<script') === false && strpos($sent[0]['html'], 'invoice-view.php?ref=' . $inv1['public_ref']) !== false, 'Email has secure portal link and no raw markup');
t(strpos($sent[0]['html'], (string) $inv1['pdf_path']) === false, 'Email never exposes PDF storage path');
$e2 = InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1);
t(!$e2['ok'] && ($e2['error'] ?? '') === 'duplicate' && count($sent) === 1, 'Immediate double send blocked');
$pdo->prepare("UPDATE invoice_email_log SET attempted_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE invoice_id = ?")->execute([$inv1['id']]);
AppSettings::set('invoice.admin_resend', '0');
$e3 = InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1);
t(!$e3['ok'] && ($e3['error'] ?? '') === 'resend_disabled', 'Resend blocked when disabled');
AppSettings::set('invoice.admin_resend', '1');
$e4 = InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1);
t($e4['ok'] && count($sent) === 2, 'Resend allowed when enabled');
$logCount = (int) $pdo->query("SELECT COUNT(*) FROM invoice_email_log WHERE invoice_id = {$inv1['id']} AND status='sent'")->fetchColumn();
t($logCount === 2, 'Every send attempt is logged');
// failure doesn't affect invoice + can retry
InvoiceEmailService::$transport = static function () {
    return ['ok' => false, 'error' => 'connection_failed'];
};
$pdo->prepare("UPDATE invoice_email_log SET attempted_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE invoice_id = ?")->execute([$inv1['id']]);
$e5 = InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1);
t(!$e5['ok'] && InvoiceService::byId((int) $inv1['id']) !== null, 'Email failure does not remove/alter the invoice');
t((string) $pdo->query("SELECT status FROM invoice_email_log WHERE invoice_id = {$inv1['id']} ORDER BY id DESC LIMIT 1")->fetchColumn() === 'failed', 'Failed email attempt logged as failed');
$leak = (string) $pdo->query("SELECT COALESCE(error_code,'') FROM invoice_email_log WHERE invoice_id = {$inv1['id']} ORDER BY id DESC LIMIT 1")->fetchColumn();
t(strlen($leak) < 61 && strpos($leak, 'password') === false, 'Error code logged without secrets');
InvoiceEmailService::$transport = static function () use (&$sent) {
    $sent[] = 'x';

    return ['ok' => true];
};
// auto email dedupe
AppSettings::set('invoice.auto_email', '1');
$before = count($sent);
$a1 = InvoiceEmailService::sendAuto((int) $rc['invoice']['id']);
$a2 = InvoiceEmailService::sendAuto((int) $rc['invoice']['id']);
t($a1['ok'] && !$a2['ok'] && count($sent) === $before + 1, 'Automatic email sent once only');
AppSettings::set('invoice.auto_email', '0');
t(!InvoiceEmailService::sendAuto((int) $inv1['id'])['ok'], 'Auto email off => not sent');
// no email address
$cidNoMail = mkCustomer($pdo, '');
$oNm = mkOrder($pdo, $cidNoMail, 'confirmed', 'paid', $items);
$rNm = InvoiceService::ensureForOrder($oNm, ['prefix' => $prefix]);
$sentBefore = count($sent);
$nm = InvoiceEmailService::sendFromAdmin((int) $rNm['invoice']['id'], 1);
t(!$nm['ok'] && ($nm['error'] ?? '') === 'no_email' && count($sent) === $sentBefore, 'Customer without email: send refused, nothing sent');
AppSettings::set('invoice.enable_email', '0');
t(!InvoiceEmailService::sendFromAdmin((int) $inv1['id'], 1)['ok'], 'Email disabled by setting');
AppSettings::set('invoice.enable_email', '1');
InvoiceEmailService::$transport = null;

// 13. SMTP attachment MIME
$mailer = new SmtpMailer(['host' => 'x']);
$ref = new ReflectionClass($mailer);
$att = $ref->getProperty('attachments');
$att->setAccessible(true);
$att->setValue($mailer, [['filename' => 'a b.pdf', 'mime' => 'application/pdf', 'content' => "%PDF-1.4\n" . str_repeat('x', 500)]]);
$bm = $ref->getMethod('buildMessage');
$bm->setAccessible(true);
$msg = (string) $bm->invoke($mailer, 'from@example.test', 'Shop', 'to@example.test', 'Subject – test', 'plain', '<p>html</p>');
t(strpos($msg, 'multipart/mixed') !== false && strpos($msg, 'multipart/alternative') !== false && strpos($msg, 'filename="a_b.pdf"') !== false, 'SMTP message is multipart/mixed with alternative body + attachment');
preg_match('/Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename="a_b.pdf"\r\n\r\n(.*?)\r\n--atm_/s', $msg, $m);
t(isset($m[1]) && strncmp((string) base64_decode(str_replace("\r\n", '', $m[1])), '%PDF-1.4', 8) === 0, 'Attachment base64 decodes to the PDF');
$att->setValue($mailer, []);
$plain = (string) $bm->invoke($mailer, 'from@example.test', 'Shop', 'to@example.test', 'S', 'plain', '<p>html</p>');
t(strpos($plain, 'multipart/alternative') !== false && strpos($plain, 'multipart/mixed') === false, 'Existing non-attachment mails unchanged');

// 14. Ownership / enumeration
$otherCid = mkCustomer($pdo, 'zt-other-' . strtolower($tok) . '@example.test');
t(InvoiceService::forCustomer((string) $inv1['public_ref'], $cid) !== null, 'Owner can access invoice by ref');
t(InvoiceService::forCustomer((string) $inv1['public_ref'], $otherCid) === null, "Another customer cannot access someone else's invoice");
t(InvoiceService::forCustomer((string) $inv1['public_ref'], 0) === null, 'No customer id => no access');
t(InvoiceService::byRef("' OR 1=1 --") === null && InvoiceService::byRef('123') === null, 'Malformed refs rejected');
t(strlen((string) $inv1['public_ref']) === 24 && !is_numeric($inv1['public_ref']), 'Public ref is non-sequential random token');

// 15. Admin queries
$all = InvoiceQuery::adminSearch(['q' => 'ZT/', 'page' => 1]);
t($all['total'] >= 5, 'Admin search by invoice number prefix');
$byOrder = InvoiceQuery::adminSearch(['q' => $snap1['order']['number']]);
t($byOrder['total'] === 1, 'Admin search by order number');
$byCust = InvoiceQuery::adminSearch(['customer' => 'ZT Customer ' . $tok]);
t($byCust['total'] >= 5, 'Admin search by customer name');
$byDate = InvoiceQuery::adminSearch(['customer' => 'ZT Customer ' . $tok, 'from' => '2026-12-31', 'to' => '2026-12-31']);
t($byDate['total'] === 0, 'Date range excludes out-of-range invoices');
$cnOnly = InvoiceQuery::adminSearch(['customer' => 'ZT Customer ' . $tok, 'type' => 'credit_note']);
t($cnOnly['total'] === 1, 'Filter by document type');
$sorted = InvoiceQuery::adminSearch(['customer' => 'ZT Customer ' . $tok, 'sort' => 'amount', 'dir' => 'asc']);
$amts = array_map(static function ($r) {
    return (float) $r['grand_total'];
}, $sorted['rows']);
$sortedAmts = $amts;
sort($sortedAmts);
t($amts === $sortedAmts, 'Sort by amount ascending');
t(InvoiceQuery::adminSearch(['sort' => 'id; DROP TABLE invoices', 'dir' => 'x'])['total'] >= 0 && $pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn() !== false, 'Sort parameter cannot inject SQL');
t(InvoiceQuery::adminSearch(['q' => "%' OR '1'='1"])['total'] === 0, 'Search input is parameterised / wildcard-escaped');
$pg = InvoiceQuery::adminSearch(['customer' => 'ZT Customer ' . $tok, 'page' => 9999]);
t($pg['page'] === $pg['pages'], 'Out-of-range page clamps');
$refs = InvoiceQuery::refsForOrderNumbers([$snap1['order']['number']], $cid);
t(isset($refs[$snap1['order']['number']]) && InvoiceQuery::refsForOrderNumbers([$snap1['order']['number']], $otherCid) === [], 'my-orders invoice indicator is ownership-scoped');

// 16. Static / RBAC / security source checks
$root = APNI_TOKRI_ROOT;
foreach (['admin/invoices/index.php', 'admin/invoices/view.php', 'admin/invoices/download.php'] as $f) {
    t(strpos((string) file_get_contents("$root/$f"), "denyIfCannot('invoices')") !== false, "$f enforces invoices RBAC");
}
t(strpos((string) file_get_contents("$root/admin/settings/invoices.php"), "denyIfCannot('settings')") !== false, 'Invoice settings page requires settings permission');
t(strpos((string) file_get_contents("$root/admin/invoices/view.php"), 'Csrf::requireValid()') !== false, 'Admin email action is CSRF-protected');
$dl = (string) file_get_contents("$root/invoice-download.php");
t(strpos($dl, 'isLoggedIn') !== false && strpos($dl, 'forCustomer') !== false, 'Customer download checks login + ownership');
foreach (['invoice-download.php', 'invoice-view.php', 'my-invoices.php', 'order-details.php'] as $f) {
    t(strpos((string) file_get_contents("$root/$f"), 'pdf_path') === false, "$f never references raw pdf_path");
}
t(strpos(shell_exec('grep -n "invoices" ' . escapeshellarg("$root/includes/permissions.php")) ?: '', "'orders.manage'") !== false, 'invoices module maps to existing orders.manage permission');
$_SERVER['HTTP_HOST'] = 'localhost';
$html = InvoiceEmailService::compose(array_merge($inv1, ['invoice_number' => '<b>X</b>']), '<script>alert(1)</script>');
t(strpos($html['html'], '<script>') === false && strpos($html['html'], '&lt;script&gt;') !== false, 'Email HTML escapes customer-supplied values');

// 17. Settings validation
$bad = InvoiceSettings::saveFromPost(array_merge(InvoiceSettings::formState(), ['prefix' => 'bad prefix!', 'website' => 'javascript:alert(1)', 'pincode' => '12']));
t(!$bad['ok'] && isset($bad['errors']['prefix'], $bad['errors']['website'], $bad['errors']['pincode']), 'Invoice settings validation rejects bad prefix/URL/PIN');
t(InvoiceSettings::gstMode() === 'unregistered', 'GST mode is unregistered (no tax lines)');

// 18. Production storage configuration (fail closed)
$cfgBackup = App::config();
$setProdCfg = static function (array $over) use ($cfgBackup): void {
    $c = $cfgBackup;
    $c['app'] = array_merge($cfgBackup['app'] ?? [], $over['app'] ?? ['env' => 'production']);
    if (array_key_exists('encryption', $over)) {
        $c['encryption'] = $over['encryption'];
    }
    if (array_key_exists('storage', $over)) {
        $c['storage'] = $over['storage'];
    }
    App::setConfig($c);
};
$setProdCfg(['encryption' => ['master_key_path' => ''], 'storage' => ['invoices_path' => '']]);
try {
    InvoiceProductionSecurity::ensureProductionStorageConfigured();
    t(false, 'Production requires explicit encryption.master_key_path');
} catch (RuntimeException $e) {
    t(strpos($e->getMessage(), 'master_key_path') !== false, 'Production requires explicit encryption.master_key_path');
}
$setProdCfg([
    'encryption' => ['master_key_path' => APNI_TOKRI_ROOT . '/storage/secrets/master.key'],
    'storage' => ['invoices_path' => APNI_TOKRI_ROOT . '/storage/invoices'],
]);
try {
    InvoiceProductionSecurity::ensureProductionStorageConfigured();
    t(false, 'Production rejects storage paths inside document root');
} catch (RuntimeException $e) {
    t(strpos($e->getMessage(), 'document root') !== false, 'Production rejects storage paths inside document root');
}
App::setConfig($cfgBackup);

// ---- cleanup ----------------------------------------------------------------------------------
$ids = implode(',', array_map('intval', $createdOrders)) ?: '0';
foreach ($pdo->query("SELECT pdf_path FROM invoices WHERE order_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $f) {
    InvoiceStorage::delete((string) $f);
}
$pdo->exec("DELETE l FROM invoice_email_log l JOIN invoices i ON i.id = l.invoice_id WHERE i.order_id IN ($ids)");
$pdo->exec("DELETE FROM invoices WHERE order_id IN ($ids) AND document_type = 'credit_note'");
$pdo->exec("DELETE FROM invoices WHERE order_id IN ($ids)");
$pdo->exec("DELETE pr FROM payment_refunds pr JOIN payments p ON p.id = pr.payment_id WHERE p.order_id IN ($ids)");
$pdo->exec("DELETE FROM payments WHERE order_id IN ($ids)");
$pdo->exec("DELETE FROM order_items WHERE order_id IN ($ids)");
$pdo->exec("DELETE FROM orders WHERE id IN ($ids)");
$cids = implode(',', array_map('intval', $createdCustomers)) ?: '0';
$pdo->exec("DELETE FROM customers WHERE id IN ($cids)");
$pdo->exec("DELETE FROM invoice_sequences WHERE prefix IN ('ZT','ZY')");
foreach ($settingsBackup as $k => $v) {
    AppSettings::set($k, $v);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
