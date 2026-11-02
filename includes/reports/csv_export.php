<?php

declare(strict_types=1);

final class ReportCsvExport
{
    /** Prevent CSV formula injection in Excel/Sheets. */
    public static function sanitizeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'" . $value;
        }

        return $value;
    }

    public static function safeFilename(string $base): string
    {
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base) ?? 'export';
        $base = trim($base, '-.');
        if ($base === '') {
            $base = 'export';
        }

        return substr($base, 0, 120);
    }

    /**
     * @param list<string> $header
     * @param iterable<int, list<string|int|float|null>> $rows
     */
    public static function streamDownload(string $filename, array $header, iterable $rows): void
    {
        $filename = self::safeFilename($filename);
        if (substr($filename, -4) !== '.csv') {
            $filename .= '.csv';
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static function ($c) {
            return self::sanitizeCell((string) $c);
        }, $header));
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                $line[] = self::sanitizeCell($cell === null ? '' : (string) $cell);
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }
}
