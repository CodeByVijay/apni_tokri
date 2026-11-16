<?php

declare(strict_types=1);

/**
 * Production-only storage and encryption path rules for invoices.
 * Local/staging may use default paths under the project; production must fail closed.
 */
final class InvoiceProductionSecurity
{
    public static function isProduction(): bool
    {
        $env = strtolower(trim(App::env()));

        return $env === 'production' || $env === 'prod';
    }

    /** Absolute web application root (typically the directory containing index.php). */
    public static function documentRoot(): string
    {
        $real = realpath(APNI_TOKRI_ROOT);

        return $real !== false ? $real : rtrim(APNI_TOKRI_ROOT, '/\\');
    }

    public static function configuredMasterKeyPath(): string
    {
        return trim((string) (App::config()['encryption']['master_key_path'] ?? ''));
    }

    public static function configuredInvoicesPath(): string
    {
        return trim((string) (App::config()['storage']['invoices_path'] ?? ''));
    }

    /** Path used for encryption (explicit config or dev default). */
    public static function effectiveMasterKeyPath(): string
    {
        $p = self::configuredMasterKeyPath();

        return $p !== '' ? $p : APNI_TOKRI_ROOT . '/storage/secrets/master.key';
    }

    /** Directory used for invoice PDF storage (explicit config or dev default). */
    public static function effectiveInvoicesPath(): string
    {
        $p = self::configuredInvoicesPath();

        return $p !== '' ? rtrim($p, '/\\') : APNI_TOKRI_ROOT . '/storage/invoices';
    }

    /**
     * Whether an absolute (or project-relative) path lies inside the web document root.
     * Used to reject production storage/key locations under a public docroot.
     */
    public static function isPathWithinDocumentRoot(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $normalized = self::normalizeAbsolutePath($path);
        $doc = self::documentRoot();
        if ($normalized === $doc) {
            return true;
        }

        return strncmp($normalized, $doc . DIRECTORY_SEPARATOR, strlen($doc) + 1) === 0;
    }

    /**
     * Validate production configuration before writing or reading encrypted invoice PDFs.
     *
     * @throws RuntimeException with a safe, non-secret message
     */
    public static function ensureProductionStorageConfigured(): void
    {
        if (!self::isProduction()) {
            return;
        }

        $keyPath = self::configuredMasterKeyPath();
        if ($keyPath === '') {
            throw new RuntimeException(
                'Invoice encryption is not configured: set encryption.master_key_path to a file outside the web document root.'
            );
        }
        if (self::isPathWithinDocumentRoot($keyPath)) {
            throw new RuntimeException(
                'Invoice encryption master key must not be stored inside the web document root. Move encryption.master_key_path outside the public site directory.'
            );
        }
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            throw new RuntimeException(
                'Invoice encryption master key file is missing or not readable. Restore the existing key file; do not replace it with a newly generated key.'
            );
        }

        $invoicePath = self::configuredInvoicesPath();
        if ($invoicePath === '') {
            throw new RuntimeException(
                'Invoice PDF storage is not configured: set storage.invoices_path to a directory outside the web document root.'
            );
        }
        if (self::isPathWithinDocumentRoot($invoicePath)) {
            throw new RuntimeException(
                'Invoice PDF storage must not be inside the web document root. Set storage.invoices_path outside the public site directory.'
            );
        }
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if ($path[0] !== DIRECTORY_SEPARATOR && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = APNI_TOKRI_ROOT . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
