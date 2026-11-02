<?php

declare(strict_types=1);

/**
 * Business / support contact settings (AppSettings `business.*` keys).
 *
 * Name precedence (documented):
 * - Header logo text, SEO site name, and `__()` brand_name → {@see BrandingSettings::siteName()} (Website Branding).
 * - Footer copyright legal line, contact page heading, and structured “business identity” → {@see self::legalName()}.
 * - legalName() uses `business.store_name` when set, otherwise falls back to BrandingSettings::siteName().
 */
final class BusinessSettings
{
    public const KEY_STORE_NAME = 'business.store_name';
    public const KEY_SUPPORT_PHONE = 'business.support_phone';
    public const KEY_SUPPORT_WHATSAPP = 'business.support_whatsapp';
    public const KEY_SUPPORT_EMAIL = 'business.support_email';
    public const KEY_FSSAI_LICENCE = 'business.fssai_licence';

    /** Legal / registered business name for footer and contact (not header SEO brand). */
    public static function legalName(): string
    {
        $business = trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? '');
        if ($business !== '') {
            return $business;
        }

        return BrandingSettings::siteName();
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

    /** @return array<string, string> */
    public static function adminFormState(): array
    {
        return [
            'store_name' => trim(AppSettings::get(self::KEY_STORE_NAME, '') ?? ''),
            'support_phone' => trim(AppSettings::get(self::KEY_SUPPORT_PHONE, '') ?? ''),
            'support_whatsapp' => trim(AppSettings::get(self::KEY_SUPPORT_WHATSAPP, '') ?? ''),
            'support_email' => trim(AppSettings::get(self::KEY_SUPPORT_EMAIL, '') ?? ''),
            'fssai_licence' => trim(AppSettings::get(self::KEY_FSSAI_LICENCE, '') ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema not ready.'];
        }

        $errors = [];
        $storeName = trim((string) ($post['store_name'] ?? ''));
        $phone = trim((string) ($post['support_phone'] ?? ''));
        $whatsapp = trim((string) ($post['support_whatsapp'] ?? ''));
        $email = trim((string) ($post['support_email'] ?? ''));
        $fssai = trim((string) ($post['fssai_licence'] ?? ''));

        if ($storeName !== '' && mb_strlen($storeName) > 160) {
            $errors['store_name'] = 'Store name must be 160 characters or fewer.';
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

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        self::persistField(self::KEY_STORE_NAME, $storeName);
        self::persistField(self::KEY_SUPPORT_PHONE, $phone !== '' ? (Validation::normalizeMobileIndia($phone) ?? $phone) : '');
        self::persistField(self::KEY_SUPPORT_WHATSAPP, $whatsapp !== '' ? (Validation::normalizeMobileIndia($whatsapp) ?? $whatsapp) : '');
        self::persistField(self::KEY_SUPPORT_EMAIL, $email);
        self::persistField(self::KEY_FSSAI_LICENCE, $fssai);

        return ['ok' => true, 'message' => 'Business settings saved.'];
    }

    private static function persistField(string $key, string $value): void
    {
        AppSettings::set($key, $value !== '' ? $value : null);
    }
}
