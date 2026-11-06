<?php

declare(strict_types=1);

/**
 * Simple bilingual labels (English / Hindi). Extend per page in later stages.
 */
final class Lang
{
    /** @var string */
    private static $locale = 'en';

    /** @var array<string, array<string, string>> */
    private static $strings = [
        'brand_name' => ['en' => 'Veggie Tokri', 'hi' => 'वेजी टोकरी'],
        'tagline' => ['en' => 'Freshness, Ready for Your Kitchen.', 'hi' => 'ताज़गी, आपकी रसोई के लिए तैयार।'],
        'nav_home' => ['en' => 'Home', 'hi' => 'होम'],
        'nav_categories' => ['en' => 'Categories', 'hi' => 'श्रेणियाँ'],
        'nav_faqs' => ['en' => 'FAQs', 'hi' => 'सवाल-जवाब'],
        'nav_contact' => ['en' => 'Contact', 'hi' => 'संपर्क'],
        'nav_account' => ['en' => 'Account', 'hi' => 'खाता'],
        'nav_login' => ['en' => 'Login', 'hi' => 'लॉगिन'],
        'nav_cart' => ['en' => 'Cart', 'hi' => 'कार्ट'],
        'delivery_to' => ['en' => 'Deliver to', 'hi' => 'डिलीवरी'],
        'check_delivery' => ['en' => 'Check delivery area', 'hi' => 'डिलीवरी क्षेत्र जाँचें'],
        'search_placeholder' => ['en' => 'Search vegetables, fruits, pickles…', 'hi' => 'सब्ज़ी, फल, अचार खोजें…'],
        'menu_open' => ['en' => 'Open menu', 'hi' => 'मेनू खोलें'],
        'menu_close' => ['en' => 'Close menu', 'hi' => 'मेनू बंद करें'],
        'skip_to_content' => ['en' => 'Skip to main content', 'hi' => 'मुख्य सामग्री पर जाएँ'],
        'footer_about' => ['en' => 'About Veggie Tokri', 'hi' => 'वेजी टोकरी के बारे में'],
        'footer_policies' => ['en' => 'Policies', 'hi' => 'नीतियाँ'],
        'footer_support' => ['en' => 'Support', 'hi' => 'सहायता'],
        'need_help' => ['en' => 'Need help?', 'hi' => 'मदद चाहिए?'],
        'admin_panel' => ['en' => 'Admin', 'hi' => 'एडमिन'],
        'admin_dashboard' => ['en' => 'Dashboard', 'hi' => 'डैशबोर्ड'],
        'admin_sign_out' => ['en' => 'Sign out', 'hi' => 'साइन आउट'],
    ];

    public static function setLocale(string $locale): void
    {
        if ($locale === 'hi' || $locale === 'en') {
            self::$locale = $locale;
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function t(string $key): string
    {
        if ($key === 'brand_name' && class_exists('BrandingSettings', false)) {
            return BrandingSettings::siteName();
        }
        if ($key === 'tagline' && class_exists('BrandingSettings', false)) {
            return BrandingSettings::tagline();
        }
        if (!isset(self::$strings[$key])) {
            return $key;
        }
        $row = self::$strings[$key];

        return $row[self::$locale] ?? $row['en'] ?? $key;
    }

    /** Resolve locale from ?lang= or session (Stage 1 preview). */
    public static function bootstrapFromRequest(): void
    {
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            self::setLocale($_GET['lang']);
            $_SESSION['locale'] = self::$locale;
        } elseif (!empty($_SESSION['locale']) && is_string($_SESSION['locale'])) {
            self::setLocale($_SESSION['locale']);
        }
    }
}

function __(string $key): string
{
    return Lang::t($key);
}
