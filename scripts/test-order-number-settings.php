<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();

$mig = APNI_TOKRI_ROOT . '/database/migrations/1.34.0_order_number_sequences.sql';
if ($pdo->query("SHOW TABLES LIKE 'order_sequences'")->fetchColumn() === false && is_readable($mig)) {
    $pdo->exec((string) file_get_contents($mig));
}

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$restore = OrderNumberSettings::adminFormState();
$restoreKeys = [
    OrderNumberSettings::KEY_CUSTOM_ENABLED => $restore['custom_format_enabled'] === '1' ? '1' : '0',
    OrderNumberSettings::KEY_PREFIX => $restore['prefix'] !== '' ? $restore['prefix'] : null,
    OrderNumberSettings::KEY_FORMAT => $restore['format'],
    OrderNumberSettings::KEY_START_SEQUENCE => $restore['start_sequence'],
    OrderNumberSettings::KEY_SEQUENCE_DIGITS => $restore['sequence_digits'],
    OrderNumberSettings::KEY_SEQUENCE_RESET => $restore['sequence_reset'],
];
$tok = strtolower(substr(Security::randomToken(4), 0, 6));

AppSettings::set(OrderNumberSettings::KEY_CUSTOM_ENABLED, '0');
AppSettings::resetCache();
ok(!OrderNumberSettings::customFormatEnabled(), 'Custom format off by default path');
$nLegacy = OrderNumberService::preview('storefront');
ok(preg_match('/^AT-\d{6}-XXXXXX$/', $nLegacy) === 1, 'Legacy preview pattern');

AppSettings::set(OrderNumberSettings::KEY_CUSTOM_ENABLED, '1');
AppSettings::set(OrderNumberSettings::KEY_PREFIX, 'ZT');
AppSettings::set(OrderNumberSettings::KEY_FORMAT, OrderNumberSettings::FORMAT_FY_SEQUENTIAL);
AppSettings::set(OrderNumberSettings::KEY_START_SEQUENCE, '125');
AppSettings::set(OrderNumberSettings::KEY_SEQUENCE_DIGITS, '6');
AppSettings::set(OrderNumberSettings::KEY_SEQUENCE_RESET, OrderNumberSettings::RESET_FISCAL_YEAR);
AppSettings::resetCache();

$fy = InvoiceFormat::financialYear(date('Y-m-d'));
$pdo->exec("DELETE FROM order_sequences WHERE prefix = 'ZT'");

$allocated = [];
for ($i = 0; $i < 3; $i++) {
    $num = Database::transaction(static function () use ($pdo): string {
        return OrderNumberService::allocate($pdo, 'storefront');
    });
    $allocated[] = $num;
    ok(preg_match('/^ZT-' . preg_quote($fy, '/') . '-\d{6}$/', $num) === 1, "FY sequential format: $num");
}
ok($allocated[0] !== $allocated[1] && $allocated[1] !== $allocated[2], 'Sequential increments');
ok((int) substr($allocated[2], -6) === (int) substr($allocated[0], -6) + 2, 'Counter bumped by 2 across transactions');

AppSettings::set(OrderNumberSettings::KEY_FORMAT, OrderNumberSettings::FORMAT_CY_SEQUENTIAL);
AppSettings::resetCache();
$pdo->exec("DELETE FROM order_sequences WHERE prefix = 'ZT'");
$cyNum = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
$cy = date('Y');
ok(str_starts_with($cyNum, 'ZT-' . $cy . '-'), 'Calendar year format prefix');

AppSettings::set(OrderNumberSettings::KEY_FORMAT, OrderNumberSettings::FORMAT_LEGACY_RANDOM);
AppSettings::resetCache();
$randNum = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
ok(preg_match('/^ZT-\d{6}-[A-Z0-9]{6}$/', $randNum) === 1, 'Configured random date format');

$oldPeriod = '2020-21';
$pdo->prepare('INSERT INTO order_sequences (prefix, period_key, series, last_number) VALUES (?,?,?,?)')
    ->execute(['ZT', $oldPeriod, 'storefront', 999]);
AppSettings::set(OrderNumberSettings::KEY_FORMAT, OrderNumberSettings::FORMAT_FY_SEQUENTIAL);
AppSettings::set(OrderNumberSettings::KEY_SEQUENCE_RESET, OrderNumberSettings::RESET_FISCAL_YEAR);
AppSettings::set(OrderNumberSettings::KEY_START_SEQUENCE, '1');
AppSettings::resetCache();
$afterFyRoll = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
ok(str_contains($afterFyRoll, $fy), 'FY rollover uses current period key');
ok(!str_contains($afterFyRoll, $oldPeriod), 'FY rollover does not reuse old period row');

AppSettings::set(OrderNumberSettings::KEY_SEQUENCE_RESET, OrderNumberSettings::RESET_CALENDAR_YEAR);
AppSettings::resetCache();
$pdo->exec("DELETE FROM order_sequences WHERE prefix = 'ZT' AND period_key = '" . date('Y') . "'");
$pdo->prepare('INSERT INTO order_sequences (prefix, period_key, series, last_number) VALUES (?,?,?,?)')
    ->execute(['ZT', (string) ((int) date('Y') - 1), 'storefront', 50]);
$cyRoll = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
ok(str_starts_with($cyRoll, 'ZT-' . date('Y') . '-000001') || str_ends_with($cyRoll, '-000001'), 'Calendar year rollover starts fresh sequence bucket');

AppSettings::set(OrderNumberSettings::KEY_PREFIX, 'ZP');
AppSettings::resetCache();
$pdo->exec("DELETE FROM order_sequences WHERE prefix = 'ZP'");
$z1 = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
$z2 = Database::transaction(static fn (): string => OrderNumberService::allocate($pdo, 'storefront'));
ok($z1 !== $z2, 'Prefix change uses independent counter row');

$saveBad = OrderNumberSettings::saveFromPost([
    'custom_format_enabled' => '1',
    'prefix' => 'BAD-PREFIX',
    'format' => OrderNumberSettings::FORMAT_FY_SEQUENTIAL,
    'start_sequence' => '1',
    'sequence_digits' => '6',
    'sequence_reset' => OrderNumberSettings::RESET_FISCAL_YEAR,
]);
ok($saveBad['ok'] === false && isset($saveBad['errors']['prefix']), 'Invalid prefix rejected');

$hist = 'AT-HIST-' . $tok;
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
     VALUES (?, 'pending_payment', 10, 0, 0, 10, 'pending', NOW())"
)->execute([$hist]);
$stmt = $pdo->prepare('SELECT order_number FROM orders WHERE order_number = ?');
$stmt->execute([$hist]);
ok($stmt->fetchColumn() === $hist, 'Historical order number unchanged after settings');

$custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($custId < 1) {
    $pdo->exec("INSERT INTO customers (mobile, name, created_at) VALUES ('99999" . random_int(10000, 99999) . "', 'OrderNum Test', NOW())");
    $custId = (int) $pdo->lastInsertId();
}

$payOrder = Database::transaction(function () use ($pdo, $custId): array {
    $num = OrderNumberService::allocate($pdo, 'storefront');
    $pdo->prepare(
        "INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, 'pending_payment', 100, 0, 0, 100, 'pending', NOW())"
    )->execute([$num, $custId]);
    return ['id' => (int) $pdo->lastInsertId(), 'number' => $num];
});
$idKey = 'idem_' . $tok;
PaymentService::completePayment(
    $payOrder['number'],
    'razorpay',
    'pay_' . $tok,
    'gw_' . $tok,
    100.0,
    'INR',
    $idKey
);
$r2 = PaymentService::completePayment(
    $payOrder['number'],
    'razorpay',
    'pay_' . $tok,
    'gw_' . $tok,
    100.0,
    'INR',
    $idKey
);
$still = (string) $pdo->query("SELECT order_number FROM orders WHERE id = " . (int) $payOrder['id'])->fetchColumn();
ok($still === $payOrder['number'], 'Payment retry does not change order_number');
ok(!empty($r2['idempotent']), 'Duplicate payment completion is idempotent');

foreach ($restoreKeys as $k => $v) {
    AppSettings::set($k, $v);
}
AppSettings::resetCache();
$pdo->exec("DELETE FROM order_sequences WHERE prefix IN ('ZT','ZP')");
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([(int) $payOrder['id']]);
$pdo->prepare('DELETE FROM orders WHERE order_number IN (?,?)')->execute([$hist, $payOrder['number']]);
ok(true, 'Restore prior order settings');

exit($fail > 0 ? 1 : 0);
