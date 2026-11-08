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
        $allowed = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];

        return self::storeImageWithAllowed($file, $subdir, $allowed);
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, list<string>> $allowed
     * @return array{ok: bool, path?: string, message?: string}
     */
    private static function storeImageWithAllowed(array $file, string $subdir, array $allowed): array
    {
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
        $subdir = trim($subdir, '/');
        $destDir = APNI_TOKRI_ROOT . '/uploads/' . $subdir;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Upload directory not writable.'];
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Could not save file.'];
        }

        return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name];
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
}
