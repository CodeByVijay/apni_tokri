<?php

declare(strict_types=1);

/**
 * Minimal XLSX read/write (Office Open XML) without external dependencies.
 */
final class SimpleXlsx
{
    /** @var list<string> */
    private static array $colLetters = [];

    /**
     * @param array<string, list<list<string|int|float|null>>> $sheets sheet name => rows (each row = list of cell values)
     */
    public static function write(array $sheets, string $destPath): bool
    {
        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        $tmp = $destPath . '.part.' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $shared = [];
        $sharedIndex = [];
        $sheetIndex = 0;
        $sheetEntries = '';
        $contentOverrides = '';
        $workbookSheets = '';
        foreach ($sheets as $name => $rows) {
            $sheetIndex++;
            $sheetFile = 'xl/worksheets/sheet' . $sheetIndex . '.xml';
            $xml = self::sheetXml($rows, $shared, $sharedIndex);
            $zip->addFromString($sheetFile, $xml);
            $rid = 'rId' . ($sheetIndex + 1);
            $sheetEntries .= '<sheet name="' . self::xmlEsc($name) . '" sheetId="' . $sheetIndex . '" r:id="' . $rid . '"/>';
            $contentOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetIndex . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
        }
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $contentOverrides
            . '</Types>'
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetEntries . '</sheets></workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . $workbookSheets
            . '</Relationships>'
        );
        $zip->addFromString('xl/styles.xml', self::minimalStyles());
        $zip->addFromString('xl/sharedStrings.xml', self::sharedStringsXml($shared));
        $zip->close();
        if (is_file($destPath)) {
            @unlink($destPath);
        }

        return @rename($tmp, $destPath);
    }

    /**
     * @return array<string, list<list<string>>>
     */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Workbook file not readable.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open workbook as XLSX.');
        }
        $shared = self::readSharedStrings($zip);
        $sheetMap = self::readSheetMap($zip);
        $out = [];
        foreach ($sheetMap as $name => $sheetPath) {
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                continue;
            }
            $out[$name] = self::parseSheetRows($xml, $shared);
        }
        $zip->close();

        return $out;
    }

    /**
     * @param list<list<string|int|float|null>> $rows
     * @param list<string> $shared
     * @param array<string, int> $sharedIndex
     */
    private static function sheetXml(array $rows, array &$shared, array &$sharedIndex): string
    {
        $body = '<sheetData>';
        foreach ($rows as $rIdx => $row) {
            $rNum = $rIdx + 1;
            $body .= '<row r="' . $rNum . '">';
            foreach ($row as $cIdx => $val) {
                $ref = self::colLetter($cIdx) . $rNum;
                if ($val === null || $val === '') {
                    continue;
                }
                if (is_int($val) || is_float($val)) {
                    $body .= '<c r="' . $ref . '"><v>' . (string) $val . '</v></c>';
                } else {
                    $s = self::sanitizeCellString((string) $val);
                    $si = self::sharedIdx($s, $shared, $sharedIndex);
                    $body .= '<c r="' . $ref . '" t="s"><v>' . $si . '</v></c>';
                }
            }
            $body .= '</row>';
        }
        $body .= '</sheetData>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $body . '</worksheet>';
    }

    /** @param list<string> $shared */
    private static function sharedStringsXml(array $shared): string
    {
        $items = '';
        foreach ($shared as $s) {
            $items .= '<si><t>' . self::xmlEsc($s) . '</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($shared) . '" uniqueCount="' . count($shared) . '">' . $items . '</sst>';
    }

    private static function minimalStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    /** @param list<string> $shared @param array<string, int> $sharedIndex */
    private static function sharedIdx(string $s, array &$shared, array &$sharedIndex): int
    {
        if (isset($sharedIndex[$s])) {
            return $sharedIndex[$s];
        }
        $sharedIndex[$s] = count($shared);
        $shared[] = $s;

        return $sharedIndex[$s];
    }

    private static function colLetter(int $index): string
    {
        $n = $index + 1;
        $s = '';
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $s = chr(65 + $rem) . $s;
            $n = (int) (($n - $rem) / 26);
        }

        return $s;
    }

    /**
     * Mitigate spreadsheet formula injection when cells are opened in Excel.
     * Prefixes dangerous leading characters so the value is treated as text.
     */
    public static function sanitizeCellString(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private static function xmlEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return list<string> */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $out[] = (string) $si->t;
            } elseif (isset($si->r)) {
                $parts = '';
                foreach ($si->r as $r) {
                    $parts .= (string) ($r->t ?? '');
                }
                $out[] = $parts;
            } else {
                $out[] = '';
            }
        }

        return $out;
    }

    /** @return array<string, string> sheet name => zip path */
    private static function readSheetMap(ZipArchive $zip): array
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return [];
        }
        $relMap = [];
        $rDoc = @simplexml_load_string($rels);
        if ($rDoc !== false) {
            foreach ($rDoc->Relationship as $rel) {
                $attrs = $rel->attributes();
                $relMap[(string) ($attrs['Id'] ?? '')] = (string) ($attrs['Target'] ?? '');
            }
        }
        $map = [];
        $wDoc = @simplexml_load_string($wb);
        if ($wDoc === false || !isset($wDoc->sheets)) {
            return [];
        }
        foreach ($wDoc->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string) ($attrs['id'] ?? $sheet->attributes()['r:id'] ?? '');
            $name = (string) ($sheet->attributes()['name'] ?? '');
            if ($name === '' || $rid === '' || !isset($relMap[$rid])) {
                continue;
            }
            $target = $relMap[$rid];
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . ltrim($target, '/');
            }
            $map[$name] = $target;
        }

        return $map;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function parseSheetRows(string $xml, array $shared): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false || !isset($doc->sheetData)) {
            return [];
        }
        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            $maxCol = 0;
            foreach ($row->c as $c) {
                $ref = (string) ($c->attributes()['r'] ?? '');
                $col = self::colIndexFromRef($ref);
                $maxCol = max($maxCol, $col);
                $type = (string) ($c->attributes()['t'] ?? '');
                $v = isset($c->v) ? (string) $c->v : '';
                if ($type === 's' && $v !== '' && isset($shared[(int) $v])) {
                    $cells[$col] = $shared[(int) $v];
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $cells[$col] = (string) $c->is->t;
                } else {
                    $cells[$col] = $v;
                }
            }
            if ($maxCol === 0 && $cells === []) {
                $rows[] = [];

                continue;
            }
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private static function colIndexFromRef(string $ref): int
    {
        if ($ref === '') {
            return 0;
        }
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $letters = $m[1];
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<array<string, string>>
     */
    public static function rowsToAssoc(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $rows[0]);
        $out = [];
        foreach (array_slice($rows, 1) as $rowNum => $row) {
            if (self::rowIsEmpty($row)) {
                continue;
            }
            $assoc = ['__row' => (string) ($rowNum + 2)];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /** @param list<string> $row */
    private static function rowIsEmpty(array $row): bool
    {
        foreach ($row as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }
}
