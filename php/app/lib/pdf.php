<?php
/**
 * A small PDF writer for branded letters: A4 pages, the standard Helvetica and Times fonts (WinAnsi encoding),
 * wrapped text, lines, filled boxes, PNG/JPEG images, a rotated translucent watermark, page header/footer callbacks
 * and "Page N of M". No external library is needed, so it runs on any PHP host.
 */
declare(strict_types=1);

class Pdf
{
    public float $w = 210.0;
    public float $h = 297.0;
    public float $k;               // points per mm
    public float $lMargin = 20;
    public float $tMargin = 18;
    public float $rMargin = 20;
    public float $bMargin = 28;
    public float $x = 20;
    public float $y = 18;
    public string $font = 'helvetica';
    public float $size = 10;
    public array $textColor = [0, 0, 0];
    public array $drawColor = [0, 0, 0];
    public array $fillColor = [255, 255, 255];
    public float $lineWidth = 0.2;
    public $headerFn = null;
    public $footerFn = null;
    private array $pages = [];
    private int $page = 0;
    private array $images = [];
    private bool $inHeaderFooter = false;
    private static ?array $metrics = null;
    private const FONTS = ['helvetica' => 'Helvetica', 'helveticaB' => 'Helvetica-Bold', 'helveticaI' => 'Helvetica-Oblique',
        'times' => 'Times-Roman', 'timesB' => 'Times-Bold'];

    public function __construct()
    {
        $this->k = 72 / 25.4;
        self::$metrics ??= require __DIR__ . '/fontmetrics.php';
    }

    // ------------------------------------------------------------ text encoding and measuring
    public static function enc(string $text): string
    {
        $map = ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-',
            "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{2022}" => '-'];
        $text = strtr($text, $map);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        return $out === false ? preg_replace('/[^\x20-\x7E\n]/', '?', $text) : $out;
    }

    public function width(string $encoded): float
    {
        $cw = self::$metrics[$this->font];
        $w = 0;
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $w += $cw[ord($encoded[$i])];
        }
        return $w * $this->size / 1000 / $this->k;
    }

    // ------------------------------------------------------------ state
    public function setFont(string $family, string $style, float $size): void
    {
        $this->font = $family . ($style === 'B' ? 'B' : ($style === 'I' ? 'I' : ''));
        $this->size = $size;
    }

    public function textColor(int $r, int $g, int $b): void
    {
        $this->textColor = [$r, $g, $b];
    }

    public function drawColor(int $r, int $g, int $b): void
    {
        $this->drawColor = [$r, $g, $b];
    }

    public function fillColor(int $r, int $g, int $b): void
    {
        $this->fillColor = [$r, $g, $b];
    }

    private function rgb(array $c, string $op): string
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0] / 255, $c[1] / 255, $c[2] / 255, $op);
    }

    private function out(string $s): void
    {
        $this->pages[$this->page] .= $s . "\n";
    }

    public function pageNo(): int
    {
        return $this->page;
    }

    public function addPage(): void
    {
        // The header and footer change fonts and colours; the text that continues on the new page keeps its own.
        $state = [$this->font, $this->size, $this->textColor, $this->drawColor, $this->fillColor, $this->lineWidth];
        if ($this->page > 0 && $this->footerFn) {
            $this->inHeaderFooter = true;
            ($this->footerFn)($this);
            $this->inHeaderFooter = false;
        }
        $this->page++;
        $this->pages[$this->page] = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        if ($this->headerFn) {
            $this->inHeaderFooter = true;
            ($this->headerFn)($this);
            $this->inHeaderFooter = false;
        }
        [$this->font, $this->size, $this->textColor, $this->drawColor, $this->fillColor, $this->lineWidth] = $state;
    }

    private function needSpace(float $h): void
    {
        if (!$this->inHeaderFooter && $this->y + $h > $this->h - $this->bMargin) {
            $x = $this->x;
            $this->addPage();
            $this->x = $x;
        }
    }

    // ------------------------------------------------------------ drawing
    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->out(sprintf('%.2F w %s %.2F %.2F m %.2F %.2F l S', $this->lineWidth * $this->k, $this->rgb($this->drawColor, 'RG'),
            $x1 * $this->k, ($this->h - $y1) * $this->k, $x2 * $this->k, ($this->h - $y2) * $this->k));
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = true): void
    {
        $this->out(sprintf('%s %.2F %.2F %.2F %.2F re %s', $this->rgb($this->fillColor, 'rg'), $x * $this->k, ($this->h - $y) * $this->k,
            $w * $this->k, -$h * $this->k, $fill ? 'f' : 'S'));
    }

    /** Text with its baseline at (x, y). */
    public function text(float $x, float $y, string $text): void
    {
        $enc = self::enc($text);
        $this->out(sprintf('BT %s /F%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->rgb($this->textColor, 'rg'), $this->font, $this->size,
            $x * $this->k, ($this->h - $y) * $this->k, $this->escape($enc)));
    }

    private function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    /** One line of text in a box of width $w (0 = to the right margin); align L or R. Moves right. */
    public function cell(float $w, float $h, string $text, string $align = 'L', bool $fill = false): void
    {
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $this->needSpace($h);
        if ($fill) {
            $this->rect($this->x, $this->y, $w, $h);
        }
        $enc = self::enc($text);
        while ($enc !== '' && $this->width($enc) > $w - 2) {
            $enc = substr($enc, 0, -1);
        }
        if ($enc !== '') {
            $dx = $align === 'R' ? $w - 1 - $this->width($enc) : 1;
            $this->out(sprintf('BT %s /F%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->rgb($this->textColor, 'rg'), $this->font, $this->size,
                ($this->x + $dx) * $this->k, ($this->h - ($this->y + 0.5 * $h + 0.3 * $this->size / $this->k)) * $this->k, $this->escape($enc)));
        }
        $this->x += $w;
    }

    public function ln(float $h): void
    {
        $this->x = $this->lMargin;
        $this->y += $h;
    }

    /** Wrapped text block, then back to the left margin below it. */
    public function multiCell(float $w, float $h, string $text, string $align = 'L', bool $fill = false, bool $leftBorder = false, float $pad = 0): void
    {
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $x0 = $this->x;
        $inner = $w - 2 * $pad - 2;
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $para) {
            $words = preg_split('/ +/', self::enc($para));
            $line = '';
            foreach ($words as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->width($try) <= $inner) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                while ($this->width($word) > $inner) {  // a single word longer than the line
                    $cut = strlen($word);
                    while ($cut > 1 && $this->width(substr($word, 0, $cut)) > $inner) {
                        $cut--;
                    }
                    $lines[] = substr($word, 0, $cut);
                    $word = substr($word, $cut);
                }
                $line = $word;
            }
            $lines[] = $line;
        }
        if ($fill || $leftBorder) {
            $total = count($lines) * $h + 2 * $pad;
            $this->needSpace(min($total, 60));
            $this->rect($x0, $this->y, $w, $total, true);
            if ($leftBorder) {
                $lw = $this->lineWidth;
                $this->lineWidth = 0.8;
                $this->line($x0, $this->y, $x0, $this->y + $total);
                $this->lineWidth = $lw;
            }
            $this->y += $pad;
        }
        foreach ($lines as $l) {
            $this->needSpace($h);
            if ($l !== '') {
                $dx = $align === 'R' ? $w - 1 - $pad - $this->width($l) : 1 + $pad;
                $this->out(sprintf('BT %s /F%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->rgb($this->textColor, 'rg'), $this->font, $this->size,
                    ($x0 + $dx) * $this->k, ($this->h - ($this->y + 0.5 * $h + 0.3 * $this->size / $this->k)) * $this->k, $this->escape($l)));
            }
            $this->y += $h;
        }
        if ($fill || $leftBorder) {
            $this->y += $pad;
        }
        $this->x = $this->lMargin;
    }

    /** Big translucent rotated text across the page (e.g. DRAFT). */
    public function watermark(string $text, float $size): void
    {
        $enc = self::enc($text);
        $a = deg2rad(35);
        [$cx, $cy] = [25 * $this->k, ($this->h - 170) * $this->k];
        $this->out(sprintf('q /GS1 gs 0.784 0.235 0.157 rg BT /FhelveticaB %.2F Tf %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET Q',
            $size, cos($a), sin($a), -sin($a), cos($a), $cx, $cy, $this->escape($enc)));
    }

    /** Place a PNG or JPEG image (from bytes) at x, y with height h (width keeps the aspect ratio). */
    public function image(string $bytes, float $x, float $y, float $h): void
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return;
        }
        $wpx = imagesx($im);
        $hpx = imagesy($im);
        // Flatten onto white and embed as JPEG: simple and supported by every PDF reader.
        $canvas = imagecreatetruecolor($wpx, $hpx);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $im, 0, 0, 0, 0, $wpx, $hpx);
        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();
        $n = count($this->images) + 1;
        $this->images[$n] = ['data' => $jpeg, 'w' => $wpx, 'h' => $hpx];
        $w = $h * $wpx / $hpx;
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - $y - $h) * $this->k, $n));
    }

    // ------------------------------------------------------------ output
    public function output(): string
    {
        if ($this->footerFn && $this->page > 0) {
            $this->inHeaderFooter = true;
            ($this->footerFn)($this);
            $this->inHeaderFooter = false;
        }
        $objects = [];
        $add = function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };
        $catalogId = $add('');
        $pagesId = $add('');
        $fontIds = [];
        foreach (self::FONTS as $key => $base) {
            $fontIds[$key] = $add("<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>");
        }
        $gsId = $add('<< /Type /ExtGState /ca 0.10 /CA 0.10 >>');
        $imgIds = [];
        foreach ($this->images as $n => $img) {
            $imgIds[$n] = $add("<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /DeviceRGB "
                . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream");
        }
        $fonts = implode(' ', array_map(fn($k, $id) => "/F$k $id 0 R", array_keys($fontIds), $fontIds));
        $xobjects = $imgIds ? '/XObject << ' . implode(' ', array_map(fn($n, $id) => "/I$n $id 0 R", array_keys($imgIds), $imgIds)) . ' >>' : '';
        $resources = "<< /Font << $fonts >> /ExtGState << /GS1 $gsId 0 R >> $xobjects >>";
        $kids = [];
        $total = (string) $this->page;
        foreach ($this->pages as $content) {
            $content = str_replace('{nb}', $total, $content);
            $data = gzcompress($content);
            $cid = $add('<< /Filter /FlateDecode /Length ' . strlen($data) . " >>\nstream\n$data\nendstream");
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
                $pagesId, $this->w * $this->k, $this->h * $this->k, $resources, $cid));
        }
        $objects[$catalogId - 1] = "<< /Type /Catalog /Pages $pagesId 0 R >>";
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($i) => "$i 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root $catalogId 0 R /Info << /Producer (LMD platform) >> >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
