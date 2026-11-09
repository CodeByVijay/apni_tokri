<?php

declare(strict_types=1);

/**
 * Invoice configuration (AppSettings `invoice.*`). Seller identity is read from
 * {@see BusinessSettings} and {@see BrandingSettings}; only invoice-specific keys are stored here.
 */
final class InvoiceSettings
{
    public const DOC_RETAIL = 'retail_invoice';
    public const DOC_CREDIT_NOTE = 'credit_note';

    /** @var array<string, string> field => AppSettings key (invoice-specific only) */
    private const KEYS = [
        'logo_file' => 'invoice.logo_file',
        'prefix' => 'invoice.prefix',
        'fy_numbering' => 'invoice.fy_numbering',
        'start_sequence' => 'invoice.start_sequence',
        'footer' => 'invoice.footer',
        'return_url' => 'invoice.return_url',
        'terms_url' => 'invoice.terms_url',
        'show_sku' => 'invoice.show_sku',
        'show_delivery_slot' => 'invoice.show_delivery_slot',
        'show_map_link' => 'invoice.show_map_link',
        'enable_thermal' => 'invoice.enable_thermal',
        'enable_email' => 'invoice.enable_email',
        'auto_email' => 'invoice.auto_email',
        'admin_resend' => 'invoice.admin_resend',
    ];

    /** @var list<string> */
    private const BOOL_FIELDS = [
        'fy_numbering', 'show_sku', 'show_delivery_slot', 'show_map_link',
        'enable_thermal', 'enable_email', 'auto_email', 'admin_resend',
    ];

    /** @return array<string, string> defaults for boolean toggles ('1'/'0') */
    private static function boolDefaults(): array
    {
        return [
            'fy_numbering' => '1', 'show_sku' => '1', 'show_delivery_slot' => '1', 'show_map_link' => '0',
            'enable_thermal' => '0', 'enable_email' => '1', 'auto_email' => '0', 'admin_resend' => '1',
        ];
    }

    /**
     * Raw stored values (empty string when unset) — for the admin form.
     *
     * @return array<string, string>
     */
    public static function formState(): array
    {
        $out = [];
        $defaults = self::boolDefaults();
        foreach (self::KEYS as $field => $key) {
            $v = AppSettings::get($key, null);
            if ($v === null || $v === '') {
                $v = isset($defaults[$field]) ? $defaults[$field] : $v;
            }
            $out[$field] = trim((string) ($v ?? ''));
        }
        if ($out['prefix'] === '') {
            $out['prefix'] = 'AT';
        }
        if ($out['start_sequence'] === '') {
            $out['start_sequence'] = '1';
        }

        return $out;
    }

    /**
     * Effective seller identity as printed on a NEW invoice (canonical business + branding settings).
     *
     * @return array<string, string|null>
     */
    public static function seller(): array
    {
        $s = [
            'brand_name' => BrandingSettings::siteName(),
            'legal_name' => BusinessSettings::legalName(),
            'tagline' => BrandingSettings::tagline(),
            'address' => BusinessSettings::addressLine(),
            'city' => BusinessSettings::city(),
            'state' => BusinessSettings::state(),
            'pincode' => BusinessSettings::pincode(),
            'phone' => BusinessSettings::supportPhoneDisplay(),
            'email' => BusinessSettings::supportEmail(),
            'website' => BusinessSettings::websiteUrl(),
            'fssai' => BusinessSettings::fssaiLicence(),
            'udyam_number' => null,
            'udyam_enterprise' => null,
            'gstin' => null,
        ];
        $udyam = BusinessSettings::udyamForInvoice();
        if ($udyam !== null) {
            $s['udyam_number'] = $udyam['number'];
            $s['udyam_enterprise'] = $udyam['enterprise_name'];
        }
        foreach ($s as $k => $v) {
            if ($v === '') {
                $s[$k] = null;
            }
        }

        return $s;
    }

    /** @return array<string, mixed> options that influence rendering, frozen into each snapshot */
    public static function renderOptions(): array
    {
        $f = self::formState();

        return [
            'footer' => $f['footer'] !== '' ? $f['footer'] : 'Thank you for shopping with Veggie Tokri.',
            'return_url' => $f['return_url'] !== '' ? $f['return_url'] : SeoUrl::absolute('/page.php?slug=refund-policy'),
            'terms_url' => $f['terms_url'] !== '' ? $f['terms_url'] : SeoUrl::absolute('/page.php?slug=terms'),
            'show_sku' => $f['show_sku'] === '1',
            'show_delivery_slot' => $f['show_delivery_slot'] === '1',
            'show_map_link' => $f['show_map_link'] === '1',
        ];
    }

    public static function prefix(): string
    {
        $p = strtoupper(self::formState()['prefix']);

        return preg_match('/^[A-Z0-9]{1,8}$/', $p) === 1 ? $p : 'AT';
    }

    public static function fyNumbering(): bool
    {
        return self::formState()['fy_numbering'] === '1';
    }

    public static function startSequence(): int
    {
        return max(1, (int) self::formState()['start_sequence']);
    }

    public static function thermalEnabled(): bool
    {
        return self::formState()['enable_thermal'] === '1';
    }

    public static function emailEnabled(): bool
    {
        return self::formState()['enable_email'] === '1';
    }

    public static function autoEmail(): bool
    {
        return self::emailEnabled() && self::formState()['auto_email'] === '1';
    }

    public static function adminResendAllowed(): bool
    {
        return self::formState()['admin_resend'] === '1';
    }

    /**
     * GST mode is architected for future registration but intentionally fixed to "unregistered":
     * no GSTIN, no tax split, no tax-invoice labels are produced while this returns 'unregistered'.
     */
    public static function gstMode(): string
    {
        return 'unregistered';
    }

    /** Absolute path of the stored logo (png/jpeg) or null. */
    public static function logoPath(): ?string
    {
        $file = self::formState()['logo_file'];
        if ($file === '' || preg_match('/^[a-f0-9]{24}\.(png|jpg)$/', $file) !== 1) {
            return null;
        }
        $path = InvoiceStorage::assetDir() . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $file $_FILES['logo']
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post, ?array $file = null): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema not ready.'];
        }
        $errors = [];
        $v = [];
        foreach (['footer' => 300] as $f => $max) {
            $v[$f] = trim(preg_replace('/\s+/', ' ', (string) ($post[$f] ?? '')) ?? '');
            if (mb_strlen($v[$f]) > $max) {
                $errors[$f] = 'Maximum ' . $max . ' characters.';
            }
        }
        foreach (['return_url', 'terms_url'] as $f) {
            $v[$f] = trim((string) ($post[$f] ?? ''));
            if ($v[$f] !== '' && (filter_var($v[$f], FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $v[$f]))) {
                $errors[$f] = 'Enter a full http(s) URL.';
            }
        }
        $v['prefix'] = strtoupper(trim((string) ($post['prefix'] ?? '')));
        if ($v['prefix'] === '') {
            $v['prefix'] = 'AT';
        }
        if (preg_match('/^[A-Z0-9]{1,8}$/', $v['prefix']) !== 1) {
            $errors['prefix'] = 'Prefix must be 1–8 letters/numbers.';
        }
        $seq = trim((string) ($post['start_sequence'] ?? '1'));
        if ($seq === '' || preg_match('/^[0-9]{1,8}$/', $seq) !== 1 || (int) $seq < 1) {
            $errors['start_sequence'] = 'Starting sequence must be a positive number.';
        }
        $v['start_sequence'] = $seq === '' ? '1' : $seq;
        foreach (self::BOOL_FIELDS as $b) {
            $v[$b] = !empty($post[$b]) ? '1' : '0';
        }
        if ($v['auto_email'] === '1' && $v['enable_email'] !== '1') {
            $errors['auto_email'] = 'Enable customer invoice email first.';
        }

        $newLogo = null;
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = InvoiceStorage::storeLogoUpload($file);
            if (!$res['ok']) {
                $errors['logo'] = (string) $res['message'];
            } else {
                $newLogo = (string) $res['file'];
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        foreach ($v as $field => $value) {
            AppSettings::set(self::KEYS[$field], $value !== '' ? $value : null);
        }
        if ($newLogo !== null) {
            AppSettings::set(self::KEYS['logo_file'], $newLogo);
        } elseif (!empty($post['remove_logo'])) {
            AppSettings::set(self::KEYS['logo_file'], null);
        }

        return ['ok' => true, 'message' => 'Invoice settings saved. Existing invoices are unchanged.'];
    }
}
