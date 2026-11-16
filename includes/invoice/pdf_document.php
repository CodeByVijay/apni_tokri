<?php

declare(strict_types=1);

/**
 * Raster image prepared for embedding in a PDF (JPEG via DCTDecode; non-interlaced 8-bit PNG without alpha
 * via FlateDecode + PNG predictors). No GD required.
 */
final class PdfImage
{
    /** @var int */
    public $width = 0;
    /** @var int */
    public $height = 0;
    /** @var string PDF dictionary entries (without << >>) */
    public $dict = '';
    /** @var string raw stream bytes */
    public $data = '';

    public static function supports(string $path): bool
    {
        return self::load($path) !== null;
    }

    public static function load(string $path): ?PdfImage
    {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 24) {
            return null;
        }
        if (strncmp($raw, "\xFF\xD8", 2) === 0) {
            $info = @getimagesizefromstring($raw);
            if ($info === false || (int) $info[2] !== IMAGETYPE_JPEG) {
                return null;
            }
            $channels = (int) ($info['channels'] ?? 3);
            if (!in_array($channels, [1, 3], true) || (int) ($info['bits'] ?? 8) !== 8) {
                return null;
            }
            $img = new PdfImage();
            $img->width = (int) $info[0];
            $img->height = (int) $info[1];
            $img->dict = '/Type /XObject /Subtype /Image /Width ' . $img->width . ' /Height ' . $img->height
                . ' /ColorSpace ' . ($channels === 1 ? '/DeviceGray' : '/DeviceRGB')
                . ' /BitsPerComponent 8 /Filter /DCTDecode';
            $img->data = $raw;

            return $img;
        }
        if (strncmp($raw, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }
        $pos = 8;
        $len = strlen($raw);
        $ihdr = null;
        $plte = '';
        $idat = '';
        while ($pos + 8 <= $len) {
            $chunkLen = unpack('N', substr($raw, $pos, 4))[1];
            $type = substr($raw, $pos + 4, 4);
            $body = substr($raw, $pos + 8, $chunkLen);
            if ($type === 'IHDR') {
                $ihdr = unpack('Nw/Nh/Cbit/Cct/Ccomp/Cfilter/Cinter', $body);
            } elseif ($type === 'PLTE') {
                $plte = $body;
            } elseif ($type === 'IDAT') {
                $idat .= $body;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $chunkLen;
        }
        if ($ihdr === null || $idat === '' || (int) $ihdr['bit'] !== 8 || (int) $ihdr['inter'] !== 0
            || !in_array((int) $ihdr['ct'], [0, 2, 3], true)) {
            return null;
        }
        $ct = (int) $ihdr['ct'];
        $colors = $ct === 2 ? 3 : 1;
        $img = new PdfImage();
        $img->width = (int) $ihdr['w'];
        $img->height = (int) $ihdr['h'];
        if ($ct === 3) {
            if ($plte === '' || strlen($plte) % 3 !== 0) {
                return null;
            }
            $cs = '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]';
        } else {
            $cs = $ct === 2 ? '/DeviceRGB' : '/DeviceGray';
        }
        $img->dict = '/Type /XObject /Subtype /Image /Width ' . $img->width . ' /Height ' . $img->height
            . ' /ColorSpace ' . $cs . ' /BitsPerComponent 8 /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent 8 /Columns ' . $img->width . ' >>';
        $img->data = $idat;

        return $img;
    }
}

/**
 * Minimal, dependency-free PDF 1.4 writer: Helvetica / Helvetica-Bold (WinAnsi), vector shapes, images,
 * link annotations, multi-page. Coordinates are TOP-DOWN points (origin top-left).
 *
 * Limitation: only Latin-1/WinAnsi glyphs are available (no embedded Unicode font); unmappable characters
 * (e.g. Devanagari) are rendered as "?". The rupee sign is not in WinAnsi, so amounts use "Rs.".
 */
final class PdfDocument
{
    /** @var array<int, int> */
    private static $wRegular = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333,
        42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278, 58 => 278, 59 => 278, 60 => 584, 61 => 584,
        62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778,
        82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 278,
        92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
        102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
        111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584, 149 => 350, 150 => 556,
    ];

    /** @var array<int, int> */
    private static $wBold = [
        32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238, 40 => 333, 41 => 333,
        42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278, 58 => 333, 59 => 333, 60 => 584, 61 => 584,
        62 => 584, 63 => 611, 64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778,
        82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 333,
        92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556,
        102 => 333, 103 => 611, 104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
        111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
        120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584, 149 => 350, 150 => 556,
    ];

    /** @var float */
    public $width;
    /** @var float */
    public $height;
    /** @var list<string> */
    private $pages = [];
    /** @var list<list<string>> link annotation objects per page */
    private $links = [];
    /** @var array<string, PdfImage> */
    private $images = [];
    /** @var string */
    private $title;
    /** @var string */
    private $createdAt;

    public function __construct(float $width, float $height, string $title = 'Document', ?string $createdAt = null)
    {
        $this->width = $width;
        $this->height = $height;
        $this->title = $title;
        $this->createdAt = $createdAt ?? gmdate('YmdHis');
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->links[] = [];
        $this->cur = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setPage(int $index): void
    {
        $this->cur = $index;
    }

    /** @var int */
    private $cur = -1;

    private function emit(string $ops): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $i = $this->cur >= 0 && $this->cur < count($this->pages) ? $this->cur : count($this->pages) - 1;
        $this->pages[$i] .= $ops . "\n";
    }

    public function currentPageIndex(): int
    {
        return $this->cur >= 0 ? $this->cur : count($this->pages) - 1;
    }

    /** Switch drawing back to the last page. */
    public function useLastPage(): void
    {
        $this->cur = count($this->pages) - 1;
    }

    private static function enc(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t", "\0"], ' ', $s);
        $c = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');

        return is_string($c) ? $c : '?';
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $t = self::enc($s);
        $tbl = $bold ? self::$wBold : self::$wRegular;
        $w = 0;
        $n = strlen($t);
        for ($i = 0; $i < $n; $i++) {
            $w += $tbl[ord($t[$i])] ?? 556;
        }

        return $w * $size / 1000.0;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $rgb 0..1 floats
     */
    public function text(float $x, float $yBaseline, string $s, float $size, bool $bold = false, array $rgb = [0, 0, 0], string $align = 'L'): void
    {
        if ($s === '') {
            return;
        }
        if ($align === 'R') {
            $x -= $this->textWidth($s, $size, $bold);
        } elseif ($align === 'C') {
            $x -= $this->textWidth($s, $size, $bold) / 2;
        }
        $this->emit(sprintf(
            'BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET',
            $bold ? 'F2' : 'F1',
            $size,
            self::rgb($rgb),
            $x,
            $this->height - $yBaseline,
            self::esc(self::enc($s))
        ));
    }

    /** @return list<string> */
    public function wrap(string $s, float $maxWidth, float $size, bool $bold = false): array
    {
        $s = trim(str_replace(["\r\n", "\r"], "\n", $s));
        if ($s === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $s) as $para) {
            $words = preg_split('/\s+/u', trim($para)) ?: [];
            $line = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                // Break over-long tokens (IDs, URLs) by characters.
                while ($this->textWidth($word, $size, $bold) > $maxWidth) {
                    $cut = '';
                    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $rest = $chars;
                    foreach ($chars as $ch) {
                        if ($this->textWidth($cut . $ch, $size, $bold) > $maxWidth && $cut !== '') {
                            break;
                        }
                        $cut .= $ch;
                        array_shift($rest);
                    }
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $out[] = $cut;
                    $word = implode('', $rest);
                    if ($word === '') {
                        break;
                    }
                }
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->textWidth($try, $size, $bold) > $maxWidth) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @param array{0: float, 1: float, 2: float}|null $fill @param array{0: float, 1: float, 2: float}|null $stroke */
    public function rect(float $x, float $yTop, float $w, float $h, ?array $fill, ?array $stroke = null, float $lw = 0.6, float $radius = 0): void
    {
        $y = $this->height - $yTop - $h;
        $ops = '';
        if ($fill !== null) {
            $ops .= self::rgb($fill) . ' rg ';
        }
        if ($stroke !== null) {
            $ops .= self::rgbStroke($stroke) . sprintf(' RG %.2F w ', $lw);
        }
        $ops .= $radius > 0 ? self::roundPath($x, $y, $w, $h, $radius) : sprintf('%.2F %.2F %.2F %.2F re', $x, $y, $w, $h);
        $ops .= $fill !== null && $stroke !== null ? ' B' : ($fill !== null ? ' f' : ' S');
        $this->emit($ops);
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $lw = 0.5, bool $dash = false): void
    {
        $this->emit(sprintf(
            '%s RG %.2F w %s %.2F %.2F m %.2F %.2F l S [] 0 d',
            self::rgbStroke($rgb),
            $lw,
            $dash ? '[2 2] 0 d' : '',
            $x1,
            $this->height - $y1,
            $x2,
            $this->height - $y2
        ));
    }

    public function image(string $key, PdfImage $img, float $x, float $yTop, float $w, float $h): void
    {
        $this->images[$key] = $img;
        $this->emit(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, $this->height - $yTop - $h, $key));
    }

    public function link(float $x, float $yTop, float $w, float $h, string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            return;
        }
        $i = $this->currentPageIndex();
        $this->links[$i][] = sprintf(
            '<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>',
            $x,
            $this->height - $yTop - $h,
            $x + $w,
            $this->height - $yTop,
            self::esc(self::enc($url))
        );
    }

    /** @param array{0: float, 1: float, 2: float} $c */
    private static function rgb(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0], $c[1], $c[2]);
    }

    /** @param array{0: float, 1: float, 2: float} $c */
    private static function rgbStroke(array $c): string
    {
        return self::rgb($c);
    }

    private static function roundPath(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $p = sprintf('%.2F %.2F m ', $x + $r, $y);
        $p .= sprintf('%.2F %.2F l ', $x + $w - $r, $y);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r);
        $p .= sprintf('%.2F %.2F l ', $x + $w, $y + $h - $r);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h);
        $p .= sprintf('%.2F %.2F l ', $x + $r, $y + $h);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r);
        $p .= sprintf('%.2F %.2F l ', $x, $y + $r);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);

        return $p;
    }

    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $imgRefs = [];
        foreach ($this->images as $key => $img) {
            $objs[$next] = ['dict' => $img->dict, 'stream' => $img->data];
            $imgRefs[$key] = $next;
            $next++;
        }
        $xo = '';
        foreach ($imgRefs as $key => $n) {
            $xo .= '/' . $key . ' ' . $n . ' 0 R ';
        }
        $pageRefs = [];
        foreach ($this->pages as $i => $content) {
            $c = gzcompress($content, 6);
            $contentNo = $next++;
            $objs[$contentNo] = ['dict' => '/Filter /FlateDecode', 'stream' => $c === false ? $content : $c];
            if ($c === false) {
                $objs[$contentNo]['dict'] = '';
            }
            $annots = '';
            if ($this->links[$i] !== []) {
                $refs = [];
                foreach ($this->links[$i] as $a) {
                    $an = $next++;
                    $objs[$an] = $a;
                    $refs[] = $an . ' 0 R';
                }
                $annots = ' /Annots [' . implode(' ', $refs) . ']';
            }
            $pageNo = $next++;
            $objs[$pageNo] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R%s >>',
                $this->width,
                $this->height,
                $xo !== '' ? ' /XObject << ' . $xo . '>>' : '',
                $contentNo,
                $annots
            );
            $pageRefs[] = $pageNo . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        $infoNo = $next++;
        $objs[$infoNo] = '<< /Title (' . self::esc(self::enc($this->title)) . ') /Producer (Veggie Tokri Invoice Engine) /CreationDate (D:' . $this->createdAt . ') >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $n => $o) {
            $offsets[$n] = strlen($out);
            if (is_array($o)) {
                $out .= $n . " 0 obj\n<< " . $o['dict'] . ' /Length ' . strlen($o['stream']) . " >>\nstream\n" . $o['stream'] . "\nendstream\nendobj\n";
            } else {
                $out .= $n . " 0 obj\n" . $o . "\nendobj\n";
            }
        }
        $maxObj = max(array_keys($objs));
        $xref = strlen($out);
        $out .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= 'trailer << /Size ' . ($maxObj + 1) . ' /Root 1 0 R /Info ' . $infoNo . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $out;
    }
}
