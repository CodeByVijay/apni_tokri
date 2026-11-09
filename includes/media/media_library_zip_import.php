<?php

declare(strict_types=1);

/** Bulk image ingest from a ZIP archive into Media Library (unused assets). */
final class MediaLibraryZipImport
{
    public const MAX_IMAGE_ENTRIES = 500;
    public const MAX_ZIP_BYTES = 262144000; // 250 MB
    public const MAX_EXTRACTED_BYTES = 524288000; // 500 MB
    public const MAX_IMAGE_BYTES = 5242880; // 5 MB

    /**
     * @param array<string, mixed> $zipFile $_FILES entry (e.g. archive)
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   summary?: array{total_found: int, uploaded: int, duplicates: int, invalid: int, failed: int},
     *   errors?: list<array{entry: string, reason: string}>,
     *   error_report_csv_base64?: string,
     *   error_report_filename?: string
     * }
     */
    public static function processZipUpload(array $zipFile, ?int $adminUserId = null): array
    {
        if (!MediaLibrary::isEnabled()) {
            return ['ok' => false, 'message' => 'Media library is not available.'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZIP extension unavailable.'];
        }

        $err = (int) ($zipFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage($err)];
        }
        $tmp = (string) ($zipFile['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'message' => 'No ZIP file received.'];
        }
        $trustedCli = !empty($zipFile['trusted_internal']) && PHP_SAPI === 'cli';
        if (!is_uploaded_file($tmp) && !$trustedCli) {
            return ['ok' => false, 'message' => 'Invalid ZIP upload.'];
        }

        $size = (int) ($zipFile['size'] ?? filesize($tmp));
        $maxZip = self::maxZipUploadBytes();
        if ($size <= 0 || $size > $maxZip) {
            return ['ok' => false, 'message' => 'ZIP must be between 1 byte and ' . self::formatBytes($maxZip) . '.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $zipMime = $finfo->file($tmp);
        if (!is_string($zipMime) || !self::isZipMime($zipMime)) {
            return ['ok' => false, 'message' => 'File must be a ZIP archive.'];
        }

        $workRoot = self::createWorkRoot();
        if ($workRoot === null) {
            return ['ok' => false, 'message' => 'Could not create temporary workspace.'];
        }

        $summary = [
            'total_found' => 0,
            'uploaded' => 0,
            'duplicates' => 0,
            'invalid' => 0,
            'failed' => 0,
        ];
        /** @var list<array{entry: string, reason: string}> $errors */
        $errors = [];

        try {
            if (@ini_get('max_execution_time') !== '0') {
                @set_time_limit(max(120, (int) ini_get('max_execution_time')));
            }

            $localZip = $workRoot . '/upload.zip';
            if (!is_uploaded_file($tmp)) {
                if (!@copy($tmp, $localZip)) {
                    return ['ok' => false, 'message' => 'Could not stage ZIP file.'];
                }
            } else {
                if (!@move_uploaded_file($tmp, $localZip)) {
                    if (!@copy($tmp, $localZip)) {
                        return ['ok' => false, 'message' => 'Could not stage ZIP file.'];
                    }
                }
            }

            $zip = new ZipArchive();
            if ($zip->open($localZip) !== true) {
                return ['ok' => false, 'message' => 'Could not open ZIP archive.'];
            }

            $fileEntries = self::enumerateFileEntries($zip);
            $summary['total_found'] = count($fileEntries);
            if ($summary['total_found'] > self::MAX_IMAGE_ENTRIES) {
                $zip->close();

                return [
                    'ok' => false,
                    'message' => 'ZIP contains more than ' . self::MAX_IMAGE_ENTRIES . ' file entries.',
                ];
            }

            $uncompTotal = 0;
            foreach ($fileEntries as $entry) {
                $uncompTotal += (int) ($entry['size'] ?? 0);
            }
            if ($uncompTotal > self::MAX_EXTRACTED_BYTES) {
                $zip->close();

                return [
                    'ok' => false,
                    'message' => 'ZIP uncompressed total exceeds ' . self::formatBytes(self::MAX_EXTRACTED_BYTES) . '.',
                ];
            }

            $extractedBytes = 0;
            $extractDir = $workRoot . '/parts';
            if (!@mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
                $zip->close();

                return ['ok' => false, 'message' => 'Could not create extraction directory.'];
            }

            foreach ($fileEntries as $entry) {
                $entryName = (string) $entry['name'];
                $displayName = self::displayNameFromEntry($entryName);

                if (!self::isSafeZipEntryPath($entryName)) {
                    $summary['invalid']++;
                    $errors[] = ['entry' => $displayName, 'reason' => 'Unsafe archive path.'];
                    continue;
                }
                if (self::isNestedZipEntry($entryName)) {
                    $summary['invalid']++;
                    $errors[] = ['entry' => $displayName, 'reason' => 'Nested ZIP files are not allowed.'];
                    continue;
                }

                $safePart = 'part-' . bin2hex(random_bytes(8));
                $destPath = $extractDir . DIRECTORY_SEPARATOR . $safePart;

                if (!self::extractEntryToFile($zip, $entryName, $destPath)) {
                    $summary['failed']++;
                    $errors[] = ['entry' => $displayName, 'reason' => 'Could not extract file.'];
                    continue;
                }

                if (is_link($destPath)) {
                    @unlink($destPath);
                    $summary['invalid']++;
                    $errors[] = ['entry' => $displayName, 'reason' => 'Symlinks are not allowed.'];
                    continue;
                }

                $actualSize = (int) (@filesize($destPath) ?: 0);
                $extractedBytes += $actualSize;
                if ($extractedBytes > self::MAX_EXTRACTED_BYTES) {
                    @unlink($destPath);
                    $zip->close();

                    return [
                        'ok' => false,
                        'message' => 'Extracted data exceeds ' . self::formatBytes(self::MAX_EXTRACTED_BYTES) . '.',
                    ];
                }

                $validate = self::validateRasterAtPath($destPath);
                if (empty($validate['ok'])) {
                    @unlink($destPath);
                    $summary['invalid']++;
                    $errors[] = [
                        'entry' => $displayName,
                        'reason' => (string) ($validate['message'] ?? 'Invalid image.'),
                    ];
                    continue;
                }

                $ingestFile = [
                    'name' => $displayName,
                    'tmp_name' => $destPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $actualSize,
                    'trusted_internal' => true,
                ];
                $ingest = MediaLibrary::ingestUpload($ingestFile, $adminUserId);
                @unlink($destPath);

                if (!empty($ingest['ok']) && !empty($ingest['media_id'])) {
                    if (!empty($ingest['duplicate'])) {
                        $summary['duplicates']++;
                    } else {
                        $summary['uploaded']++;
                    }
                    continue;
                }

                $summary['failed']++;
                $errors[] = [
                    'entry' => $displayName,
                    'reason' => (string) ($ingest['message'] ?? 'Ingest failed.'),
                ];
            }

            $zip->close();

            $out = [
                'ok' => true,
                'summary' => $summary,
                'errors' => $errors,
            ];
            if ($errors !== []) {
                $csv = self::buildErrorCsv($errors);
                $out['error_report_csv_base64'] = base64_encode($csv);
                $out['error_report_filename'] = 'media-zip-errors-' . date('Ymd-His') . '.csv';
            }

            return $out;
        } finally {
            self::removeDirRecursive($workRoot);
        }
    }

    public static function maxZipUploadBytes(): int
    {
        $cfg = App::config()['upload']['media_zip_max_bytes'] ?? null;
        if (is_int($cfg) && $cfg > 0) {
            return min($cfg, self::MAX_ZIP_BYTES * 2);
        }
        if (is_numeric($cfg) && (int) $cfg > 0) {
            return min((int) $cfg, self::MAX_ZIP_BYTES * 2);
        }

        return self::MAX_ZIP_BYTES;
    }

    private static function isZipMime(string $mime): bool
    {
        return in_array(strtolower($mime), [
            'application/zip',
            'application/x-zip-compressed',
            'multipart/x-zip',
            'application/octet-stream',
        ], true);
    }

    /** @return list<array{name: string, size: int}> */
    private static function enumerateFileEntries(ZipArchive $zip): array
    {
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (self::isMacOsMetadataPath($name)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'size' => (int) ($stat['size'] ?? 0),
            ];
        }

        return $out;
    }

    private static function isMacOsMetadataPath(string $name): bool
    {
        $n = str_replace('\\', '/', $name);

        return str_starts_with($n, '__MACOSX/') || str_contains($n, '/._');
    }

    private static function isSafeZipEntryPath(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:/#', $name) === 1) {
            return false;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isNestedZipEntry(string $name): bool
    {
        return preg_match('/\.zip$/i', basename(str_replace('\\', '/', $name))) === 1;
    }

    private static function displayNameFromEntry(string $entryName): string
    {
        $base = basename(str_replace('\\', '/', $entryName));
        $base = preg_replace('/[^a-zA-Z0-9._ -]+/', '-', $base) ?? 'file';

        return trim($base, '.-') !== '' ? trim($base, '.-') : 'file';
    }

    private static function extractEntryToFile(ZipArchive $zip, string $entryName, string $destPath): bool
    {
        $stream = $zip->getStream($entryName);
        if ($stream === false) {
            return false;
        }
        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            fclose($stream);

            return false;
        }
        $copied = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        return $copied !== false && is_file($destPath);
    }

    /** @return array{ok: bool, message?: string} */
    private static function validateRasterAtPath(string $path): array
    {
        $base = Upload::validateProductRasterPath($path);
        if (empty($base['ok'])) {
            return $base;
        }
        $mime = (string) ($base['mime'] ?? '');
        $rejectSniff = self::rejectDangerousContent($path, $mime);
        if ($rejectSniff !== null) {
            return ['ok' => false, 'message' => $rejectSniff];
        }

        return ['ok' => true];
    }

    private static function rejectDangerousContent(string $path, string $mime): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 512);
        if (!is_string($head)) {
            return null;
        }
        $lower = strtolower($head);
        if (str_contains($lower, '<?php') || str_contains($lower, '<svg') || str_contains($lower, '<html')) {
            return 'Rejected non-raster or script content.';
        }
        if ($mime === 'image/jpeg' && !str_starts_with($head, "\xFF\xD8")) {
            return 'Invalid JPEG content.';
        }
        if ($mime === 'image/png' && !str_starts_with($head, "\x89PNG")) {
            return 'Invalid PNG content.';
        }
        if ($mime === 'image/webp' && !str_contains($head, 'WEBP')) {
            return 'Invalid WebP content.';
        }

        return null;
    }

    /** @param list<array{entry: string, reason: string}> $errors */
    private static function buildErrorCsv(array $errors): string
    {
        $lines = ["entry,reason"];
        foreach ($errors as $row) {
            $lines[] = self::csvField((string) ($row['entry'] ?? ''))
                . ','
                . self::csvField((string) ($row['reason'] ?? ''));
        }

        return implode("\n", $lines) . "\n";
    }

    private static function csvField(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (str_contains($value, ',') || str_contains($value, '"')) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    private static function createWorkRoot(): ?string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\');
        $dir = $base . DIRECTORY_SEPARATOR . 'at-ml-zip-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        return $dir;
    }

    private static function removeDirRecursive(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $real = realpath($dir);
        if ($real === false) {
            return;
        }
        $tmpRoot = realpath(sys_get_temp_dir());
        if ($tmpRoot === false || !str_starts_with($real, $tmpRoot)) {
            return;
        }
        foreach (scandir($real) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $real . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($real);
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ZIP exceeds server upload limit.',
            UPLOAD_ERR_PARTIAL => 'ZIP upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No ZIP file selected.',
            default => 'ZIP upload failed.',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return (int) round($bytes / 1048576) . ' MB';
        }

        return (int) round($bytes / 1024) . ' KB';
    }
}
