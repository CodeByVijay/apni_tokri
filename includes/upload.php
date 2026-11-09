<?php

declare(strict_types=1);

final class Upload
{
    /** @var array<string, list<string>> */
    private static $allowed = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    /**
     * @param array<string, mixed> $file Single entry from $_FILES
     * @return array{ok: bool, path?: string, message?: string}
     */
    public static function storeImage(array $file, string $subdir): array
    {
        return self::storeImageWithAllowed($file, $subdir, self::$allowed);
    }

    /**
     * Product gallery images only (JPEG, PNG, WebP — no GIF).
     *
     * @param array<string, mixed> $file
     * @return array{ok: bool, path?: string, message?: string}
     */
    public static function storeProductImage(array $file, string $subdir): array
    {
        $allowed = self::productRasterAllowed();

        return self::storeImageWithAllowed($file, $subdir, $allowed, true);
    }

    /**
     * Central media library raster upload (JPEG/PNG/WebP).
     *
     * @param array<string, mixed> $file
     * @return array{
     *   ok: bool,
     *   path?: string,
     *   message?: string,
     *   mime?: string,
     *   size?: int,
     *   width?: int,
     *   height?: int,
     *   sha256?: string,
     *   storage_filename?: string
     * }
     */
    /** Ensure uploads/ root hardening exists (Apache .htaccess is not sufficient on Nginx). */
    public static function ensureUploadsRootSecured(): void
    {
        $root = APNI_TOKRI_ROOT . '/uploads';
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        self::writeUploadsSecurityFiles($root);
    }

    public static function storeMediaRaster(array $file, ?string $subdir = null): array
    {
        if ($subdir === null || $subdir === '') {
            $subdir = 'media/' . date('Y') . '/' . date('m');
        }
        $stored = self::storeImageWithAllowed($file, $subdir, self::productRasterAllowed(), true);
        if (!$stored['ok'] || empty($stored['path'])) {
            return $stored;
        }
        $rel = (string) $stored['path'];
        $full = APNI_TOKRI_ROOT . '/' . ltrim($rel, '/');
        if (!is_file($full)) {
            return ['ok' => false, 'message' => 'Stored file missing.'];
        }
        $size = (int) filesize($full);
        $dims = @getimagesize($full);
        $width = is_array($dims) ? (int) ($dims[0] ?? 0) : 0;
        $height = is_array($dims) ? (int) ($dims[1] ?? 0) : 0;
        if ($width < 1 || $height < 1) {
            @unlink($full);

            return ['ok' => false, 'message' => 'Invalid image dimensions.'];
        }
        $maxW = (int) (App::config()['upload']['max_image_width'] ?? 8000);
        $maxH = (int) (App::config()['upload']['max_image_height'] ?? 8000);
        if ($width > $maxW || $height > $maxH) {
            @unlink($full);

            return ['ok' => false, 'message' => 'Image exceeds maximum dimensions (' . $maxW . '×' . $maxH . ').'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($full);
        $sha = hash_file('sha256', $full);
        $base = basename($full);

        return [
            'ok' => true,
            'path' => $rel,
            'mime' => is_string($mime) ? $mime : '',
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => is_string($sha) ? $sha : '',
            'storage_filename' => $base,
        ];
    }

    /** @return array<string, list<string>> */
    public static function productRasterAllowed(): array
    {
        return [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, list<string>> $allowed
     * @return array{ok: bool, path?: string, message?: string}
     */
    private static function storeImageWithAllowed(array $file, string $subdir, array $allowed, bool $decodeVerify = false): array
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed or no file provided.'];
        }
        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }
        $tmp = $file['tmp_name'];
        $cliFixture = PHP_SAPI === 'cli' && is_file($tmp);
        if (!is_uploaded_file($tmp) && !$cliFixture) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'File must be under 5 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime) || !isset($allowed[$mime])) {
            return ['ok' => false, 'message' => 'File type not allowed.'];
        }

        $ext = $allowed[$mime][0];
        if (preg_match('/\.\.|\\\\|^\/|^\.\//', $subdir) === 1) {
            return ['ok' => false, 'message' => 'Invalid upload destination.'];
        }
        $subdir = trim(str_replace('\\', '/', $subdir), '/');
        if ($subdir === '' || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $subdir)) {
            return ['ok' => false, 'message' => 'Invalid upload destination.'];
        }
        self::ensureUploadsRootSecured();
        $destDir = APNI_TOKRI_ROOT . '/uploads/' . $subdir;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Upload directory not writable.'];
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $destDir . '/' . $name;
        $saved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$saved) {
            return ['ok' => false, 'message' => 'Could not save file.'];
        }
        @chmod($dest, 0644);
        if ($decodeVerify && !self::verifyRasterDecoded($dest, $mime)) {
            @unlink($dest);

            return ['ok' => false, 'message' => 'File is not a valid image.'];
        }

        return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name];
    }

    /** Apache-only helper; configure Nginx to deny script execution under /uploads/ separately. */
    private static function writeUploadsSecurityFiles(string $uploadsRoot): void
    {
        if (!is_dir($uploadsRoot)) {
            return;
        }
        $htaccess = $uploadsRoot . '/.htaccess';
        $body = "<IfModule mod_authz_core.c>\n  <FilesMatch \"\\.(?i:php|phtml|php[0-9]*)$\">\n    Require all denied\n  </FilesMatch>\n</IfModule>\n";
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, $body);
        }
        $index = $uploadsRoot . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * Support ticket attachments (images/PDF).
     *
     * @param array<string, mixed> $file
     * @return array{ok: bool, path?: string, message?: string, mime?: string}
     */
    public static function storeSupportAttachment(array $file): array
    {
        $allowed = self::$allowed;
        $allowed['application/pdf'] = ['pdf'];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed or no file provided.'];
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'File must be under 5 MB.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!is_string($mime) || !isset($allowed[$mime])) {
            return ['ok' => false, 'message' => 'File type not allowed.'];
        }
        $ext = $allowed[$mime][0];
        $destDir = APNI_TOKRI_ROOT . '/uploads/support';
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Upload directory not writable.'];
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Could not save file.'];
        }

        return ['ok' => true, 'path' => 'uploads/support/' . $name, 'mime' => $mime];
    }

    private static function verifyRasterDecoded(string $path, string $mime): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        try {
            if ($mime === 'image/jpeg') {
                if (function_exists('imagecreatefromjpeg')) {
                    return @imagecreatefromjpeg($path) !== false;
                }

                return @getimagesize($path) !== false;
            }
            if ($mime === 'image/png') {
                if (function_exists('imagecreatefrompng')) {
                    return @imagecreatefrompng($path) !== false;
                }

                return @getimagesize($path) !== false;
            }
            if ($mime === 'image/webp') {
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($path) !== false;
                }

                return @getimagesize($path) !== false;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
}
