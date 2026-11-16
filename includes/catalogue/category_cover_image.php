<?php

declare(strict_types=1);

/** Admin category cover uploads under assets/images/categories/ (managed paths only). */
final class CategoryCoverImage
{
    public const REL_PREFIX = 'assets/images/categories/';

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
    public static function storeUpload(array $file): array
    {
        if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'No file provided.'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed.'];
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
        if (!is_string($mime) || !isset(self::$allowed[$mime])) {
            return ['ok' => false, 'message' => 'File type not allowed (JPEG, PNG, WebP or GIF).'];
        }

        $ext = self::$allowed[$mime][0];
        $destDir = APNI_TOKRI_ROOT . '/' . self::REL_PREFIX;
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            return ['ok' => false, 'message' => 'Upload directory not writable.'];
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $destDir . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Could not save file.'];
        }

        return ['ok' => true, 'path' => self::REL_PREFIX . $name];
    }

    public static function isManagedPath(?string $relativePath): bool
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return false;
        }
        $path = ltrim($relativePath, '/');

        return strpos($path, self::REL_PREFIX) === 0;
    }

    public static function deleteManagedFile(?string $relativePath): void
    {
        if (!self::isManagedPath($relativePath)) {
            return;
        }
        $full = APNI_TOKRI_ROOT . '/' . ltrim((string) $relativePath, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * Apply remove / replace from admin category form (after category row exists).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{ok: bool, message?: string}
     */
    public static function applyAdminPost(int $categoryId, array $post, array $files): array
    {
        $row = CategoryRepository::find($categoryId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        $current = isset($row['image_path']) ? trim((string) $row['image_path']) : '';

        if (!empty($post['remove_cover_image'])) {
            self::deleteManagedFile($current !== '' ? $current : null);
            self::persistPath($categoryId, null);

            return ['ok' => true];
        }

        $file = isset($files['cover_image']) && is_array($files['cover_image']) ? $files['cover_image'] : null;
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true];
        }

        $stored = self::storeUpload($file);
        if (empty($stored['ok'])) {
            return ['ok' => false, 'message' => (string) ($stored['message'] ?? 'Cover image upload failed.')];
        }
        $newPath = (string) ($stored['path'] ?? '');
        if ($newPath !== '' && $current !== '' && $current !== $newPath) {
            self::deleteManagedFile($current);
        }
        self::persistPath($categoryId, $newPath);
        CatalogueGateway::reset();

        return ['ok' => true];
    }

    private static function persistPath(int $categoryId, ?string $path): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE categories SET image_path = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$path !== null && $path !== '' ? $path : null, $categoryId]);
    }
}
