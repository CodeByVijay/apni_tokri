<?php

declare(strict_types=1);

/**
 * Canonical business / store profile (AppSettings `business.*` keys).
 *
 * Name precedence (documented):
 * - Header logo text, SEO site name, default invoice brand → {@see BrandingSettings::siteName()} (Website branding).
 * - Footer copyright, contact page, invoice legal line → {@see self::legalName()}.
 * - legalName() uses `business.legal_name`, then `business.store_name`, then branding site name.
 * - Invoices, emails and PDFs read seller identity via {@see InvoiceSettings::seller()} (no duplicate invoice.* identity keys).
 * - Public website URL for invoices and similar: {@see self::websiteUrl()} (`business.website_url`), with default https://veggietokri.com when unset.
 */
final class BusinessSettings
{
    /** Default customer-facing site URL when business.website_url is not set (canonical Veggie Tokri domain). */
    public const DEFAULT_WEBSITE_URL = 'https://veggietokri.com';

    public const KEY_STORE_NAME = 'business.store_name';
    public const KEY_LEGAL_NAME = 'business.legal_name';
    public const KEY_WEBSITE_URL = 'business.website_url';
    public const KEY_ADDRESS = 'business.address';
    public const KEY_CITY = 'business.city';
    public const KEY_STATE = 'business.state';
    public const KEY_PINCODE = 'business.pincode';
    public const KEY_SUPPORT_PHONE = 'business.support_phone';
    public const KEY_SUPPORT_WHATSAPP = 'business.support_whatsapp';
    public const KEY_SUPPORT_EMAIL = 'business.support_email';
    public const KEY_FSSAI_LICENCE = 'business.fssai_licence';
    public const KEY_UDYAM_ENABLED = 'business.udyam_enabled';
    public const KEY_UDYAM_NUMBER = 'business.udyam_registration_number';
    public const KEY_UDYAM_ENTERPRISE = 'business.udyam_enterprise_name';
    public const KEY_UDYAM_VERIFY_URL = 'business.udyam_verify_url';
    public const KEY_UDYAM_CERTIFICATE_FILE = 'business.udyam_certificate_file';

    public const DEFAULT_UDYAM_VERIFY_URL = 'https://udyamregistration.gov.in/Udyam_verify.aspx';

    public const MIGRATION_INVOICE_IDENTITY = 'business.invoice_identity_migrated_v1';

    /** Trading / store name when set (optional). */
    public static function storeName(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? '');

        return $v !== '' ? $v : null;
    }

    /** Legal / registered business name for footer, contact and invoices. */
    public static function legalName(): string
    {
        $legal = trim(AppSettings::get(self::KEY_LEGAL_NAME, '') ?? '');
        if ($legal !== '') {
            return $legal;
        }
        $business = trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? '');
        if ($business !== '') {
            return $business;
        }

        return BrandingSettings::siteName();
    }

    public static function addressLine(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_ADDRESS, '') ?? '');

        return $v !== '' ? $v : null;
    }

    public static function city(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_CITY, '') ?? '');

        return $v !== '' ? $v : null;
    }

    public static function state(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_STATE, '') ?? '');

        return $v !== '' ? $v : null;
    }

    public static function pincode(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_PINCODE, '') ?? '');
        if ($v === '' || preg_match('/^[1-9][0-9]{5}$/', $v) !== 1) {
            return $v !== '' ? $v : null;
        }

        return $v;
    }

    public static function supportPhoneDigits(): ?string
    {
        $raw = trim(AppSettings::get(self::KEY_SUPPORT_PHONE, '') ?? '');
        if ($raw === '') {
            return null;
        }

        return Validation::normalizeMobileIndia($raw);
    }

    public static function supportPhoneDisplay(): ?string
    {
        $digits = self::supportPhoneDigits();
        if ($digits === null) {
            return null;
        }

        return '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5);
    }

    public static function supportPhoneTelUrl(): ?string
    {
        $digits = self::supportPhoneDigits();
        if ($digits === null) {
            return null;
        }

        return 'tel:+91' . $digits;
    }

    public static function supportWhatsappDigits(): ?string
    {
        $raw = trim(AppSettings::get(self::KEY_SUPPORT_WHATSAPP, '') ?? '');
        if ($raw === '') {
            return null;
        }

        return Validation::normalizeMobileIndia($raw);
    }

    public static function whatsappUrl(): ?string
    {
        $digits = self::supportWhatsappDigits();
        if ($digits === null) {
            return null;
        }

        return 'https://wa.me/91' . $digits;
    }

    public static function supportEmail(): ?string
    {
        $email = trim(AppSettings::get(self::KEY_SUPPORT_EMAIL, '') ?? '');
        if ($email === '' || !Validation::email($email)) {
            return null;
        }

        return $email;
    }

    public static function fssaiLicence(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_FSSAI_LICENCE, '') ?? '');
        if ($v === '') {
            return null;
        }

        return $v;
    }

    public static function udyamEnabled(): bool
    {
        return (AppSettings::get(self::KEY_UDYAM_ENABLED, '') ?? '') === '1';
    }

    public static function udyamRegistrationNumber(): ?string
    {
        $v = strtoupper(trim(AppSettings::get(self::KEY_UDYAM_NUMBER, '') ?? ''));

        return $v !== '' ? $v : null;
    }

    public static function udyamEnterpriseName(): ?string
    {
        $v = trim(AppSettings::get(self::KEY_UDYAM_ENTERPRISE, '') ?? '');

        return $v !== '' ? $v : null;
    }

    public static function udyamVerifyUrl(): string
    {
        $v = trim(AppSettings::get(self::KEY_UDYAM_VERIFY_URL, '') ?? '');
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }

        return self::DEFAULT_UDYAM_VERIFY_URL;
    }

    /** Admin reference only — certificate PDF is not exposed on the storefront. */
    public static function udyamCertificateStoredFile(): ?string
    {
        $file = trim(AppSettings::get(self::KEY_UDYAM_CERTIFICATE_FILE, '') ?? '');
        if ($file === '' || preg_match('/^[a-f0-9]{24}\.pdf$/', $file) !== 1) {
            return null;
        }

        return $file;
    }

    public static function udyamCertificatePath(): ?string
    {
        $file = self::udyamCertificateStoredFile();
        if ($file === null) {
            return null;
        }
        $path = InvoiceStorage::assetDir() . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Public footer / compliance display when enabled and registration number is set.
     *
     * @return array{number: string, enterprise_name: ?string, verify_url: string}|null
     */
    public static function udyamPublicDisplay(): ?array
    {
        if (!self::udyamEnabled()) {
            return null;
        }
        $number = self::udyamRegistrationNumber();
        if ($number === null) {
            return null;
        }

        return [
            'number' => $number,
            'enterprise_name' => self::udyamEnterpriseName(),
            'verify_url' => self::udyamVerifyUrl(),
        ];
    }

    /**
     * Values frozen onto new invoice snapshots (registration + enterprise only).
     *
     * @return array{number: string, enterprise_name: ?string}|null
     */
    public static function udyamForInvoice(): ?array
    {
        $pub = self::udyamPublicDisplay();
        if ($pub === null) {
            return null;
        }

        return [
            'number' => $pub['number'],
            'enterprise_name' => $pub['enterprise_name'],
        ];
    }

    public static function isValidUdyamNumber(string $number): bool
    {
        $n = strtoupper(trim($number));

        return preg_match('/^UDYAM-[A-Z]{2}-[0-9]{2}-[0-9]{7}$/', $n) === 1;
    }

    /**
     * Public website URL for invoices and business-facing display (not the app deployment URL).
     * Falls back to {@see self::DEFAULT_WEBSITE_URL} when unset or cleared.
     */
    public static function websiteUrl(): string
    {
        $v = trim(AppSettings::get(self::KEY_WEBSITE_URL, '') ?? '');
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $v) === 1) {
            return $v;
        }

        return self::DEFAULT_WEBSITE_URL;
    }

    /** Raw stored value for the admin form (empty when not configured). */
    public static function websiteUrlForForm(): string
    {
        return trim(AppSettings::get(self::KEY_WEBSITE_URL, '') ?? '');
    }

    /** @return array<string, string> */
    public static function adminFormState(): array
    {
        return [
            'store_name' => trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? ''),
            'legal_name' => trim(AppSettings::get(self::KEY_LEGAL_NAME, '') ?? ''),
            'address' => trim(AppSettings::get(self::KEY_ADDRESS, '') ?? ''),
            'city' => trim(AppSettings::get(self::KEY_CITY, '') ?? ''),
            'state' => trim(AppSettings::get(self::KEY_STATE, '') ?? ''),
            'pincode' => trim(AppSettings::get(self::KEY_PINCODE, '') ?? ''),
            'support_phone' => trim(AppSettings::get(self::KEY_SUPPORT_PHONE, '') ?? ''),
            'support_whatsapp' => trim(AppSettings::get(self::KEY_SUPPORT_WHATSAPP, '') ?? ''),
            'support_email' => trim(AppSettings::get(self::KEY_SUPPORT_EMAIL, '') ?? ''),
            'fssai_licence' => trim(AppSettings::get(self::KEY_FSSAI_LICENCE, '') ?? ''),
            'website_url' => self::websiteUrlForForm(),
            'udyam_enabled' => self::udyamEnabled() ? '1' : '0',
            'udyam_registration_number' => trim(AppSettings::get(self::KEY_UDYAM_NUMBER, '') ?? ''),
            'udyam_enterprise_name' => trim(AppSettings::get(self::KEY_UDYAM_ENTERPRISE, '') ?? ''),
            'udyam_verify_url' => trim(AppSettings::get(self::KEY_UDYAM_VERIFY_URL, '') ?? ''),
        ];
    }

    /**
     * Copy legacy `invoice.*` identity overrides into business/branding when canonical keys are empty.
     * Safe to run on every bootstrap; runs the data copy once per installation.
     */
    public static function migrateLegacyInvoiceIdentity(): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        if (AppSettings::get(self::MIGRATION_INVOICE_IDENTITY, '') === '1') {
            return;
        }

        $copyIfEmpty = static function (string $canonicalKey, string $legacyKey): void {
            $cur = trim(AppSettings::get($canonicalKey, '') ?? '');
            if ($cur !== '') {
                return;
            }
            $legacy = trim(AppSettings::get($legacyKey, '') ?? '');
            if ($legacy === '') {
                return;
            }
            AppSettings::set($canonicalKey, $legacy);
        };

        $copyIfEmpty(self::KEY_LEGAL_NAME, 'invoice.legal_name');
        $copyIfEmpty(self::KEY_ADDRESS, 'invoice.address');
        $copyIfEmpty(self::KEY_CITY, 'invoice.city');
        $copyIfEmpty(self::KEY_STATE, 'invoice.state');
        $copyIfEmpty(self::KEY_PINCODE, 'invoice.pincode');
        $copyIfEmpty(self::KEY_FSSAI_LICENCE, 'invoice.fssai');
        $copyIfEmpty(self::KEY_SUPPORT_EMAIL, 'invoice.email');
        $copyIfEmpty(self::KEY_WEBSITE_URL, 'invoice.website_url');

        $legacyPhone = trim(AppSettings::get('invoice.phone', '') ?? '');
        if ($legacyPhone !== '' && trim(AppSettings::get(self::KEY_SUPPORT_PHONE, '') ?? '') === '') {
            $digits = Validation::normalizeMobileIndia($legacyPhone);
            if ($digits !== null) {
                AppSettings::set(self::KEY_SUPPORT_PHONE, $digits);
            }
        }

        $legacyBrand = trim(AppSettings::get('invoice.brand_name', '') ?? '');
        if ($legacyBrand !== '' && trim(AppSettings::get(BrandingSettings::KEY_SITE_NAME, '') ?? '') === '') {
            AppSettings::set(BrandingSettings::KEY_SITE_NAME, $legacyBrand);
        }

        $legacyTag = trim(AppSettings::get('invoice.tagline', '') ?? '');
        if ($legacyTag !== '' && trim(AppSettings::get(BrandingSettings::KEY_TAGLINE, '') ?? '') === '') {
            AppSettings::set(BrandingSettings::KEY_TAGLINE, $legacyTag);
        }

        // Historical installs used invoice legal_name only; store_name may already hold the trading name.
        $legacyLegal = trim(AppSettings::get('invoice.legal_name', '') ?? '');
        if ($legacyLegal !== ''
            && trim(AppSettings::get(self::KEY_LEGAL_NAME, '') ?? '') === ''
            && trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? '') === '') {
            AppSettings::set(self::KEY_STORE_NAME, $legacyLegal);
        }

        AppSettings::set(self::MIGRATION_INVOICE_IDENTITY, '1');
        AppSettings::resetCache();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $udyamCertificateFile $_FILES['udyam_certificate'] when present
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post, ?array $udyamCertificateFile = null): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema not ready.'];
        }

        $errors = [];
        $state = self::adminFormState();
        foreach (array_keys($state) as $key) {
            if (array_key_exists($key, $post)) {
                $state[$key] = trim((string) $post[$key]);
            }
        }
        $state['address'] = trim(preg_replace('/\s+/', ' ', $state['address']) ?? '');
        $storeName = $state['store_name'];
        $legalName = $state['legal_name'];
        $address = $state['address'];
        $city = $state['city'];
        $stateVal = $state['state'];
        $pincode = $state['pincode'];
        $phone = $state['support_phone'];
        $whatsapp = $state['support_whatsapp'];
        $email = $state['support_email'];
        $fssai = $state['fssai_licence'];
        $websiteUrl = $state['website_url'];
        $udyamEnabled = !empty($post['udyam_enabled']) ? '1' : '0';
        $udyamNumber = strtoupper(trim($state['udyam_registration_number']));
        $udyamEnterprise = trim($state['udyam_enterprise_name']);
        $udyamVerifyUrl = trim($state['udyam_verify_url']);

        foreach (['store_name' => $storeName, 'legal_name' => $legalName] as $field => $val) {
            if ($val !== '' && mb_strlen($val) > 160) {
                $errors[$field] = 'Must be 160 characters or fewer.';
            }
        }
        if ($address !== '' && mb_strlen($address) > 300) {
            $errors['address'] = 'Address must be 300 characters or fewer.';
        }
        if ($city !== '' && mb_strlen($city) > 80) {
            $errors['city'] = 'City must be 80 characters or fewer.';
        }
        if ($stateVal !== '' && mb_strlen($stateVal) > 80) {
            $errors['state'] = 'State must be 80 characters or fewer.';
        }
        if ($pincode !== '' && preg_match('/^[1-9][0-9]{5}$/', $pincode) !== 1) {
            $errors['pincode'] = 'Enter a valid 6-digit PIN code.';
        }
        if ($phone !== '' && Validation::normalizeMobileIndia($phone) === null) {
            $errors['support_phone'] = 'Enter a valid 10-digit Indian mobile number for support phone.';
        }
        if ($whatsapp !== '' && Validation::normalizeMobileIndia($whatsapp) === null) {
            $errors['support_whatsapp'] = 'Enter a valid 10-digit Indian mobile number for WhatsApp.';
        }
        if ($email !== '' && !Validation::email($email)) {
            $errors['support_email'] = 'Enter a valid support email address.';
        }
        if ($fssai !== '' && mb_strlen($fssai) > 64) {
            $errors['fssai_licence'] = 'FSSAI licence must be 64 characters or fewer.';
        }
        if ($fssai !== '' && !preg_match('/^[A-Za-z0-9\-\/]+$/', $fssai)) {
            $errors['fssai_licence'] = 'FSSAI licence may only contain letters, numbers, hyphens and slashes.';
        }
        if ($websiteUrl !== '') {
            if (filter_var($websiteUrl, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $websiteUrl)) {
                $errors['website_url'] = 'Enter a full http(s) URL, or leave blank to use the default.';
            }
        }
        if ($udyamEnterprise !== '' && mb_strlen($udyamEnterprise) > 160) {
            $errors['udyam_enterprise_name'] = 'Enterprise name must be 160 characters or fewer.';
        }
        if ($udyamNumber !== '' && !self::isValidUdyamNumber($udyamNumber)) {
            $errors['udyam_registration_number'] = 'Use format UDYAM-XX-00-0000000 (e.g. UDYAM-MP-09-1234567).';
        }
        if ($udyamEnabled === '1' && $udyamNumber === '') {
            $errors['udyam_registration_number'] = 'Enter the Udyam registration number or disable Udyam display.';
        }
        if ($udyamVerifyUrl !== '') {
            if (filter_var($udyamVerifyUrl, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $udyamVerifyUrl)) {
                $errors['udyam_verify_url'] = 'Enter a full http(s) verification URL, or leave blank for the official default.';
            }
        }

        $newCert = null;
        if ($udyamCertificateFile !== null && (int) ($udyamCertificateFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = InvoiceStorage::storeUdyamCertificateUpload($udyamCertificateFile);
            if (!$upload['ok']) {
                $errors['udyam_certificate'] = (string) ($upload['message'] ?? 'Certificate upload failed.');
            } else {
                $newCert = (string) ($upload['file'] ?? '');
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        self::persistField(self::KEY_STORE_NAME, $storeName);
        self::persistField(self::KEY_LEGAL_NAME, $legalName);
        self::persistField(self::KEY_ADDRESS, $address);
        self::persistField(self::KEY_CITY, $city);
        self::persistField(self::KEY_STATE, $stateVal);
        self::persistField(self::KEY_PINCODE, $pincode);
        self::persistField(self::KEY_SUPPORT_PHONE, $phone !== '' ? (Validation::normalizeMobileIndia($phone) ?? $phone) : '');
        self::persistField(self::KEY_SUPPORT_WHATSAPP, $whatsapp !== '' ? (Validation::normalizeMobileIndia($whatsapp) ?? $whatsapp) : '');
        self::persistField(self::KEY_SUPPORT_EMAIL, $email);
        self::persistField(self::KEY_FSSAI_LICENCE, $fssai);
        self::persistField(self::KEY_WEBSITE_URL, $websiteUrl);
        AppSettings::set(self::KEY_UDYAM_ENABLED, $udyamEnabled);
        self::persistField(self::KEY_UDYAM_NUMBER, $udyamNumber);
        self::persistField(self::KEY_UDYAM_ENTERPRISE, $udyamEnterprise);
        self::persistField(self::KEY_UDYAM_VERIFY_URL, $udyamVerifyUrl !== '' ? $udyamVerifyUrl : null);
        if ($newCert !== null && $newCert !== '') {
            AppSettings::set(self::KEY_UDYAM_CERTIFICATE_FILE, $newCert);
        } elseif (!empty($post['remove_udyam_certificate'])) {
            AppSettings::set(self::KEY_UDYAM_CERTIFICATE_FILE, null);
        }

        return ['ok' => true, 'message' => 'Business profile saved.'];
    }

    private static function persistField(string $key, string $value): void
    {
        AppSettings::set($key, $value !== '' ? $value : null);
    }
}
