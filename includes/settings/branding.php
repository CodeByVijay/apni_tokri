<?php

declare(strict_types=1);

/**
 * Website branding (AppSettings keys + uploads/branding/).
 */
final class BrandingSettings
{
    public const KEY_SITE_NAME = 'branding.site_name';
    public const KEY_TAGLINE = 'branding.tagline';
    public const KEY_DEFAULT_SEO_TITLE = 'branding.default_seo_title';
    public const KEY_DEFAULT_META_DESC = 'branding.default_meta_description';
    public const KEY_LOGO_PATH = 'branding.logo_path';
    public const KEY_FOOTER_LOGO_PATH = 'branding.footer_logo_path';
    public const KEY_FAVICON_PATH = 'branding.favicon_path';
    public const KEY_OG_IMAGE_PATH = 'branding.og_image_path';
    public const KEY_LOGO_ALT = 'branding.logo_alt';
    public const KEY_FOOTER_BRAND_DESCRIPTION = 'branding.footer_brand_description';

    public const DEFAULT_FOOTER_BRAND_DESCRIPTION = 'Har Ghar Ki Taazgi — fresh vegetables, fruits, ready-cut produce and pantry favourites for Indore homes.';

    public const DEFAULT_LOGO = 'assets/images/logo.svg';
    public const UPLOAD_SUBDIR = 'branding';

    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;
    private const MAX_FAVICON_BYTES = 512 * 1024;
    private const MAX_OG_BYTES = 3 * 1024 * 1024;
    private const MAX_LOGO_PX = 1200;
    private const MAX_FAVICON_PX = 512;
    private const MAX_OG_W = 2400;
    private const MAX_OG_H = 2400;

    /** @var array<string, list<string>> */
    private static $logoMimes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/svg+xml' => [], // rejected in storeBrandingFile
    ];

    /** @var array<string, list<string>> */
    private static $faviconMimes = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/x-icon' => ['ico'],
        'image/vnd.microsoft.icon' => ['ico'],
    ];

    public static function siteName(): string
    {
        $custom = trim(AppSettings::get(self::KEY_SITE_NAME, '') ?? '');
        if ($custom !== '') {
            return $custom;
        }
        $cfg = trim((string) (App::config()['app']['name'] ?? ''));

        return $cfg !== '' ? $cfg : 'Apni Tokri';
    }

    public static function tagline(): string
    {
        $custom = trim(AppSettings::get(self::KEY_TAGLINE, '') ?? '');
        if ($custom !== '') {
            return $custom;
        }
        if (Lang::locale() === 'hi') {
            return 'हर घर की ताज़गी';
        }

        return 'Har Ghar Ki Taazgi';
    }

    public static function logoAlt(): string
    {
        $alt = trim(AppSettings::get(self::KEY_LOGO_ALT, '') ?? '');

        return $alt !== '' ? $alt : self::siteName();
    }

    /** Footer blurb below the logo; null when explicitly cleared by admin. */
    public static function footerBrandDescription(): ?string
    {
        if (!AppSettings::has(self::KEY_FOOTER_BRAND_DESCRIPTION)) {
            return self::DEFAULT_FOOTER_BRAND_DESCRIPTION;
        }
        $trim = trim(AppSettings::get(self::KEY_FOOTER_BRAND_DESCRIPTION, '') ?? '');

        return $trim === '' ? null : $trim;
    }

    /** Value shown in admin form (default text until first save). */
    public static function footerBrandDescriptionForForm(): string
    {
        if (!AppSettings::has(self::KEY_FOOTER_BRAND_DESCRIPTION)) {
            return self::DEFAULT_FOOTER_BRAND_DESCRIPTION;
        }

        return trim(AppSettings::get(self::KEY_FOOTER_BRAND_DESCRIPTION, '') ?? '');
    }

    public static function defaultSeoTitle(): string
    {
        $custom = trim(AppSettings::get(self::KEY_DEFAULT_SEO_TITLE, '') ?? '');
        if ($custom !== '') {
            return $custom;
        }
        $seoHome = trim(AppSettings::get('seo.home.meta_title', '') ?? '');
        if ($seoHome !== '') {
            return $seoHome;
        }

        return self::siteName() . ' — ' . self::tagline();
    }

    public static function defaultMetaDescription(): string
    {
        $custom = trim(AppSettings::get(self::KEY_DEFAULT_META_DESC, '') ?? '');
        if ($custom !== '') {
            return $custom;
        }
        $seoHome = trim(AppSettings::get('seo.home.meta_description', '') ?? '');
        if ($seoHome !== '') {
            return $seoHome;
        }

        return 'Fresh vegetables, fruits and daily food products delivered in Indore.';
    }

    public static function logoUrl(): string
    {
        return self::publicAssetUrl(self::storedPath(self::KEY_LOGO_PATH), self::DEFAULT_LOGO);
    }

    public static function footerLogoUrl(): string
    {
        $footer = self::storedPath(self::KEY_FOOTER_LOGO_PATH);
        if ($footer !== '') {
            return self::publicAssetUrl($footer, self::DEFAULT_LOGO);
        }

        return self::logoUrl();
    }

    public static function faviconUrl(): string
    {
        $path = self::storedPath(self::KEY_FAVICON_PATH);
        if ($path === '') {
            return self::publicAssetUrl(self::DEFAULT_LOGO, self::DEFAULT_LOGO);
        }

        return self::publicAssetUrl($path, self::DEFAULT_LOGO);
    }

    public static function defaultOgImageUrl(): ?string
    {
        $path = self::storedPath(self::KEY_OG_IMAGE_PATH);
        if ($path !== '') {
            $abs = SeoUrl::validateOgImage(self::publicAssetUrl($path, ''));
            if ($abs !== null) {
                return $abs;
            }
        }
        $fallback = SeoUrl::validateOgImage(asset_url('assets/images/logo-placeholder.svg'));

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminFormState(): array
    {
        return [
            'site_name' => trim(AppSettings::get(self::KEY_SITE_NAME, '') ?? ''),
            'tagline' => trim(AppSettings::get(self::KEY_TAGLINE, '') ?? ''),
            'default_seo_title' => trim(AppSettings::get(self::KEY_DEFAULT_SEO_TITLE, '') ?? ''),
            'default_meta_description' => trim(AppSettings::get(self::KEY_DEFAULT_META_DESC, '') ?? ''),
            'logo_alt' => trim(AppSettings::get(self::KEY_LOGO_ALT, '') ?? ''),
            'footer_brand_description' => self::footerBrandDescriptionForForm(),
            'logo_path' => self::storedPath(self::KEY_LOGO_PATH),
            'footer_logo_path' => self::storedPath(self::KEY_FOOTER_LOGO_PATH),
            'favicon_path' => self::storedPath(self::KEY_FAVICON_PATH),
            'og_image_path' => self::storedPath(self::KEY_OG_IMAGE_PATH),
            'logo_preview' => self::logoUrl(),
            'footer_logo_preview' => self::footerLogoUrl(),
            'favicon_preview' => self::faviconUrl(),
            'og_preview' => self::defaultOgImageUrl() ?? asset_url('assets/images/logo-placeholder.svg'),
            'using_default_logo' => self::storedPath(self::KEY_LOGO_PATH) === '',
            'using_default_footer_logo' => self::storedPath(self::KEY_FOOTER_LOGO_PATH) === '',
            'using_default_favicon' => self::storedPath(self::KEY_FAVICON_PATH) === '',
            'using_default_og' => self::storedPath(self::KEY_OG_IMAGE_PATH) === '',
        ] + BrandingSocialLinks::adminFormValues();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveFromRequest(array $post, array $files): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database schema not ready.'];
        }

        $errors = [];
        $siteName = trim((string) ($post['site_name'] ?? ''));
        if ($siteName !== '' && mb_strlen($siteName) > 120) {
            $errors['site_name'] = 'Brand name must be 120 characters or fewer.';
        }
        $tagline = trim((string) ($post['tagline'] ?? ''));
        if ($tagline !== '' && mb_strlen($tagline) > 160) {
            $errors['tagline'] = 'Tagline must be 160 characters or fewer.';
        }
        $seoTitle = trim((string) ($post['default_seo_title'] ?? ''));
        if ($seoTitle !== '' && mb_strlen($seoTitle) > 70) {
            $errors['default_seo_title'] = 'Default SEO title must be 70 characters or fewer.';
        }
        $metaDesc = trim((string) ($post['default_meta_description'] ?? ''));
        if ($metaDesc !== '' && mb_strlen($metaDesc) > 320) {
            $errors['default_meta_description'] = 'Default meta description must be 320 characters or fewer.';
        }
        $logoAlt = trim((string) ($post['logo_alt'] ?? ''));
        if ($logoAlt !== '' && mb_strlen($logoAlt) > 191) {
            $errors['logo_alt'] = 'Logo alt text must be 191 characters or fewer.';
        }
        $footerDesc = trim((string) ($post['footer_brand_description'] ?? ''));
        if ($footerDesc !== '' && mb_strlen($footerDesc) > 500) {
            $errors['footer_brand_description'] = 'Footer brand description must be 500 characters or fewer.';
        }

        $social = BrandingSocialLinks::validateFromPost($post);
        if ($social['errors'] !== []) {
            $errors = array_merge($errors, $social['errors']);
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        $settingsBatch = [
            self::KEY_SITE_NAME => ['value' => $siteName !== '' ? $siteName : null],
            self::KEY_TAGLINE => ['value' => $tagline !== '' ? $tagline : null],
            self::KEY_DEFAULT_SEO_TITLE => ['value' => $seoTitle !== '' ? $seoTitle : null],
            self::KEY_DEFAULT_META_DESC => ['value' => $metaDesc !== '' ? $metaDesc : null],
            self::KEY_LOGO_ALT => ['value' => $logoAlt !== '' ? $logoAlt : null],
            self::KEY_FOOTER_BRAND_DESCRIPTION => ['value' => $footerDesc !== '' ? $footerDesc : null],
        ];
        foreach ($social['normalized'] as $socialKey => $socialUrl) {
            $settingsBatch[$socialKey] = ['value' => $socialUrl];
        }
        AppSettings::setMany($settingsBatch);

        $remove = isset($post['remove']) && is_array($post['remove']) ? $post['remove'] : [];
        foreach (['logo', 'footer_logo', 'favicon', 'og_image'] as $slot) {
            if (!empty($remove[$slot])) {
                self::clearUploadSlot($slot);
            }
        }

        $uploadMap = [
            'logo' => ['file' => $files['logo'] ?? null, 'slot' => 'logo'],
            'footer_logo' => ['file' => $files['footer_logo'] ?? null, 'slot' => 'footer_logo'],
            'favicon' => ['file' => $files['favicon'] ?? null, 'slot' => 'favicon'],
            'og_image' => ['file' => $files['og_image'] ?? null, 'slot' => 'og_image'],
        ];
        foreach ($uploadMap as $field => $spec) {
            $file = $spec['file'];
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $stored = self::storeUpload($file, $spec['slot']);
            if (!$stored['ok']) {
                $errors[$field] = (string) ($stored['message'] ?? 'Upload failed.');

                continue;
            }
            self::setPathForSlot($spec['slot'], (string) $stored['path']);
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Some uploads could not be saved.', 'errors' => $errors];
        }

        return ['ok' => true, 'message' => 'Website branding saved.'];
    }

    public static function previewUrlForPath(string $relativePath): string
    {
        if ($relativePath === '' || !self::isSafeRelativePath($relativePath)) {
            return asset_url(self::DEFAULT_LOGO);
        }

        return self::publicAssetUrl($relativePath, self::DEFAULT_LOGO);
    }

    private static function storedPath(string $key): string
    {
        $path = trim(AppSettings::get($key, '') ?? '');
        if ($path === '' || !self::isSafeRelativePath($path)) {
            return '';
        }
        if (!is_file(APNI_TOKRI_ROOT . '/' . $path)) {
            return '';
        }

        return $path;
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }
        if (!str_starts_with($path, 'uploads/' . self::UPLOAD_SUBDIR . '/')) {
            return false;
        }

        return preg_match('#^uploads/branding/[a-f0-9]{32}\.(jpg|jpeg|png|webp|gif|ico)$#', $path) === 1;
    }

    private static function publicAssetUrl(string $relativePath, string $defaultRelative): string
    {
        $path = $relativePath !== '' && self::isSafeRelativePath($relativePath) ? $relativePath : $defaultRelative;
        $url = asset_url($path);
        $file = APNI_TOKRI_ROOT . '/' . ltrim($path, '/');
        if (is_file($file) && str_starts_with($path, 'uploads/')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, path?: string, message?: string}
     */
    private static function storeUpload(array $file, string $slot): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed or no file provided.'];
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime)) {
            return ['ok' => false, 'message' => 'Could not detect file type.'];
        }

        $allowed = $slot === 'favicon' ? self::$faviconMimes : self::$logoMimes;
        if ($mime === 'image/svg+xml') {
            return ['ok' => false, 'message' => 'SVG uploads are not allowed. Use PNG, JPEG or WebP.'];
        }
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'message' => 'File type not allowed for this field.'];
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = $slot === 'favicon' ? self::MAX_FAVICON_BYTES : ($slot === 'og_image' ? self::MAX_OG_BYTES : self::MAX_LOGO_BYTES);
        if ($size <= 0 || $size > $maxBytes) {
            return ['ok' => false, 'message' => 'File exceeds the maximum allowed size.'];
        }

        if (!in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            $dimCheck = self::validateDimensions($file['tmp_name'], $slot);
            if ($dimCheck !== null) {
                return ['ok' => false, 'message' => $dimCheck];
            }
        }

        $ext = $allowed[$mime][0];
        $destDir = APNI_TOKRI_ROOT . '/uploads/' . self::UPLOAD_SUBDIR;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Upload directory is not writable.'];
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Could not save uploaded file.'];
        }
        @chmod($dest, 0644);

        return ['ok' => true, 'path' => 'uploads/' . self::UPLOAD_SUBDIR . '/' . $name];
    }

    private static function validateDimensions(string $tmpPath, string $slot): ?string
    {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return 'File is not a valid image.';
        }
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w < 1 || $h < 1) {
            return 'Invalid image dimensions.';
        }
        if ($slot === 'favicon' && ($w > self::MAX_FAVICON_PX || $h > self::MAX_FAVICON_PX)) {
            return 'Favicon must be at most ' . self::MAX_FAVICON_PX . '×' . self::MAX_FAVICON_PX . ' pixels.';
        }
        if ($slot === 'og_image' && ($w > self::MAX_OG_W || $h > self::MAX_OG_H)) {
            return 'Social image must be at most ' . self::MAX_OG_W . '×' . self::MAX_OG_H . ' pixels.';
        }
        if (in_array($slot, ['logo', 'footer_logo'], true) && ($w > self::MAX_LOGO_PX || $h > self::MAX_LOGO_PX)) {
            return 'Logo must be at most ' . self::MAX_LOGO_PX . '×' . self::MAX_LOGO_PX . ' pixels.';
        }

        return null;
    }

    private static function setPathForSlot(string $slot, string $path): void
    {
        $key = self::pathKeyForSlot($slot);
        if ($key === null) {
            return;
        }
        $previous = self::storedPath($key);
        AppSettings::set($key, $path);
        if ($previous !== '' && $previous !== $path) {
            self::deleteFileIfOwned($previous);
        }
    }

    public static function clearUploadSlot(string $slot): void
    {
        $key = self::pathKeyForSlot($slot);
        if ($key === null) {
            return;
        }
        $previous = self::storedPath($key);
        AppSettings::set($key, null);
        if ($previous !== '') {
            self::deleteFileIfOwned($previous);
        }
    }

    private static function pathKeyForSlot(string $slot): ?string
    {
        switch ($slot) {
            case 'logo':
                return self::KEY_LOGO_PATH;
            case 'footer_logo':
                return self::KEY_FOOTER_LOGO_PATH;
            case 'favicon':
                return self::KEY_FAVICON_PATH;
            case 'og_image':
                return self::KEY_OG_IMAGE_PATH;
            default:
                return null;
        }
    }

    private static function deleteFileIfOwned(string $relativePath): void
    {
        if (!self::isSafeRelativePath($relativePath)) {
            return;
        }
        $full = APNI_TOKRI_ROOT . '/' . $relativePath;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
