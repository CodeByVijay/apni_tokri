<?php

declare(strict_types=1);

/** Detect shared-hosting home and safe private paths outside the web document root. */
final class InstallHostingPaths
{
    /** @var string|null */
    public static $testHostingHomeOverride = null;

    public static function documentRoot(): string
    {
        $doc = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($doc !== '') {
            $real = realpath($doc);

            return $real !== false ? $real : rtrim(str_replace('\\', '/', $doc), '/');
        }

        return InvoiceProductionSecurity::documentRoot();
    }

    public static function detectHostingHome(): ?string
    {
        if (self::$testHostingHomeOverride !== null) {
            return self::$testHostingHomeOverride;
        }
        $candidates = [];
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $candidates[] = $home;
        }
        if (!empty($_SERVER['HOME']) && is_string($_SERVER['HOME'])) {
            $candidates[] = $_SERVER['HOME'];
        }
        $doc = self::documentRoot();
        if (preg_match('#^(.+)/public_html(?:/|$)#i', str_replace('\\', '/', $doc), $m)) {
            $candidates[] = $m[1];
        }
        if (preg_match('#^(/home[^/]+/[^/]+)/(?:public_html|www|htdocs)(?:/|$)#i', str_replace('\\', '/', $doc), $m)) {
            $candidates[] = $m[1];
        }
        $parent = dirname($doc);
        if ($parent !== $doc) {
            $candidates[] = $parent;
        }
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real) && is_readable($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @return array{base: string, secrets_dir: string, invoices_dir: string, master_key_path: string}
     */
    public static function defaultPrivateLayout(): array
    {
        $home = self::detectHostingHome();
        $base = $home !== null
            ? rtrim($home, '/\\') . DIRECTORY_SEPARATOR . 'apni-tokri-private'
            : dirname(InvoiceProductionSecurity::documentRoot()) . DIRECTORY_SEPARATOR . 'apni-tokri-private';

        $secrets = $base . DIRECTORY_SEPARATOR . 'secrets';
        $invoices = $base . DIRECTORY_SEPARATOR . 'invoices';
        $key = $secrets . DIRECTORY_SEPARATOR . 'master.key';

        return [
            'base' => $base,
            'secrets_dir' => $secrets,
            'invoices_dir' => $invoices,
            'master_key_path' => $key,
        ];
    }

    public static function isOutsideDocumentRoot(string $absolutePath): bool
    {
        return !InvoiceProductionSecurity::isPathWithinDocumentRoot($absolutePath);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function ensurePrivateDirectory(string $dir, int $mode = 0750): array
    {
        if ($dir === '') {
            return ['ok' => false, 'message' => 'Directory path is empty.'];
        }
        if (!self::isOutsideDocumentRoot($dir)) {
            return ['ok' => false, 'message' => 'Private directory must be outside the web document root: ' . $dir];
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
                return ['ok' => false, 'message' => 'Could not create directory. Create it manually with mode 0750: ' . $dir];
            }
            @chmod($dir, $mode);
        }
        if (!is_dir($dir) || !is_readable($dir)) {
            return ['ok' => false, 'message' => 'Directory is not readable by PHP: ' . $dir];
        }
        $probe = $dir . DIRECTORY_SEPARATOR . '.write_probe_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return ['ok' => false, 'message' => 'PHP cannot write to directory. Set owner/group so the web user can write (avoid 777): ' . $dir];
        }
        @unlink($probe);

        return ['ok' => true];
    }
}
