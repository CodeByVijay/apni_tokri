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

$backup = [
    'business' => BusinessSettings::adminFormState(),
    'brand' => AppSettings::get(BrandingSettings::KEY_SITE_NAME, null),
    'tag' => AppSettings::get(BrandingSettings::KEY_TAGLINE, null),
    'invoice_prefix' => AppSettings::get('invoice.prefix', null),
    'invoice_footer' => AppSettings::get('invoice.footer', null),
    'migration' => AppSettings::get(BusinessSettings::MIGRATION_INVOICE_IDENTITY, null),
];

BusinessSettings::saveFromPost(array_merge($backup['business'], [
    'store_name' => 'Consolidated Store',
    'legal_name' => 'Consolidated Store Pvt Ltd',
    'address' => '12 Warehouse Road',
    'city' => 'Indore',
    'state' => 'Madhya Pradesh',
    'pincode' => '452001',
    'support_phone' => '9876500111',
    'support_email' => 'billing@consolidated.test',
    'fssai_licence' => '12345678901234',
]));
AppSettings::set(BrandingSettings::KEY_SITE_NAME, 'Consolidated Brand');
AppSettings::resetCache();

$seller = InvoiceSettings::seller();
ok($seller['brand_name'] === 'Consolidated Brand', 'Invoice brand from website branding');
ok($seller['legal_name'] === 'Consolidated Store Pvt Ltd', 'Invoice legal from business profile');
ok($seller['fssai'] === '12345678901234', 'Invoice FSSAI from business profile');
ok($seller['phone'] === BusinessSettings::supportPhoneDisplay(), 'Invoice phone from business profile');
ok($seller['email'] === 'billing@consolidated.test', 'Invoice email from business profile');
ok($seller['address'] === '12 Warehouse Road' && $seller['city'] === 'Indore', 'Invoice address from business profile');

BusinessSettings::saveFromPost(array_merge(BusinessSettings::adminFormState(), [
    'support_phone' => '9123456780',
]));
AppSettings::resetCache();
ok(InvoiceSettings::seller()['phone'] === BusinessSettings::supportPhoneDisplay(), 'Business phone change reflects in invoice seller()');

AppSettings::set(BrandingSettings::KEY_SITE_NAME, 'Renamed Brand Only');
AppSettings::resetCache();
ok(InvoiceSettings::seller()['brand_name'] === 'Renamed Brand Only', 'Branding name updates default invoice brand');

$beforeFooter = AppSettings::get('invoice.footer', null);
InvoiceSettings::saveFromPost(array_merge(InvoiceSettings::formState(), ['footer' => 'Custom invoice footer ' . bin2hex(random_bytes(2))]));
AppSettings::resetCache();
ok(BusinessSettings::adminFormState()['store_name'] === 'Consolidated Store', 'Invoice-only save does not alter business profile');
ok(AppSettings::get('invoice.brand_name', null) === null || trim((string) AppSettings::get('invoice.brand_name', '')) === '', 'No independent invoice.brand_name maintained after save');

$formKeys = array_keys(InvoiceSettings::formState());
ok(!in_array('brand_name', $formKeys, true) && !in_array('fssai', $formKeys, true), 'Invoice form state has no duplicate identity fields');

// Legacy migration: copy invoice-only values when canonical empty
AppSettings::set(BusinessSettings::MIGRATION_INVOICE_IDENTITY, null);
AppSettings::set('business.legal_name', null);
AppSettings::set('business.fssai_licence', null);
AppSettings::set('invoice.fssai', '99998888777766');
AppSettings::set('invoice.legal_name', 'Legacy Legal From Invoice');
AppSettings::resetCache();
BusinessSettings::migrateLegacyInvoiceIdentity();
AppSettings::resetCache();
ok(BusinessSettings::fssaiLicence() === '99998888777766', 'Migration copies invoice FSSAI when business empty');
ok(trim(AppSettings::get(BusinessSettings::KEY_LEGAL_NAME, '') ?? '') === 'Legacy Legal From Invoice', 'Migration copies invoice legal name');

// restore
BusinessSettings::saveFromPost($backup['business']);
AppSettings::set(BrandingSettings::KEY_SITE_NAME, $backup['brand']);
AppSettings::set(BrandingSettings::KEY_TAGLINE, $backup['tag']);
AppSettings::set('invoice.prefix', $backup['invoice_prefix']);
AppSettings::set('invoice.footer', $backup['invoice_footer']);
AppSettings::set(BusinessSettings::MIGRATION_INVOICE_IDENTITY, $backup['migration']);
AppSettings::resetCache();

echo $fail === 0 ? "PASS: settings profile consolidation.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
