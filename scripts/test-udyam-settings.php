<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$validUdyam = 'UDYAM-MP-09-1234567';
$orig = BusinessSettings::adminFormState();
$restorePost = array_merge($orig, [
    'udyam_enabled' => '0',
    'udyam_registration_number' => '',
    'udyam_enterprise_name' => '',
    'udyam_verify_url' => '',
]);

ok(BusinessSettings::isValidUdyamNumber($validUdyam), 'Valid Udyam format accepted');
ok(!BusinessSettings::isValidUdyamNumber('UDYAM-1-09-1234567'), 'Invalid Udyam format rejected');
ok(BusinessSettings::udyamPublicDisplay() === null, 'Hidden when disabled');

$save = BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '1',
    'udyam_registration_number' => $validUdyam,
    'udyam_enterprise_name' => 'Test MSME Unit',
    'udyam_verify_url' => '',
]));
AppSettings::resetCache();
ok($save['ok'] === true, 'Save enabled Udyam settings');
$form = BusinessSettings::adminFormState();
ok($form['udyam_registration_number'] === $validUdyam, 'Registration number stored uppercase');
ok($form['udyam_enterprise_name'] === 'Test MSME Unit', 'Enterprise name stored');
ok(BusinessSettings::udyamVerifyUrl() === BusinessSettings::DEFAULT_UDYAM_VERIFY_URL, 'Default verify URL when blank');

$blankOptionals = BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '1',
    'udyam_registration_number' => $validUdyam,
    'udyam_enterprise_name' => '',
    'udyam_verify_url' => '',
    'remove_udyam_certificate' => '1',
]));
AppSettings::resetCache();
ok($blankOptionals['ok'] === true, 'Save Udyam with blank enterprise and verify URL (persistField must not receive null)');
ok(trim(BusinessSettings::adminFormState()['udyam_verify_url']) === '', 'Blank verify URL clears stored override');
ok(BusinessSettings::udyamEnterpriseName() === null, 'Blank enterprise clears stored value');

$pub = BusinessSettings::udyamPublicDisplay();
ok($pub !== null && $pub['number'] === $validUdyam, 'Public display payload');
ok($pub['enterprise_name'] === 'Test MSME Unit', 'Public display enterprise');

$seller = InvoiceSettings::seller();
ok(($seller['udyam_number'] ?? '') === $validUdyam, 'Invoice seller includes Udyam number');
ok(($seller['udyam_enterprise'] ?? '') === 'Test MSME Unit', 'Invoice seller includes enterprise');

ob_start();
$showBottomNav = false;
$activeNav = '';
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerOn = (string) ob_get_clean();
ok(str_contains($footerOn, $validUdyam), 'Footer shows Udyam number when enabled');
ok(str_contains($footerOn, 'Udyam Registration No.:'), 'Footer Udyam label');
ok(!str_contains($footerOn, 'Test MSME Unit'), 'Footer omits enterprise name');
ok(!str_contains($footerOn, '>Verify</a>'), 'Footer omits verify link');
ok(str_contains($footerOn, 'FSSAI Lic. No.:'), 'Footer FSSAI label uses colon');
ok(str_contains($footerOn, ' | '), 'Footer registration details separated by pipe');

AppSettings::resetCache();
BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '1',
    'udyam_registration_number' => $validUdyam,
    'udyam_verify_url' => 'https://udyamregistration.gov.in/Udyam_Verify.aspx',
]));
AppSettings::resetCache();
ok(BusinessSettings::udyamVerifyUrl() === BusinessSettings::DEFAULT_UDYAM_VERIFY_URL, 'Canonicalize official verify URL with wrong path casing');
ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerCanonical = (string) ob_get_clean();
ok(!str_contains($footerCanonical, '>Verify</a>'), 'Footer still omits verify after canonical URL save');
ok(BusinessSettings::canonicalUdyamVerifyUrl('https://verify.example.test/udyam') === 'https://verify.example.test/udyam', 'Custom verify URL preserved');
ok(!str_contains($footerOn, '.pdf'), 'Footer does not expose certificate filename');

$bad = BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '1',
    'udyam_registration_number' => 'NOT-VALID',
]));
AppSettings::resetCache();
ok($bad['ok'] === false, 'Reject invalid registration number');

BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '0',
    'udyam_registration_number' => $validUdyam,
]));
AppSettings::resetCache();
ok(BusinessSettings::udyamPublicDisplay() === null, 'Disabled hides footer block even if number stored');

ob_start();
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
$footerOff = (string) ob_get_clean();
ok(!str_contains($footerOff, 'Udyam Registration No.:'), 'Footer omits Udyam when disabled');

$frozenSnap = [
    'schema' => 1,
    'generated_at' => date('Y-m-d H:i:s'),
    'seller' => [
        'brand_name' => 'Frozen Brand',
        'legal_name' => 'Frozen Legal',
        'tagline' => null,
        'address' => '1 Test St',
        'city' => 'City',
        'state' => 'ST',
        'pincode' => '452001',
        'phone' => '9000000000',
        'email' => null,
        'website' => 'https://example.test',
        'fssai' => '10012345678901',
        'udyam_number' => 'UDYAM-XX-01-1111111',
        'udyam_enterprise' => 'Frozen Enterprise',
        'gstin' => null,
        'logo_file' => '',
    ],
    'options' => ['footer' => 'Thanks', 'show_sku' => '0', 'show_delivery_slot' => '0', 'show_map_link' => '0', 'return_url' => '', 'terms_url' => ''],
    'document' => [
        'type' => InvoiceSettings::DOC_INVOICE,
        'title' => 'RETAIL INVOICE',
        'number' => 'AT/TEST/00001',
        'date' => date('Y-m-d'),
        'badge' => 'Paid',
    ],
    'customer' => ['name' => 'Test', 'mobile' => '9000000001', 'email' => '', 'billing_address' => null],
    'order' => ['id' => 1, 'number' => 'ORD-1', 'placed_at' => date('Y-m-d'), 'status_label' => 'Delivered', 'payment_status_label' => 'Paid'],
    'delivery_address' => null,
    'delivery' => null,
    'items' => [['name' => 'Item', 'variant' => '', 'sku' => '', 'qty' => 1, 'unit_price' => 10.0, 'line_total' => 10.0]],
    'pricing' => ['subtotal' => 10.0, 'discount' => 0.0, 'coupon_code' => null, 'delivery_fee' => 0.0, 'rounding' => 0.0, 'grand_total' => 10.0, 'amount_paid' => 10.0, 'amount_refunded' => 0.0, 'balance_due' => 0.0],
    'payments' => [],
    'logo_path' => null,
];

BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'udyam_enabled' => '1',
    'udyam_registration_number' => 'UDYAM-GJ-02-9999999',
    'udyam_enterprise_name' => 'Current Settings Name',
]));
AppSettings::resetCache();
$pdfBytes = InvoicePdfRenderer::renderA4($frozenSnap);
ok(strncmp($pdfBytes, '%PDF-', 5) === 0, 'Invoice PDF renders from snapshot');
ok(str_contains($pdfBytes, 'UDYAM-XX-01-1111111'), 'PDF uses frozen snapshot Udyam number');
ok(!str_contains($pdfBytes, 'UDYAM-GJ-02-9999999'), 'PDF does not read live Udyam settings');

BusinessSettings::saveFromPost($restorePost);
AppSettings::resetCache();

echo $fail === 0 ? "PASS: udyam settings.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
