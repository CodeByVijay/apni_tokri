<?php

declare(strict_types=1);

/** Central media assets + product attachment helpers. */
final class MediaLibrary
{
    public const MSG_SOFT_DELETED_REUPLOAD =
        'This file matches media that was soft-deleted. Purge it permanently before re-uploading the same content.';

    public const MSG_RESTORE_SHA_CONFLICT =
        'Cannot restore: another active image already has the same file content (SHA-256). Permanently delete or deduplicate before restoring.';

    public static function isEnabled(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = false;
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute(['media_assets']);
            $cache = (bool) $stmt->fetchColumn();
            if ($cache) {
                Upload::ensureUploadsRootSecured();
            }
        } catch (Throwable $e) {
            $cache = false;
        }

        return $cache;
    }

    /**
     * @param array<string, mixed> $file $_FILES single entry
     * @return array{ok: bool, media_id?: int, message?: string, duplicate?: bool}
     */
    public static function ingestUpload(array $file, ?int $adminUserId = null, string $altText = ''): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'message' => 'Media library is not available. Run migration 1.37.0.'];
        }
        $original = self::safeOriginalName((string) ($file['name'] ?? 'image'));
        $preSha = self::hashUploadTemp($file);
        if ($preSha !== '') {
            $active = self::findActiveBySha256($preSha);
            if ($active !== null) {
                return ['ok' => true, 'media_id' => (int) $active['id'], 'duplicate' => true];
            }
            // No disk write yet — safe early return.
            if (self::findSoftDeletedBySha256($preSha) !== null) {
                return ['ok' => false, 'message' => self::MSG_SOFT_DELETED_REUPLOAD];
            }
        }

        $stored = Upload::storeMediaRaster($file);
        if (!$stored['ok']) {
            return ['ok' => false, 'message' => (string) ($stored['message'] ?? 'Upload failed.')];
        }
        $sha = (string) ($stored['sha256'] ?? '');
        $path = (string) $stored['path'];
        $orphanFull = APNI_TOKRI_ROOT . '/' . ltrim($path, '/');

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            if ($sha !== '') {
                $stmt = $pdo->prepare(
                    'SELECT id, deleted_at FROM media_assets WHERE file_sha256 = ? LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$sha]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    if (empty($row['deleted_at'])) {
                        $pdo->commit();
                        self::unlinkOrphanNotWinnerPath($orphanFull, $path, $row);

                        return ['ok' => true, 'media_id' => (int) $row['id'], 'duplicate' => true];
                    }
                    $pdo->rollBack();
                    self::unlinkOrphanFile($orphanFull);

                    return ['ok' => false, 'message' => self::MSG_SOFT_DELETED_REUPLOAD];
                }
            }
            if (!is_file($orphanFull)) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Stored file missing.'];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO media_assets (
                    original_filename, storage_filename, file_path, mime_type, file_size,
                    width, height, file_sha256, alt_text, uploaded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $original,
                (string) ($stored['storage_filename'] ?? basename($path)),
                $path,
                (string) ($stored['mime'] ?? ''),
                (int) ($stored['size'] ?? 0),
                (int) ($stored['width'] ?? 0),
                (int) ($stored['height'] ?? 0),
                $sha !== '' ? $sha : null,
                mb_substr(trim($altText), 0, 255),
                $adminUserId !== null && $adminUserId > 0 ? $adminUserId : null,
            ]);
            $mediaId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return ['ok' => true, 'media_id' => $mediaId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($sha !== '' && self::isIngestDuplicateConflict($e)) {
                $existing = self::waitForActiveBySha256AfterDuplicateKey($sha, $e);
                if ($existing !== null) {
                    self::unlinkOrphanNotWinnerPath($orphanFull, $path, $existing);

                    return ['ok' => true, 'media_id' => (int) $existing['id'], 'duplicate' => true];
                }
            }
            self::unlinkOrphanFile($orphanFull);
            error_log('MediaLibrary::ingestUpload: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save media record.'];
        }
    }

    /**
     * After duplicate-key (errno 1062), the winning INSERT may still be committing — re-read outside stale txn.
     *
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed>|null $existing
     */
    private static function waitForActiveBySha256AfterDuplicateKey(string $sha256, ?Throwable $trigger = null): ?array
    {
        $maxAttempts = 40;
        $delayUs = 4000;
        $maxDelayUs = 120000;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delayUs);
                $delayUs = min((int) ($delayUs * 2), $maxDelayUs);
            }
            try {
                $existing = self::findActiveBySha256($sha256);
                if ($existing !== null) {
                    return $existing;
                }
            } catch (Throwable $e) {
                error_log('MediaLibrary::waitForActiveBySha256AfterDuplicateKey: ' . $e->getMessage());
            }
        }
        if ($trigger !== null) {
            error_log(
                'MediaLibrary::waitForActiveBySha256AfterDuplicateKey: no row after retries for sha; '
                . $trigger->getMessage()
            );
        }

        return null;
    }

    private static function isIngestDuplicateConflict(Throwable $e): bool
    {
        if (self::isDuplicateKey($e)) {
            return true;
        }
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? [];
        $code = (int) ($info[1] ?? 0);

        return $code === 1205 || $code === 1213;
    }

    /** @param array<string, mixed> $file */
    private static function hashUploadTemp(array $file): string
    {
        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            return '';
        }
        $tmp = $file['tmp_name'];
        if (!is_uploaded_file($tmp) && !(PHP_SAPI === 'cli' && is_file($tmp))) {
            return '';
        }
        $hash = @hash_file('sha256', $tmp);

        return is_string($hash) ? $hash : '';
    }

    private static function isDuplicateKey(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? [];
        if (isset($info[0]) && $info[0] === '23000' && isset($info[1]) && (int) $info[1] === 1062) {
            return true;
        }

        return isset($info[1]) && (int) $info[1] === 1062;
    }

    /** @return array<string, mixed>|null */
    public static function findActive(int $mediaId): ?array
    {
        if ($mediaId <= 0 || !self::isEnabled()) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM media_assets WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function findActiveBySha256(string $sha256): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM media_assets WHERE file_sha256 = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function findSoftDeletedBySha256(string $sha256): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM media_assets WHERE file_sha256 = ? AND deleted_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public static function productUsageCount(int $mediaId): int
    {
        if ($mediaId <= 0) {
            return 0;
        }
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM product_images WHERE media_id = ?');
        $stmt->execute([$mediaId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function productsUsingMedia(int $mediaId): array
    {
        if ($mediaId <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT p.id, p.name, p.slug
             FROM product_images pi
             INNER JOIN products p ON p.id = pi.product_id
             WHERE pi.media_id = ?
             ORDER BY p.name
             LIMIT 50'
        );
        $stmt->execute([$mediaId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }

        return $out;
    }

    /**
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function search(
        string $q,
        string $usageFilter,
        string $sort,
        int $page,
        int $perPage
    ): array {
        if (!self::isEnabled()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        }
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $q = trim($q);
        $params = [];
        $where = ['m.deleted_at IS NULL'];
        if ($q !== '') {
            $where[] = 'm.original_filename LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        if ($usageFilter === 'used') {
            $where[] = '(SELECT COUNT(*) FROM product_images pi WHERE pi.media_id = m.id) > 0';
        } elseif ($usageFilter === 'unused') {
            $where[] = '(SELECT COUNT(*) FROM product_images pi WHERE pi.media_id = m.id) = 0';
        }
        $order = $sort === 'oldest' ? 'm.created_at ASC' : 'm.created_at DESC';
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM media_assets m WHERE {$whereSql}";
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT m.*,
            (SELECT COUNT(*) FROM product_images pi WHERE pi.media_id = m.id) AS usage_count
            FROM media_assets m
            WHERE {$whereSql}
            ORDER BY {$order}
            LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Trash listing (soft-deleted assets only).
     *
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchTrash(string $q, string $sort, int $page, int $perPage): array
    {
        if (!self::isEnabled()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        }
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $q = trim($q);
        $params = [];
        $where = ['m.deleted_at IS NOT NULL'];
        if ($q !== '') {
            $where[] = 'm.original_filename LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        $order = $sort === 'oldest' ? 'm.deleted_at ASC' : 'm.deleted_at DESC';
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM media_assets m WHERE {$whereSql}";
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT m.* FROM media_assets m WHERE {$whereSql} ORDER BY {$order} LIMIT "
            . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function findDeleted(int $mediaId): ?array
    {
        if ($mediaId <= 0 || !self::isEnabled()) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM media_assets WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function restore(int $mediaId): array
    {
        if ($mediaId <= 0) {
            return ['ok' => false, 'message' => 'Invalid media.'];
        }
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM media_assets WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$mediaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || empty($row['deleted_at'])) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Media is not in trash or was already restored.'];
            }
            $sha = (string) ($row['file_sha256'] ?? '');
            if ($sha !== '') {
                $dup = $pdo->prepare(
                    'SELECT id FROM media_assets WHERE file_sha256 = ? AND deleted_at IS NULL AND id <> ? LIMIT 1'
                );
                $dup->execute([$sha, $mediaId]);
                $conflict = $dup->fetch(PDO::FETCH_ASSOC);
                if (is_array($conflict)) {
                    $pdo->rollBack();

                    return ['ok' => false, 'message' => self::MSG_RESTORE_SHA_CONFLICT];
                }
            }
            $upd = $pdo->prepare('UPDATE media_assets SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
            $upd->execute([$mediaId]);
            if ($upd->rowCount() < 1) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Could not restore media.'];
            }
            $pdo->commit();

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('MediaLibrary::restore: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not restore media.'];
        }
    }

    /**
     * @param list<int> $mediaIds
     * @return array{attached: list<int>, skipped: list<int>, errors: list<string>}
     */
    public static function attachToProduct(int $productId, array $mediaIds, bool $assignPrimaryIfEmpty): array
    {
        $attached = [];
        $skipped = [];
        $errors = [];
        if ($productId <= 0 || ProductRepository::find($productId) === null) {
            return ['attached' => [], 'skipped' => [], 'errors' => ['Product not found.']];
        }
        $max = ProductRepository::MAX_IMAGES_PER_PRODUCT;
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn (int $v): bool => $v > 0)));
        if ($mediaIds === []) {
            return ['attached' => [], 'skipped' => [], 'errors' => ['No media selected.']];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $existingMedia = [];
            foreach (ProductRepository::images($productId) as $img) {
                if (!empty($img['media_id'])) {
                    $existingMedia[(int) $img['media_id']] = true;
                }
            }
            $assignPrimary = $assignPrimaryIfEmpty && !ProductRepository::hasPrimaryImage($productId);

            foreach ($mediaIds as $mid) {
                if (ProductRepository::imageCount($productId) >= $max) {
                    $errors[] = 'Image limit reached.';
                    break;
                }
                if (isset($existingMedia[$mid])) {
                    $skipped[] = $mid;
                    continue;
                }
                $lock = $pdo->prepare('SELECT * FROM media_assets WHERE id = ? LIMIT 1 FOR UPDATE');
                $lock->execute([$mid]);
                $media = $lock->fetch(PDO::FETCH_ASSOC);
                if ($media === false || !empty($media['deleted_at'])) {
                    $errors[] = 'Media #' . $mid . ' not found or unavailable.';
                    continue;
                }
                $path = (string) $media['file_path'];
                if ($path === '' || strpos($path, 'uploads/') !== 0) {
                    $errors[] = 'Media #' . $mid . ' has invalid storage path.';
                    continue;
                }
                $full = APNI_TOKRI_ROOT . '/' . ltrim($path, '/');
                if (!is_file($full) || !is_readable($full)) {
                    $errors[] = 'Media #' . $mid . ' file is missing on disk.';
                    continue;
                }
                $imageId = ProductRepository::addImage(
                    $productId,
                    $path,
                    $assignPrimary,
                    $mid,
                    (string) ($media['alt_text'] ?? '')
                );
                $assignPrimary = false;
                $existingMedia[$mid] = true;
                $attached[] = $imageId;
            }
            $pdo->commit();
            CatalogueGateway::reset();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('MediaLibrary::attachToProduct: ' . $e->getMessage());

            return ['attached' => [], 'skipped' => $skipped, 'errors' => ['Could not attach media.']];
        }

        return ['attached' => $attached, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @return array{ok: bool, message?: string, products?: list<array{id:int,name:string,slug:string}>}
     */
    public static function softDelete(int $mediaId): array
    {
        if ($mediaId <= 0) {
            return ['ok' => false, 'message' => 'Invalid media.'];
        }
        $usage = self::productUsageCount($mediaId);
        if ($usage > 0) {
            return [
                'ok' => false,
                'message' => 'This image is used by ' . $usage . ' product attachment(s). Remove associations first.',
                'products' => self::productsUsingMedia($mediaId),
            ];
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE media_assets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$mediaId]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'message' => 'Media not found or already deleted.'];
        }

        return ['ok' => true];
    }

    /**
     * Physical delete — only when soft-deleted and unused.
     *
     * @return array{ok: bool, message?: string, file_deleted?: bool, file_delete_warning?: string}
     */
    public static function purgeDeleted(int $mediaId): array
    {
        if ($mediaId <= 0) {
            return ['ok' => false, 'message' => 'Invalid media.'];
        }
        $pdo = Database::pdo();
        $path = '';
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT file_path, deleted_at FROM media_assets WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$mediaId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || empty($row['deleted_at'])) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Soft-delete this asset before permanent removal.'];
            }
            $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE media_id = ?');
            $usageStmt->execute([$mediaId]);
            if ((int) $usageStmt->fetchColumn() > 0) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Cannot purge media that is still linked to products.'];
            }
            $path = (string) $row['file_path'];
            if ($path !== '') {
                $legacyStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE file_path = ?');
                $legacyStmt->execute([$path]);
                if ((int) $legacyStmt->fetchColumn() > 0) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'message' => 'Cannot purge: product image rows still reference this file path.',
                    ];
                }
                $otherMedia = $pdo->prepare(
                    'SELECT COUNT(*) FROM media_assets WHERE file_path = ? AND id <> ?'
                );
                $otherMedia->execute([$path, $mediaId]);
                if ((int) $otherMedia->fetchColumn() > 0) {
                    $pdo->rollBack();

                    return [
                        'ok' => false,
                        'message' => 'Cannot purge: another media record uses this file path.',
                    ];
                }
            }
            $del = $pdo->prepare('DELETE FROM media_assets WHERE id = ? AND deleted_at IS NOT NULL');
            $del->execute([$mediaId]);
            if ($del->rowCount() < 1) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Could not purge media record.'];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('MediaLibrary::purgeDeleted: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not purge media.'];
        }

        $fileDeleted = false;
        $fileWarning = null;
        if ($path !== '' && strpos($path, 'uploads/') === 0) {
            $full = APNI_TOKRI_ROOT . '/' . ltrim($path, '/');
            if (is_file($full)) {
                if (@unlink($full)) {
                    $fileDeleted = true;
                } else {
                    $fileWarning = 'Database record removed but the file could not be deleted from disk.';
                    error_log('MediaLibrary::purgeDeleted: unlink failed for ' . $path);
                }
            }
        }

        if ($fileWarning !== null) {
            return [
                'ok' => true,
                'message' => 'Media purged from database with a filesystem warning.',
                'file_deleted' => $fileDeleted,
                'file_delete_warning' => $fileWarning,
            ];
        }

        return ['ok' => true, 'file_deleted' => $fileDeleted];
    }

    /** Remove duplicate upload orphan only when it is not the canonical stored path. */
    private static function unlinkOrphanNotWinnerPath(string $orphanFull, string $orphanRel, array $winnerRow): void
    {
        $winnerPath = ltrim((string) ($winnerRow['file_path'] ?? ''), '/');
        $orphanRel = ltrim($orphanRel, '/');
        if ($winnerPath !== '' && $winnerPath === $orphanRel) {
            return;
        }
        self::unlinkOrphanFile($orphanFull);
    }

    private static function unlinkOrphanFile(string $orphanFull): void
    {
        if (is_file($orphanFull)) {
            @unlink($orphanFull);
        }
    }

    public static function updateAlt(int $mediaId, string $alt): bool
    {
        if ($mediaId <= 0) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE media_assets SET alt_text = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([mb_substr(trim($alt), 0, 255), $mediaId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed> */
    public static function rowForClient(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            'id' => $id,
            'url' => asset_url((string) ($row['file_path'] ?? '')),
            'original_filename' => (string) ($row['original_filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'file_size' => (int) ($row['file_size'] ?? 0),
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'deleted_at' => (string) ($row['deleted_at'] ?? ''),
            'usage_count' => (int) ($row['usage_count'] ?? self::productUsageCount($id)),
        ];
    }

    /** @return array<string, mixed> */
    public static function rowForClientTrash(array $row): array
    {
        $client = self::rowForClient($row);
        $client['usage_count'] = 0;

        return $client;
    }

    private static function safeOriginalName(string $name): string
    {
        $base = basename(str_replace(['\\', '/'], '/', $name));
        $base = preg_replace('/[^\w.\-()+@ ]+/u', '_', $base) ?? 'image';

        return mb_substr($base !== '' ? $base : 'image', 0, 255);
    }
}
