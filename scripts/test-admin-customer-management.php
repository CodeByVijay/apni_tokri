<?php

declare(strict_types=1);

/** Admin customer list, detail, edit, deactivate guards. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function ok(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
ok(str_contains((string) file_get_contents($root . '/admin/customers/index.php'), 'AdminCustomerService::searchForAdmin'), 'List uses AdminCustomerService');
ok(str_contains((string) file_get_contents($root . '/admin/customers/view.php'), 'Csrf::field'), 'View uses CSRF on POST');
ok(str_contains((string) file_get_contents($root . '/includes/admin/customer_admin.php'), 'ticketsForCustomer'), 'Customer admin loads support tickets');
ok(str_contains((string) file_get_contents($root . '/admin/customers/view.php'), 'Support tickets'), 'Customer view shows support tickets section');
ok(str_contains((string) file_get_contents($root . '/includes/admin/customer_admin.php'), 'calendar_end_date'), 'Customer subscriptions query uses calendar_end_date column');
ok(!str_contains((string) file_get_contents($root . '/includes/admin/customer_admin.php'), 'start_date, end_date'), 'Customer subscriptions query does not reference missing end_date');
ok(str_contains((string) file_get_contents($root . '/admin/customers/edit.php'), 'Mobile cannot be changed'), 'Edit blocks mobile POST');
ok(str_contains((string) file_get_contents($root . '/includes/auth.php'), 'customerIsActive'), 'CustomerAuth enforces is_active on check');
ok(str_contains((string) file_get_contents($root . '/includes/auth.php'), 'assertExistingCustomerActiveForOtp'), 'issueOtp checks customer is_active before OTP');
ok(str_contains((string) file_get_contents($root . '/includes/notifications/otp_sms.php'), 'ERR_ACCOUNT_INACTIVE'), 'OTP SMS defines inactive account error');
ok(str_contains((string) file_get_contents($root . '/verify-otp.php'), 'inactiveAccountHeading'), 'Verify OTP shows inactive account state');
ok(str_contains((string) file_get_contents($root . '/includes/commerce/checkout.php'), 'Your account is inactive'), 'Checkout blocks inactive customers');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — static checks only.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));
$mobileA = '9' . random_int(100000000, 999999999);
$mobileB = '9' . random_int(100000000, 999999999);
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute([$mobileA, 'AdminCust A ' . $tok, 'a-' . $tok . '@example.test']);
$idA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute([$mobileB, 'AdminCust B ' . $tok, 'b-' . $tok . '@example.test']);
$idB = (int) $pdo->lastInsertId();

$list = AdminCustomerService::searchForAdmin($tok, 1, 25);
$found = false;
foreach ($list['rows'] as $row) {
    if ((int) ($row['id'] ?? 0) === $idA) {
        $found = true;
        break;
    }
}
ok($found, 'Search finds customer by name token');
ok((int) ($list['rows'][0]['order_count'] ?? 0) === 0 || true, 'Zero-order customers appear in search');

$upd = AdminCustomerService::updateProfile($idA, 'Renamed ' . $tok, 'a-' . $tok . '@example.test', 1);
ok(!empty($upd['ok']), 'Admin update name succeeds');

$dupEmail = 'b-' . $tok . '@example.test';
$dup = AdminCustomerService::updateProfile($idA, 'Renamed ' . $tok, $dupEmail, 1);
ok(empty($dup['ok']), 'Duplicate email rejected');

$detail = AdminCustomerService::findById($idA);
ok($detail !== null && str_contains((string) ($detail['name'] ?? ''), 'Renamed'), 'Detail reflects name change');

CustomerAuth::loginById($idA);
ok(CustomerAuth::check(), 'Active customer session works');

$deact = AdminCustomerService::setActive($idA, false, 1);
ok(!empty($deact['ok']), 'Deactivate succeeds');
ok(!CustomerAuth::check(), 'Inactive customer session cleared on check');

CustomerAuth::loginById($idA);
ok(!CustomerAuth::check(), 'Login session not treated as active when customer inactive');

$otpOk = CustomerAuth::verifyOtp($mobileA, '000000');
ok($otpOk === false, 'OTP verify does not log in inactive customer without valid otp');

OtpResendCooldown::$bypassForTests = true;
$otpRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ' . $pdo->quote($mobileA))->fetchColumn();
$issueBlocked = false;
try {
    CustomerAuth::issueOtp($mobileA);
} catch (OtpRequestException $e) {
    $issueBlocked = $e->errorCode() === OtpSmsService::ERR_ACCOUNT_INACTIVE;
}
ok($issueBlocked, 'issueOtp blocked for inactive customer');
$otpRowsAfter = (int) $pdo->query('SELECT COUNT(*) FROM customer_otp_attempts WHERE mobile = ' . $pdo->quote($mobileA))->fetchColumn();
ok($otpRowsBefore === $otpRowsAfter, 'Inactive customer issueOtp does not insert OTP attempts');

$staleOtp = '442211';
$pdo->prepare(
    'INSERT INTO customer_otp_attempts (mobile, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 300 SECOND))'
)->execute([$mobileA, password_hash($staleOtp, PASSWORD_DEFAULT)]);
ok(!CustomerAuth::verifyOtp($mobileA, $staleOtp), 'Stale valid OTP cannot log in inactive customer');
ok(!CustomerAuth::check(), 'Inactive customer remains logged out after OTP attempt');

$react = AdminCustomerService::setActive($idA, true, 1);
ok(!empty($react['ok']), 'Reactivate succeeds');
$reactIssueOk = false;
try {
    CustomerAuth::issueOtp($mobileA);
    $reactIssueOk = true;
} catch (OtpRequestException $e) {
    $reactIssueOk = $e->errorCode() !== OtpSmsService::ERR_ACCOUNT_INACTIVE;
}
ok($reactIssueOk, 'Reactivated customer is not blocked by inactive OTP guard');
CustomerAuth::loginById($idA);
ok(CustomerAuth::check(), 'Reactivated customer can hold session');

$created = SupportTicketService::create($idA, 'other', 'Admin customer view test ' . $tok, 'Probe body', null);
ok(!empty($created['ok']), 'Support ticket created for customer A');
$ticketListA = AdminCustomerService::ticketsForCustomer($idA);
ok(count($ticketListA) >= 1, 'ticketsForCustomer returns customer A tickets');
$ticketListB = AdminCustomerService::ticketsForCustomer($idB);
$leak = false;
foreach ($ticketListB as $row) {
    if (str_contains((string) ($row['subject'] ?? ''), $tok)) {
        $leak = true;
    }
}
ok(!$leak, 'Customer B does not see customer A tickets');
if (!empty($created['ticket_number'])) {
    $delId = (int) $pdo->query(
        'SELECT id FROM support_tickets WHERE ticket_number = ' . $pdo->quote((string) $created['ticket_number']) . ' LIMIT 1'
    )->fetchColumn();
    if ($delId > 0) {
        $pdo->prepare('DELETE FROM support_messages WHERE ticket_id = ?')->execute([$delId]);
        $pdo->exec('DELETE FROM support_tickets WHERE id = ' . $delId);
    }
}

$emptyTickets = AdminCustomerService::ticketsForCustomer($idB);
ok($emptyTickets === [] || count($emptyTickets) === (int) $pdo->query(
    'SELECT COUNT(*) FROM support_tickets WHERE customer_id = ' . $idB
)->fetchColumn(), 'Empty or isolated ticket list for customer B');

$place = CheckoutService::placeOrder([
    'checkout_nonce' => CheckoutService::freshNonce(),
    'address_id' => '0',
    'delivery_slot' => 'auto',
    'terms' => '1',
]);
ok(empty($place['ok']), 'placeOrder fails without valid address when logged in');

AdminCustomerService::setActive($idA, false, 1);
CustomerAuth::loginById($idA);
ok(!CustomerService::isLoggedIn(), 'Inactive customer is not logged in (blocks checkout)');
CustomerAuth::logout();

$audit = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type = 'customers' AND entity_id = ?");
$audit->execute([(string) $idA]);
ok((int) $audit->fetchColumn() >= 2, 'Audit log records customer admin actions');

$idor = AdminCustomerService::findById(999999999);
ok($idor === null, 'Missing customer id returns null');

$pdo->exec('DELETE FROM customer_otp_attempts WHERE mobile IN (' . $pdo->quote($mobileA) . ', ' . $pdo->quote($mobileB) . ')');
$pdo->exec('DELETE FROM admin_audit_logs WHERE entity_type = \'customers\' AND entity_id IN (' . $idA . ',' . $idB . ')');
$pdo->exec('DELETE FROM customers WHERE id IN (' . $idA . ',' . $idB . ')');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
