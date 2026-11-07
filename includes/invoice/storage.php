<?php

declare(strict_types=1);

/**
 * Private invoice PDF storage. Files are never addressed by request input: callers pass an invoice row
 * loaded from the database, the stored filename is validated against a strict pattern, resolved with
 * realpath() inside the storage root, and its SHA-256 is verified before streaming.
 *
 * PDFs are encrypted at rest.
 * Default root: <app>/storage/invoices (Apache "Require all denied" via .htaccess).
 * Production: set config `storage.invoices_path` to a directory OUTSIDE the web root.
 */
final class InvoiceStorage
{
    private const FILE_PATTERN = '/^[a-f0-9]{40}\.pdf$/';
    private const ADMIN_ASSET_PDF_PATTERN = '/^[a-f0-9]{24}\.pdf$/';

    public static function baseDir(): string
    {
        InvoiceProductionSecurity::ensureProductionStorageConfigured();

        $dir = InvoiceProductionSecurity::effectiveInvoicesPath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        self::protect($dir);

        return $dir;
    }

    public static function assetDir(): string
    {
        $dir = self::baseDir() . '/assets';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private static function protect(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Write PDF bytes to a random, non-guessable filename. Returns relative file name + hash.
     *
     * @return array{ok: bool, file?: string, hash?: string, size?: int, error?: string}
     */
    public static function savePdf(string $bytes): array
    {
        if (strncmp($bytes, '%PDF-', 5) !== 0 || strlen($bytes) < 200 || strpos(substr($bytes, -64), '%%EOF') === false) {
            return ['ok' => false, 'error' => 'invalid_pdf'];
        }
        $dir = self::baseDir();
        $name = bin2hex(random_bytes(20)) . '.pdf';
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        // Encrypted at rest (sodium secretbox via SecretStore): even if a host ignores .htaccess and serves the
        // file directly, only ciphertext is exposed. The stored hash is of the plaintext PDF.
        try {
            $blob = SecretStore::encrypt($bytes);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'encrypt_failed'];
        }
        if (@file_put_contents($tmp, $blob, LOCK_EX) === false) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'write_failed'];
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'write_failed'];
        }

        return ['ok' => true, 'file' => $name, 'hash' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    public static function delete(string $file): void
    {
        $path = self::resolve($file);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Absolute path for a stored filename, or null when invalid / outside root / missing. */
    public static function resolve(string $file): ?string
    {
        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            return null;
        }
        $root = realpath(self::baseDir());
        if ($root === false) {
            return null;
        }
        $real = realpath($root . '/' . $file);
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Read and integrity-check a stored PDF.
     *
     * @param array<string, mixed> $invoice invoices row
     * @return array{ok: bool, bytes?: string, error?: string}
     */
    public static function read(array $invoice): array
    {
        $path = self::resolve((string) ($invoice['pdf_path'] ?? ''));
        if ($path === null) {
            return ['ok' => false, 'error' => 'missing'];
        }
        $bytes = @file_get_contents($path);
        if ($bytes !== false && strncmp($bytes, '%PDF-', 5) !== 0) {
            try {
                $bytes = SecretStore::decrypt(trim($bytes));
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'integrity'];
            }
        }
        if ($bytes === false || !hash_equals((string) ($invoice['pdf_hash'] ?? ''), hash('sha256', $bytes))) {
            return ['ok' => false, 'error' => 'integrity'];
        }

        return ['ok' => true, 'bytes' => $bytes];
    }

    /**
     * Stream an invoice PDF to the client (caller has already authorised access).
     *
     * @param array<string, mixed> $invoice
     */
    public static function stream(array $invoice, bool $inline, ?string $bytesOverride = null, ?string $fileLabel = null): void
    {
        if ($bytesOverride !== null) {
            $bytes = $bytesOverride;
        } else {
            $read = self::read($invoice);
            if (!$read['ok']) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Document unavailable.';
                exit;
            }
            $bytes = (string) $read['bytes'];
        }
        $label = $fileLabel ?? preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) ($invoice['invoice_number'] ?? 'invoice')) ?? 'invoice';
        self::streamPdfBytes($bytes, $label, $inline);
    }

    /** Absolute path for a stored admin asset PDF filename, or null when invalid / outside root / missing. */
    public static function resolveAdminAssetPdf(string $file): ?string
    {
        if (preg_match(self::ADMIN_ASSET_PDF_PATTERN, $file) !== 1) {
            return null;
        }
        $root = realpath(self::assetDir());
        if ($root === false) {
            return null;
        }
        $real = realpath($root . '/' . $file);
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            return null;
        }

        return $real;
    }

    /**
     * @return array{ok: bool, bytes?: string, error?: string}
     */
    public static function readAdminAssetPdf(string $storedFile): array
    {
        $path = self::resolveAdminAssetPdf($storedFile);
        if ($path === null) {
            return ['ok' => false, 'error' => 'missing'];
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || strncmp($bytes, '%PDF-', 5) !== 0) {
            return ['ok' => false, 'error' => 'invalid_pdf'];
        }

        return ['ok' => true, 'bytes' => $bytes];
    }

    public static function streamPdfBytes(string $bytes, string $safeLabel, bool $inline): void
    {
        $label = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $safeLabel) ?? 'document';
        if ($label === '') {
            $label = 'document';
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $label . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        echo $bytes;
        exit;
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{ok: bool, file?: string, message?: string}
     */
    public static function storeLogoUpload(array $file): array
    {
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'message' => 'Logo upload failed.'];
        }

        return self::storeLogoFile((string) $file['tmp_name'], (int) ($file['size'] ?? 0));
    }

    /** @return array{ok: bool, file?: string, message?: string} */
    public static function storeLogoFile(string $tmpPath, int $size): array
    {
        if ($size <= 0 || $size > 1024 * 1024) {
            return ['ok' => false, 'message' => 'Logo must be 1 MB or smaller.'];
        }
        $info = @getimagesize($tmpPath);
        if ($info === false || !in_array((int) $info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return ['ok' => false, 'message' => 'Logo must be a PNG or JPEG image.'];
        }
        if (!PdfImage::supports($tmpPath)) {
            return ['ok' => false, 'message' => 'Unsupported PNG/JPEG variant. Use an 8-bit RGB/greyscale PNG without transparency, or a baseline JPEG.'];
        }
        $ext = (int) $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = self::assetDir() . '/' . $name;
        if (!@copy($tmpPath, $dest)) {
            return ['ok' => false, 'message' => 'Could not store logo.'];
        }
        @chmod($dest, 0640);

        return ['ok' => true, 'file' => $name];
    }

    /**
     * Admin-only PDF asset (e.g. Udyam certificate). Stored under private invoice assets — not web-served.
     *
     * @param array<string, mixed> $file $_FILES entry
     * @return array{ok: bool, file?: string, message?: string}
     */
    public static function storeUdyamCertificateUpload(array $file): array
    {
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['ok' => false, 'message' => 'Certificate upload failed.'];
        }
        $tmp = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'Certificate PDF must be 2 MB or smaller.'];
        }
        $head = @file_get_contents($tmp, false, null, 0, 8);
        if ($head === false || strncmp($head, '%PDF-', 5) !== 0) {
            return ['ok' => false, 'message' => 'Certificate must be a PDF file.'];
        }
        $name = bin2hex(random_bytes(12)) . '.pdf';
        $dest = self::assetDir() . '/' . $name;
        if (!@move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'message' => 'Could not store certificate.'];
        }
        @chmod($dest, 0640);

        return ['ok' => true, 'file' => $name];
    }
}
