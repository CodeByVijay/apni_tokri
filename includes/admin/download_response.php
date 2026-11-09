<?php

declare(strict_types=1);

/** Safe attachment streaming for authenticated admin downloads. */
final class AdminFileDownload
{
    public const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const MIME_ZIP = 'application/zip';
    public const MIME_CSV = 'text/csv; charset=UTF-8';

    public static function safeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?? 'download';
        $name = trim($name, '.-');
        if ($name === '') {
            return 'download';
        }

        return $name;
    }

    public static function streamAttachment(string $path, string $mimeType, string $downloadFilename): void
    {
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'File not found.';
            exit;
        }
        $size = filesize($path);
        if ($size === false) {
            error_log('AdminFileDownload: could not stat file.');
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Download failed.';
            exit;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . self::safeFilename($downloadFilename) . '"');
        header('Content-Length: ' . (string) $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $ok = readfile($path);
        if ($ok === false) {
            error_log('AdminFileDownload: readfile failed.');
        }
        exit;
    }
}
