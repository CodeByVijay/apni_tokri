<?php

declare(strict_types=1);

/** Admin product gallery uploads (extends ProductRepository image rows). */
final class ProductImageAdminService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function normalizedFileList(?array $files): array
    {
        if ($files === null || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $out = [];
        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Upload one or many files; preserves successful rows when others fail.
     *
     * @param list<array<string, mixed>> $files
     * @return array{
     *   results: list<array{filename: string, ok: bool, message?: string, image_id?: int}>,
     *   images: list<array<string, mixed>>
     * }
     */
    public static function uploadBatch(int $productId, array $files): array
    {
        if ($productId <= 0 || ProductRepository::find($productId) === null) {
            return ['results' => [['filename' => '', 'ok' => false, 'message' => 'Product not found.']], 'images' => []];
        }
        $files = array_values(array_filter($files, static function (array $f): bool {
            return (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        }));
        if ($files === []) {
            return ['results' => [['filename' => '', 'ok' => false, 'message' => 'No files selected.']], 'images' => []];
        }

        $max = ProductRepository::MAX_IMAGES_PER_PRODUCT;
        $existing = ProductRepository::imageCount($productId);
        if ($existing >= $max) {
            return [
                'results' => [['filename' => '', 'ok' => false, 'message' => 'This product already has the maximum of ' . $max . ' images.']],
                'images' => ProductRepository::images($productId),
            ];
        }
        if ($existing + count($files) > $max) {
            return [
                'results' => [['filename' => '', 'ok' => false, 'message' => 'Too many files. Maximum ' . $max . ' images per product (' . $existing . ' already uploaded).']],
                'images' => ProductRepository::images($productId),
            ];
        }

        $subdir = 'products/' . $productId;
        $assignPrimary = !ProductRepository::hasPrimaryImage($productId);
        $results = [];

        foreach ($files as $file) {
            $label = self::safeFilename((string) ($file['name'] ?? 'image'));
            if (ProductRepository::imageCount($productId) >= $max) {
                $results[] = ['filename' => $label, 'ok' => false, 'message' => 'Image limit reached.'];
                continue;
            }
            $upload = Upload::storeProductImage($file, $subdir);
            if (!$upload['ok'] || empty($upload['path'])) {
                $results[] = ['filename' => $label, 'ok' => false, 'message' => (string) ($upload['message'] ?? 'Upload failed.')];
                continue;
            }
            $path = (string) $upload['path'];
            try {
                $imageId = ProductRepository::addImage($productId, $path, $assignPrimary);
                $assignPrimary = false;
                $results[] = ['filename' => $label, 'ok' => true, 'image_id' => $imageId];
            } catch (Throwable $e) {
                error_log('ProductImageAdminService::uploadBatch: ' . $e->getMessage());
                $full = APNI_TOKRI_ROOT . '/' . ltrim($path, '/');
                if (is_file($full)) {
                    @unlink($full);
                }
                $results[] = ['filename' => $label, 'ok' => false, 'message' => 'Could not save image record.'];
            }
        }

        CatalogueGateway::reset();

        return ['results' => $results, 'images' => ProductRepository::images($productId)];
    }

    /** @param list<int> $orderedImageIds */
    public static function reorder(int $productId, array $orderedImageIds): bool
    {
        if ($productId <= 0 || $orderedImageIds === []) {
            return false;
        }
        $existing = ProductRepository::images($productId);
        $valid = [];
        foreach ($existing as $row) {
            $valid[(int) $row['id']] = true;
        }
        $filtered = [];
        foreach ($orderedImageIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($valid[$id]) && !in_array($id, $filtered, true)) {
                $filtered[] = $id;
            }
        }
        if (count($filtered) !== count($valid)) {
            return false;
        }
        ProductRepository::reorderImages($productId, $filtered);
        CatalogueGateway::reset();

        return true;
    }

    public static function setPrimary(int $productId, int $imageId): bool
    {
        if ($productId <= 0 || $imageId <= 0) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM product_images WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$imageId, $productId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }
        ProductRepository::setPrimaryImage($productId, $imageId);
        CatalogueGateway::reset();

        return true;
    }

    private static function safeFilename(string $name): string
    {
        $base = basename(str_replace(['\\', '/'], '/', $name));

        return $base !== '' ? $base : 'image';
    }
}
